## MODIFIED Requirements

### Requirement: Tratamento noturno de palavras-chave
O servidor SHALL executar todo dia às 07:30 UTC (04:30 em Brasília) `app:keywords:treat --execute --require-backup --env=prod`, sem limite e sem `--auto-create-concepts`, sobre todas as palavras-chave da plataforma. O processamento SHALL aparecer no histórico do tratamento com origem `cli`, e a saída da última execução SHALL ficar em `var/log/cron_keywords_treat.log`, substituindo a da noite anterior.

#### Scenario: Execução da noite
- **WHEN** chega 07:30 UTC, existe o backup do dia e não há outro tratamento nem importação em andamento
- **THEN** um processamento Real com origem `cli` aparece no histórico de `/admin/super/keywords/treatment`
- **AND** o log da última execução traz a tabela de contagens do processamento

#### Scenario: Importação em andamento à noite
- **WHEN** às 07:30 UTC um dataset está sendo importado (status `importing` e trava da importação ocupada)
- **THEN** o tratamento daquela noite não altera nenhuma palavra-chave
- **AND** o log registra "Importação em andamento: tratamento adiado."

#### Scenario: Importação interrompida à noite
- **WHEN** às 07:30 UTC um dataset está com status `importing` sem importação em andamento (trava livre)
- **THEN** o tratamento daquela noite roda
- **AND** o log registra o aviso com o ID desse dataset
