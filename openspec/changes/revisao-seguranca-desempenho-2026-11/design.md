# Design

## Context

Motivação em proposal.md. Medições de 12/11/2026.

**Banco local** (MySQL 8.0.40, projeto 13 com 10.452 documentos), tempo / consultas / pico de memória por serviço, antes de qualquer mudança (retrato completo fora do repositório, 49 casos × 3 projetos):

| Caso | ms | consultas | pico MB |
|---|---|---|---|
| Painel `summary` | 321 | 4 | 4,3 |
| Relatório geral | 956 | 10 | 0,2 |
| Relatório de autores | 626 | 3 | 43,0 |
| Relatório de palavras-chave | 547 | 2 | 0,1 |
| Rede de palavras-chave | 670 | 2 | 16,4 |
| Mapa temático | 490 | 2 | 2,2 |
| Evolução temática | 532 | 4 | 1,7 |
| Três campos | 437–840 | 6–8 | 81–87 |
| Matriz autor × autor | 196 | 3 | 42,4 |
| Classificação (`calculate`) | 793 | 72 | 66,5 |

**Produção** (leitura, 14:39 UTC): disco 100% (49 G), carga 4,0 em 1 vCPU, 873 MB de 1.963 MB usados, 277 MB em swap. `document` 90.557 linhas (215 MB de dados), `document_keyword` 1.501.498, `document_author` 560.709, `keyword` 88.602, `author_identity` 221.778. Projetos 9 (49.992 documentos) e 2 (48.200). PHP 8.4 com OPcache ligado, `validate_timestamps` ligado, sem preload, `memory_limit` do CLI 256M e da web 512M (`public/.user.ini`). O cron do BiblioMap continua com `/bin/bash` na frente do PHP (mudança `cron-do-painel-corrigido`, tarefas de produção pendentes). No ar: `4dbf3b5`.

**Planos de execução**: as consultas lentas já usam índice. Os ganhos medidos vêm de (a) junção resolvida só pelo índice em `document_keyword` (contagem de conceitos 120 → 95 ms; lista do relatório 300 → 210 ms), (b) somas de citações sem ler a linha de `document`, que carrega resumo (1,6 KB em média) e referências (5,2 KB em média) (10 → 2 ms), (c) filtro de projeto dentro da subconsulta de `document_author` (37 → 2 ms num projeto de 232 documentos; plano atual: `DERIVED document_author type=index rows=52915 Using temporary`).

## Goals / Non-Goals

**Goals**
- Nenhuma tela do projeto 9 passa de 256 MB.
- Nenhum número de relatório muda, fora as três exceções da decisão 4.
- Um usuário comum não consegue executar script na sessão de outro nem chegar a super administrador.
- `composer audit` sem aviso de gravidade alta.
- Produção com espaço em disco e deploy conferido.

**Non-Goals**
- Mudar método de cálculo (Bibliometrix).
- Mudar regra de negócio das tabelas globais (ver "Decisões pendentes").
- Worker de fila, Redis ou outro serviço novo no servidor de 2 GB.

## Decisions

### 1. Cache por versão dos dados, com a versão subida por middleware da conexão
`AnalyticsCache::get(projeto, nome, parâmetros, cálculo)` guarda o resultado no pool `cache.analytics` (arquivos em `var/cache/{env}/pools`, 12 h). A chave leva a versão lida de `analytics_data_version` (uma linha). `DataVersionMiddleware` vê todo comando SQL da aplicação; `DataVersionTracker` reconhece gravação (`INSERT/UPDATE/DELETE/REPLACE/TRUNCATE/DDL`) em tabela que não esteja na lista de ignoradas (`user`, `site_settings`, `saved_matrix`, `dataset_skip`, `keyword_treatment_job`, `keyword_treatment_log`, `messenger_messages`, `doctrine_migration_versions`, `reset_password_request`, `sessions`) e sobe o contador depois da confirmação: fora de transação, no máximo a cada 2 s, e uma última vez no fim da requisição ou do comando (`DataVersionSubscriber` e função de encerramento).

- Por quê: as tabelas globais são gravadas em mais de dez controllers, na importação e em comandos. Invalidar "em cada ponto de escrita" deixaria um ponto esquecido mostrar número velho. O middleware não depende de quem grava.
- Alternativas descartadas: `UPDATE_TIME` do `information_schema` (resolução de 1 s, some no reinício e quando a tabela sai do cache do dicionário, o que permitiria devolver resultado velho); gatilhos (custo por linha em 1,5 milhão de vínculos); versão por projeto (exigiria saber o projeto de cada gravação global).
- Granularidade: uma versão global. Uma importação no projeto A invalida o cache do projeto B. Aceito: correto e simples.
- Proteções: não guarda quando a versão muda durante o cálculo, quando o processo tem gravação em transação aberta, quando o resultado passa de 4 MB ou quando a versão não pode ser lida (tabela ainda não criada: calcula sem cache, então o deploy antes da migration funciona). Falha do cache nunca derruba a tela.
- Limite conhecido: gravação feita fora da aplicação (SQL manual, restauração de dump) não sobe a versão. O `build.sh --prod` apaga `var/cache`, e a validade é de 12 h. Depois de SQL manual: `php bin/console cache:pool:clear cache.analytics --env=prod`.
- Disco: itens vencidos são apagados por `prune()` em 1 de cada 50 gravações.

### 2. Índices
`document_keyword (document_id, keyword_id)` e `document (project_id, year, cited_by)`, criados só se não existirem. Os índices simples redundantes não são removidos (sustentam chaves estrangeiras do mapeamento do Doctrine).

### 3. Leitura em lotes sem mudar resultado
Classificação por faixas de `document.id` (cada documento é avaliado sozinho); matriz e três campos em duas passadas (frequências, depois só as células do top N); enriquecimento por faixas com troca dos vínculos do lote em transação curta; importação com uma transação por lote de 200 e INSERT de várias linhas, refazendo o lote linha a linha quando houver duplicidade (comportamento atual).

### 4. Exceções em que o número muda, de propósito
- **Matriz**: a dimensão palavra-chave passa a usar `COALESCE(tc.preferred_label, kc.keyword_display, k.keyword_display)`, como relatórios, redes e mapa. Variantes ligadas só por `keyword_concept_id` deixam de ser linhas separadas. Efeito medido no projeto 13: registrar na tarefa 3.x antes do commit.
- **Índice de colaboração**: `COUNT(DISTINCT author_identity_id)`. No banco local há 2 pares (autor, documento) repetidos; em produção, medir.
- **Desempate**: `ORDER BY … , id` (ou rótulo) nos cortes. Só o último item do corte pode trocar, e passa a ser sempre o mesmo.

### 5. Segurança sem tirar capacidade do usuário comum
As correções fecham XSS, upload, impersonação, perfil e login. O que restringe uso atual fica para decisão (abaixo).

### 6. Dependências
Symfony 7.4 LTS por `composer.json` (`7.3.*` → `7.4.*`, `extra.symfony.require`), PhpSpreadsheet e Dompdf na última versão compatível. Conferência: `composer audit`, os testes, os lints e um roteiro manual das telas principais num servidor local antes do deploy. Se o roteiro falhar, a atualização sai deste change e vira outro.

### 7. Ordem de publicação
Commits por tema, direto na `main`. Deploy só depois de: disco liberado em produção, backup do banco em `~/backups/`, testes verdes. Migrations depois do `build.sh --prod`. O cache funciona com ou sem a tabela, então não há janela de erro.

Nomes de migration reservados: `Version20261112090000` (índices, feita), `Version20261112100000` (importação), `Version20261112120000` (administração), `Version20261112130000` (segurança), `Version20261112140000` (versão dos dados, feita).

## Risks / Trade-offs

- **Atualização do Symfony sem testes de tela**: os 121 testes são de serviço. Mitigação: roteiro manual local e deploy separado dos demais temas.
- **Importação e enriquecimento reescritos no caminho crítico**: mitigação por comparação de contagens e vínculos antes/depois num projeto descartável.
- **Cache em disco num servidor que já ficou sem disco**: validade curta, teto de 4 MB por item e limpeza automática; `du -sh var/cache/prod/pools` entra na conferência de uma semana.
- **Comandos removidos**: voltam pelo git se forem necessários.
- **Troca do `APP_SECRET`**: encerra os "lembrar de mim" uma vez.

## Decisões pendentes (Jonas)

1. Mesclagem de autores e palavras-chave pela tela de normalização do projeto é global: restringir a administradores ou limitar ao projeto?
2. Excluir e Descartar nas telas administrativas apagam `original_term`/`original_name` por CASCADE, contra a regra de nunca apagar o termo original: trocar por inativação ou bloquear quando houver vínculos?
3. Remapeamento e fusões apagam a linha "duplicada" de `document_keyword`/`document_author` do mesmo documento: manter as duas linhas?
4. Tratamento: agrupar termos iguais que caem em lotes diferentes (muda números de todos os projetos).
5. Lente teórica criada por dono de projeto é global: restringir a administradores?
6. Índice único em `author_identity.normalized_name` (exige deduplicar identidades globais antes).
7. `openspec/config.yaml` publica IP, usuário SSH e caminhos do servidor num repositório público.
8. Cache `public_pages` do ufscar-cech (26 GB): esvaziar agora e criar limite ou limpeza periódica naquele projeto.
9. `opcache.validate_timestamps=0` e preload no pool `php84rc` (painel RunCloud).

## Open Questions

- A senha do administrador de produção é a padrão do `app:create-admin`? (não verificado)
- Há estados criados por engano a partir de siglas de país em produção? (consulta na tarefa 6.x)
- Quantos pares (autor, documento) repetidos existem em produção?
