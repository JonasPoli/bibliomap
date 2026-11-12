# Tasks

Regras: `vendor/bin/phpunit` verde antes de cada commit; commit por grupo na `main`; tela alterada atualiza `config/help/*.yaml`; backup do banco antes de migration em produção; nada de IP, usuário SSH ou caminho de servidor nos arquivos (estão na memória local). Retrato de referência dos números: `retrato.php` e a pasta `antes/` do scratchpad da sessão de 12/11/2026 (49 casos × projetos 11, 12 e 13); regerar o "antes" no commit anterior a cada fase, porque o banco local pode ter mudado.

## 0. Diário e bloqueios (fase 0)

- [ ] 0.1 Migration `Version20261112160000`: tabelas `authority_merge_log` (id, created_at, user_id nulo, project_id nulo, origin `screen_project`/`screen_admin`/`treatment`/`enrichment`/`command`, action `merge`/`delete`/`discard`/`remap`, entity_type `author`/`keyword`/`institution`, source_id, target_id nulo, source_label, payload JSON com variações e identificadores) e `authority_merge_log_link` (id, log_id, document_id, link_table, old_entity_id, original_text, position nulo), com índice em `log_id` e em `(entity_type, source_id)`. Só cria; `down()` remove; idempotente. Entidades declaradas para o schema ficar em sincronia.
- [ ] 0.2 Serviço `AuthorityMergeLogger` (`src/Service/Authority/`): `begin(...)` grava o cabeçalho e `logLinks(...)` faz `INSERT … SELECT` dos vínculos que vão mudar (antes da alteração), na mesma conexão e transação do chamador; nunca falha silenciosamente (se o diário falhar, a ação falha). Teste unitário com conexão simulada e teste com rollback no banco local.
- [ ] 0.3 Ligar o diário aos caminhos que repontuam ou apagam: `NormalizationService` (mesclagens de autores e palavras-chave, em lote e individual), `EntityMergeService` (autores, palavras-chave), `KeywordTreatmentService` (fusão), `DocumentEnrichmentService` (remapeamento que troca id e apaga repetido) e as rotas de mesclagem de `AdminReviewController`. Verificar: o retrato sai idêntico e cada ação deixa as linhas esperadas no diário (script com rollback).
- [ ] 0.4 Bloquear Excluir/Descartar com vínculos: `AdminKeywordController::delete`, `AdminAuthorController::delete`, `AdminInstitutionController` (exclusão), `AdminReviewController` (`discardKeyword`, `discardAuthor`, `moveAuthorToInstitution`), com a contagem de documentos na mensagem; sem vínculos, continua permitindo. A tela esconde ou desabilita o botão quando há documentos. Ajuda atualizada. Teste.
- [ ] 0.5 Registrar em `cron-do-painel-corrigido/tasks.md` que ligar o tratamento noturno só depois da fase 2 deste change (nota na tarefa 5.2).
- [ ] 0.6 Retrato idêntico; `phpunit`, `lint:container`, `lint:twig` verdes; commit "Autoridades: diário das fusões e bloqueio de excluir com documentos".
- [ ] 0.7 Publicar: backup, `git pull`, `build.sh --prod`, migration, conferir.

## 1. Id efetivo e leitura (fase 1)

- [ ] 1.1 Migrations: colunas `effective_author_id` e `effective_keyword_id` (`ALGORITHM=INSTANT`), índices `(document_id, effective_*)` e `(effective_*)`, tabela `authority_mapping`.
- [ ] 1.2 Comando `app:authority:rebuild` (por faixas de `document.id`, idempotente, com `--check` e `--project`): preenche com o valor de hoje (autor: o próprio id; palavra-chave: a expressão `IF(thesaurus_concept_id …)` atual).
- [ ] 1.3 Importação, cópia de projeto, fusão de documentos e enriquecimento mantêm as colunas.
- [ ] 1.4 Relatórios, painel, redes, mapa, três campos, matriz, comparação e classificação passam a ler a coluna, contando documento distinto. Retrato idêntico.
- [ ] 1.5 `DataVersionTracker` marca gravação em `keyword`, `thesaurus_concept` e `authority_mapping` e dispara o recálculo; `--check` no cron.
- [ ] 1.6 Publicar com backup e disco conferido; preencher em lotes fora do horário de uso.

## 2. Global sem apagar (fase 2)

- [ ] 2.1 Mesclagem administrativa, colisão do tratamento e remapeamento do enriquecimento gravam `authority_mapping` (ou `keyword_concept_id`) e recalculam o efetivo; nada é apagado nem trocado.
- [ ] 2.2 "Desfazer" no administrador (apaga o mapeamento e recalcula).
- [ ] 2.3 `GroupComparisonService` e `ClassificationEngine` usam a palavra efetiva; documentar as diferenças de número (mesclagem entre tipos).
- [ ] 2.4 Aposentar os caminhos destrutivos e o `authority_merge_log` passa a registrar só histórico.
- [ ] 2.5 Publicar; depois, ligar o cron do tratamento (tarefa 5.2 de `cron-do-painel-corrigido`).

## 3 a 6. Detalhar depois de publicadas as fases 0 a 2

Exceção por projeto na tela de normalização e cópia levando o mapeamento (3); tesauro do usuário, "congelar o global" e divergências (4); restauração e tratamento do histórico de fusões, decidindo a autoria das fusões passadas (5); instituições, geografia e revistas sem apagar, e cache por projeto (6).
