# Design

## Context

Motivação em proposal.md. Estado do código depois de `cron-do-painel-corrigido` (lido em 10/11/2026):

- `KeywordTreatmentService::executeJob` pega a trava `bibliomap_keywords_treat` e, no modo `execute`, recusa o início com `SELECT COUNT(*) FROM dataset WHERE status = 'importing'` maior que zero. A exceção `KeywordTreatmentBlockedException` vira aviso e código 0 no comando e flash na tela.
- `ImportDatasetCommand::importDataset` pega `GET_LOCK('bibliomap_import_dataset_{id}', 0)` antes de importar cada dataset e solta num `finally`. O status `importing` é gravado dentro desse trecho (`doImportDataset`). Se o processo morre (servidor reiniciado, falta de memória, `kill`), o MySQL solta a trava com a sessão, mas o status fica `importing`.
- Vários arquivos no mesmo processo: os datasets são importados em sequência, um de cada vez, cada um sob a sua trava. Os que ainda esperam ficam `pending`, e a regra atual também não os considera.
- Produção, 10/11/2026: o dataset #11 (projeto 2, arquivado) estava `importing` desde 02/06/2026, sem processo e sem trava (`IS_USED_LOCK` nulo). Foi corrigido à mão para `error`.

## Goals / Non-Goals

**Goals:**
- Uma importação interrompida não bloqueia o tratamento Real.
- Uma importação que está rodando continua bloqueando o tratamento Real.
- Quem roda o tratamento fica sabendo dos datasets interrompidos.

**Non-Goals:**
- Corrigir sozinho o status de um dataset interrompido.
- Mudar a importação, as travas dela ou a retomada de importações.
- Proteger o enriquecimento do projeto (trava `bibliomap_enrich_project_{id}`) ou o intervalo entre dois datasets do mesmo processo. A regra de hoje também não cobre esses casos.

## Decisions

### 1. Importação em andamento = status `importing` com a trava ocupada
Uma consulta só, no modo `execute`, depois de pegar a trava do tratamento:

```sql
SELECT id, IS_USED_LOCK(CONCAT('bibliomap_import_dataset_', id)) AS sessao
FROM dataset WHERE status = 'importing'
```

Se alguma linha tem `sessao` não nula, o tratamento é recusado com a mesma mensagem de hoje ("Importação em andamento: tratamento adiado."). As linhas com `sessao` nula são importações interrompidas.
- Por que a trava, e não a data: a trava é do MySQL e some com a sessão do processo. Uma data limite ("importing há mais de N horas") erraria nas importações grandes e lentas e exigiria escolher N.
- Por que não procurar o processo (`ps`): o servidor web e o comando podem rodar com permissões diferentes, e a trava já existe para isso.
- Travas do MySQL são do servidor inteiro, não do banco. Nenhum outro app do servidor usa nomes `bibliomap_*`.

### 2. Importação interrompida: aviso, sem alterar o status
O serviço devolve os IDs dos datasets interrompidos junto com o processamento. O comando escreve um aviso ("Dataset(s) com status importing sem importação em andamento: #11. Corrija o status.") e segue. A tela mostra o mesmo aviso em flash. O status não muda.
- Alternativa: o tratamento marcar o dataset como `error`. Foi descartada porque gravaria num cadastro do usuário sem ninguém olhando, e o dono pode querer reimportar.
- O aviso aparece também na simulação, que já lê a tabela, para ser visto antes do modo Real.

## Risks / Trade-offs

- [O processo de importação perde a conexão com o MySQL no meio (tempo limite) e a trava some com ele, mas a importação segue com uma conexão nova] → o tratamento poderia começar durante essa importação. A importação usa a conexão o tempo todo, então o tempo limite não é esperado. Se acontecer, vale o que o design de `cron-do-painel-corrigido` já prevê: reimportar o mesmo arquivo completa o que faltou.
- [Aviso repetido toda madrugada enquanto ninguém corrige o status] → é o objetivo: o log da rotina mostra o aviso até a correção.

## Migration Plan

1. Implementar e testar localmente. Rodar `vendor/bin/phpunit` antes do commit.
2. Produção, **escrita**: backup (`app:database:backup`), deploy (`git pull --ff-only`, `./build.sh --prod`, sem migration) e conferência.
- Rollback: reverter o commit. Não há dado a desfazer.

## Open Questions

- Vale cobrir também o enriquecimento do projeto (trava `bibliomap_enrich_project_{id}`) numa mudança própria? O enriquecimento remapeia palavras-chave dos documentos e pode cruzar com uma fusão do tratamento.
