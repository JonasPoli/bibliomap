# Tasks

## 1. Código

- [ ] 1.1 Em `KeywordTreatmentService::executeJob`, no modo `execute`, trocar a contagem por status pela consulta do design (decisão 1): recusar só quando algum dataset `importing` tem `IS_USED_LOCK(CONCAT('bibliomap_import_dataset_', id))` não nulo, e guardar os IDs com a trava livre para o aviso (decisão 2), lidos também na simulação. Verificar com teste unitário (Connection simulada): trava ocupada barra o modo Real sem criar processamento; trava livre deixa passar e devolve o ID; a simulação não é barrada.
- [ ] 1.2 Mostrar o aviso dos datasets interrompidos em `KeywordTreatmentCommand` (aviso com os IDs, sem mudar o código de saída) e em `SuperAdminKeywordTreatmentController::run` (flash de aviso, além do de sucesso). Verificar com teste unitário do comando (CommandTester).
- [ ] 1.3 Conferir num banco local separado (ex.: `bibliomap_openspec`): um dataset com status `importing` sem processo deixa `app:keywords:treat --execute --limit=50` rodar, com o aviso do ID, e o status continua `importing`; com a trava presa numa sessão MySQL (`SELECT GET_LOCK('bibliomap_import_dataset_<id>', 0)`), o comando escreve "Importação em andamento: tratamento adiado." e o histórico não ganha linha. Devolver o status do dataset de teste e apagar o banco separado.
- [ ] 1.4 Atualizar a ajuda `admin.kwtreat.run` em `config/help/admin_general.yaml` e a seção de `app:keywords:treat` no `README.md` (importação em andamento = trava ocupada; importação interrompida só gera aviso). Verificar com o `tests/Twig/HelpCatalogTest.php` verde.
- [ ] 1.5 Rodar `vendor/bin/phpunit` (verde) e fazer o commit local, sem segredo nem dado do corpus.

## 2. Produção

- [ ] 2.1 Backup antes do deploy (produção, escrita só na pasta de backups): rodar `app:database:backup` à mão, como runcloud, e conferir o arquivo novo com `gzip -t`.
- [ ] 2.2 Deploy (produção, escrita), como runcloud em `~/webapps/app-bibliomap`: `git pull --ff-only origin main` e `./build.sh --prod` (sem migration).
- [ ] 2.3 Conferir depois do deploy (produção, leitura): `git log -1` igual ao main; `SELECT id FROM dataset WHERE status = 'importing'` e a trava de cada um, registrando só IDs; o log da rotina da madrugada seguinte sem "Importação em andamento" quando não há importação rodando; nenhum 5xx novo nos logs do Apache e do nginx.

## Workflow follow-up

- Arquivar só depois de `cron-do-painel-corrigido`, porque as specs `tesauro-de-palavras-chave` (requisito "Um tratamento por vez") e `rotinas-agendadas` recebem esses requisitos ao arquivar aquela mudança.
