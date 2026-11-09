## Purpose

Rotinas que o servidor de produção executa sozinho, pelo cron do painel RunCloud, para manter os cadastros globais (Qualis dos periódicos e palavras-chave) sem ação do usuário: horário, forma do comando, registro da última execução e o que cada rotina altera.

## ADDED Requirements

### Requirement: Comando agendado sem intérprete de shell
Cada tarefa do cron do BiblioMap SHALL rodar como o usuário runcloud e chamar diretamente o binário `/RunCloud/Packages/php84rc/bin/php` com o caminho completo de `bin/console` do webapp e `--env=prod`. Uma linha que entrega o binário do PHP ao `/bin/bash` MUST NOT existir.

#### Scenario: Linha gerada pelo painel
- **WHEN** as tarefas são salvas no painel RunCloud
- **THEN** cada linha do BiblioMap em `/etc/cron.d/runcloud-runcloud` traz, depois do usuário runcloud, `/RunCloud/Packages/php84rc/bin/php /home/runcloud/webapps/app-bibliomap/bin/console`
- **AND** nenhuma delas contém `/bin/bash`

### Requirement: Resolução noturna do Qualis
O servidor SHALL executar todo dia às 00:00 `app:qualis:resolve-missing --env=prod`, que liga os documentos de todos os projetos às revistas já cadastradas pelo ISSN e cadastra pelo Crossref as revistas que faltam. A saída da última execução SHALL ficar em `var/log/cron_qualis_resolve_missing.log`, substituindo a da noite anterior.

#### Scenario: ISSN novo resolvido à noite
- **WHEN** um projeto importou um documento cujo ISSN não tem revista no cadastro e o Crossref conhece esse ISSN
- **THEN** na manhã seguinte a revista está cadastrada e o documento está ligado a ela, assim como os documentos de outros projetos com o mesmo ISSN

#### Scenario: Nada a resolver
- **WHEN** todos os documentos com ISSN já estão ligados a uma revista
- **THEN** o log da noite traz "All documents already have their journals resolved! Nothing to do."

### Requirement: Tratamento noturno de palavras-chave
O servidor SHALL executar todo dia às 00:45 `app:keywords:treat --execute --env=prod`, sem limite e sem `--auto-create-concepts`, sobre todas as palavras-chave da plataforma. O processamento SHALL aparecer no histórico do tratamento com origem `cli`, e a saída da última execução SHALL ficar em `var/log/cron_keywords_treat.log`, substituindo a da noite anterior.

#### Scenario: Execução da noite
- **WHEN** chega 00:45 e não há outro tratamento nem importação em andamento
- **THEN** um processamento Real com origem `cli` aparece no histórico de `/admin/super/keywords/treatment`
- **AND** o log da última execução traz a tabela de contagens do processamento

#### Scenario: Importação em andamento à noite
- **WHEN** às 00:45 um dataset está com status `importing`
- **THEN** o tratamento daquela noite não altera nenhuma palavra-chave
- **AND** o log registra "Importação em andamento: tratamento adiado."
