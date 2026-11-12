# Proposal

## Why

Diretriz do Jonas (12/11/2026): os dados coletados são sagrados e nunca devem ser alterados; o tesauro deve ser a camada que diz "este nome bruto vale aquele nome efetivo". O administrador cria o tesauro global (o padrão); cada usuário cria o seu, que vale só para os trabalhos dele e sobrescreve o global; o usuário não edita o global.

Hoje o sistema faz o contrário. Mesclar autores e palavras-chave (tela de normalização do projeto, telas de administração, tratamento noturno e enriquecimento) repontua os vínculos de todos os projetos e apaga a identidade descartada: é global e destrutivo (`NormalizationService`, `EntityMergeService`, `KeywordTreatmentService`, `DocumentEnrichmentService`). Excluir ou Descartar no administrador apaga os vínculos por CASCADE, e com eles `original_term` e `original_name`, contra a decisão registrada em `openspec/config.yaml`. Qualquer usuário aprovado com projeto mescla em escala global.

A análise de arquitetura (Fable 5.2, 12/11/2026, somente leitura, com medições no banco local) concluiu que a ideia faz sentido e que a coluna de id efetivo no vínculo, sugerida pelo Jonas, é a alternativa mais rápida: nos 98,5 mil vínculos de palavra-chave locais, a rede de palavras-chave de 155–180 ms cai para 47–106 ms, contra 175–490 ms de uma junção por consulta.

## What Changes

- **Dados brutos imutáveis**: `document_author.author_identity_id`, `document_keyword.keyword_id`, `original_name`, `original_term` e `position` não mudam depois da importação. Nenhum fluxo apaga ou repontua esses vínculos.
- **Id efetivo no vínculo**: colunas derivadas `document_author.effective_author_id` e `document_keyword.effective_keyword_id` (palavra-chave mantém a convenção atual: negativo = conceito do tesauro), com índices, lidas pelos relatórios no lugar das expressões de resolução copiadas em vários serviços. Reconstruíveis por comando.
- **Tabela `authority_mapping`** (tipo, escopo `global`/`user`/`project`, id bruto, id efetivo, origem, quem e quando), com resolução: projeto, depois usuário dono, depois global, depois o próprio bruto. Desfazer é apagar uma linha. Palavra-chave mantém o global em `keyword.keyword_concept_id`/`thesaurus_concept_id`.
- **Escopo por projeto**: quem vê um projeto público enxerga o tesauro do dono; a cópia de projeto copia o mapeamento; o usuário não grava no global.
- **Diário reversível** (`authority_merge_log`) de qualquer ação destrutiva que ainda exista durante a transição.
- **Fusões que passam a só agrupar**: administrador, tratamento noturno e enriquecimento gravam mapeamento e recalculam o efetivo, sem apagar nem trocar vínculos.

## Fases (cada uma publicável sozinha)

| Fase | Entrega | Risco | Esforço | Decisão necessária |
|---|---|---|---|---|
| 0 | Diário das fusões, bloqueio ou inativação de Excluir/Descartar, correção do cron segurada | Baixo | Pequeno | O que "inativar" significa nas análises |
| 1 | Colunas efetivas, índices, `authority_mapping` vazia, relatórios lendo o efetivo, conferência | Médio (migração em tabela de 1,5 milhão de linhas; disco) | Médio | Janela de produção; 50 a 70 MB |
| 2 | Mesclagem administrativa, tratamento e enriquecimento sem apagar; desfazer | Médio a alto | Médio a grande | Fusões já feitas; dois vínculos no mesmo documento; mesclagem entre tipos |
| 3 | Exceção por projeto na tela de normalização; cópia leva as exceções | Médio | Médio | Quem vê o projeto público |
| 4 | Tesauro do usuário, "congelar o global", divergências do global | Baixo a médio | Médio | Admin muda o global depois de uma exceção |
| 5 | Restauração dos ids brutos e das fusões antigas como mapeamento (opcional) | Médio | Médio | Fazer ou não |
| 6 | Instituições, geografia e revistas sem apagar; cache por projeto | Baixo | Pequeno a médio | Medir o projeto 9 |

Em toda fase, os 49 casos do retrato de referência (projetos 11, 12 e 13) têm de sair idênticos; onde mudar de propósito, a diferença é declarada.

## Riscos e pontos em aberto

- `author_identity` é uma forma de nome, não uma pessoa ("Silva J" pode ser pessoas diferentes): o mapeamento global de autor só deve valer com evidência forte (ORCID); o resto fica por projeto.
- O id efetivo guardado pode ficar velho se um ponto de escrita não recalcular: o `DataVersionTracker` marca a gravação em `keyword`, `thesaurus_concept` e `authority_mapping` e um comando de conferência roda no cron.
- Sem apagar vínculos, um documento pode ter dois vínculos com o mesmo efetivo: as análises que agrupam por nome (`ThreeFieldsService`, `MatrixEngineService`, `GroupComparisonService`) passam a agrupar por id e contar documento distinto.
- Mesclagem entre tipos (autor ↔ indexada) deixa de mudar o tipo da ocorrência: os números desses casos mudam.
- A correção do cron (`cron-do-painel-corrigido`) não pode ir ao ar antes da fase 0, senão as fusões destrutivas noturnas voltam.
- Fase 1 exige disco livre em produção e backup antes.

## Impact

Código: serviços de análise, importação, enriquecimento, normalização, mesclagem e tratamento; telas de normalização e de administração; cópia de projeto. Banco: duas colunas com índices (50 a 70 MB em produção), duas tabelas novas. Esta proposta ainda não tem specs delta nem tarefas: elas nascem depois das decisões do Jonas.
