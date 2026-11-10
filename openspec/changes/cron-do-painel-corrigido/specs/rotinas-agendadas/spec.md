## Purpose

Rotinas que o servidor de produção executa sozinho, pelo cron do painel RunCloud, para manter os cadastros globais (Qualis dos periódicos e palavras-chave) sem ação do usuário, e o backup diário do banco que protege essas rotinas: horário, forma do comando, registro da última execução e o que cada rotina altera.

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

### Requirement: Backup diário do banco
O servidor SHALL executar todo dia às 00:30, antes do tratamento noturno, `app:database:backup --env=prod`, que grava um backup completo e comprimido do banco do BiblioMap numa pasta fora do webapp e da pasta pública, acessível só ao usuário runcloud. Um arquivo SHALL contar como backup só depois de o dump terminar sem erro e de o arquivo comprimido ser conferido. A saída da última execução SHALL ficar em `var/log/cron_db_backup.log`, substituindo a da noite anterior.

#### Scenario: Backup da noite
- **WHEN** chega 00:30 e há espaço livre no disco
- **THEN** a pasta de backups ganha um arquivo comprimido com a data do dia, legível só pelo dono
- **AND** o log da última execução traz o nome, o tamanho e a duração do backup

#### Scenario: Falha no meio do backup
- **WHEN** o dump falha ou o espaço livre no disco é menor que o mínimo
- **THEN** nenhum arquivo parcial conta como backup do dia
- **AND** o log registra o motivo

### Requirement: Credencial e retenção dos backups
O usuário e a senha do banco MUST NOT aparecer na linha do cron, no log nem na linha de comando dos processos do backup. A rotina SHALL guardar os backups dos últimos 7 dias e os feitos num domingo nas últimas 4 semanas, e MUST NOT apagar nenhum backup quando o da noite falhar.

#### Scenario: Credencial fora da vista
- **WHEN** alguém lê a linha do cron do backup, o log da última execução ou a lista de processos durante o dump
- **THEN** nenhum deles mostra o usuário nem a senha do banco

#### Scenario: Backup que falhou não apaga nada
- **WHEN** o backup da noite falha
- **THEN** todos os backups anteriores continuam na pasta

#### Scenario: Retenção
- **WHEN** um backup termina bem e a pasta tem um backup de 8 dias atrás feito numa terça-feira e outro de 14 dias atrás feito num domingo
- **THEN** o de terça-feira é apagado
- **AND** o de domingo continua na pasta

### Requirement: Tratamento noturno só com backup do dia
O tratamento noturno de palavras-chave SHALL rodar só se existir um backup completo do banco feito no mesmo dia. Sem esse backup, o tratamento MUST NOT criar processamento nem alterar palavras-chave, e SHALL registrar o motivo no log da última execução. A página do tratamento SHALL mostrar o último backup completo e avisar quando não houver backup do dia.

#### Scenario: Backup ausente
- **WHEN** às 00:45 não há na pasta de backups um backup completo com a data do dia
- **THEN** o tratamento daquela noite não cria processamento nem altera nenhuma palavra-chave
- **AND** o log registra "Backup do banco de hoje não encontrado: tratamento adiado."

#### Scenario: Aviso na página do tratamento
- **WHEN** o super administrador abre `/admin/super/keywords/treatment` e não há backup do dia
- **THEN** a página mostra a data do último backup completo e avisa que o tratamento da noite não vai rodar

### Requirement: Tratamento noturno de palavras-chave
O servidor SHALL executar todo dia às 00:45 `app:keywords:treat --execute --require-backup --env=prod`, sem limite e sem `--auto-create-concepts`, sobre todas as palavras-chave da plataforma. O processamento SHALL aparecer no histórico do tratamento com origem `cli`, e a saída da última execução SHALL ficar em `var/log/cron_keywords_treat.log`, substituindo a da noite anterior.

#### Scenario: Execução da noite
- **WHEN** chega 00:45, existe o backup do dia e não há outro tratamento nem importação em andamento
- **THEN** um processamento Real com origem `cli` aparece no histórico de `/admin/super/keywords/treatment`
- **AND** o log da última execução traz a tabela de contagens do processamento

#### Scenario: Importação em andamento à noite
- **WHEN** às 00:45 um dataset está com status `importing`
- **THEN** o tratamento daquela noite não altera nenhuma palavra-chave
- **AND** o log registra "Importação em andamento: tratamento adiado."
