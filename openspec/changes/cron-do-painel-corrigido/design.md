# Design

## Context

Motivação em proposal.md. Estado atual (código lido em 09/11/2026; produção conforme o `openspec/config.yaml` e uma leitura de 10/11/2026 sobre disco, tamanho do banco e fuso horário):

- **Cron**: em `/etc/cron.d/runcloud-runcloud`, às 00:00 `app:qualis:resolve-missing --env=prod` e às 00:45 `app:keywords:treat --execute --auto-create-concepts`. As duas linhas chamam `/bin/bash` antes do binário do PHP e falham. A segunda nem tem `--env=prod` (o `.env.local` já define `APP_ENV=prod`, mas a regra do projeto pede o parâmetro explícito).
- **Fuso**: o servidor e o PHP usam UTC. As 00:00 e 00:45 do cron são 21:00 e 21:45 em Brasília.
- **Backup**: não existe backup recorrente do banco de produção (confirmado pelo Jonas em 10/11/2026). Só há os dumps manuais feitos antes de deploy e de migration, na pasta de backups do usuário runcloud.
- **Banco e disco** (leitura de 10/11/2026): 46 tabelas, todas InnoDB, sem rotinas, gatilhos nem views; cerca de 400 MB de dados e 170 MB de índices. Os dumps manuais de 25/10 ficaram com cerca de 100 MB comprimidos e levaram menos de 1 minuto cada. O disco do servidor tinha cerca de 22 GB livres. O `mysqldump` 8.4 está instalado, e o PHP de linha de comando permite o `proc_open` que o componente Process usa. O `.env.local` é lido direto (não há `.env.local.php`), então uma variável nova vale sem novo build.
- **`app:keywords:treat`** (`KeywordTreatmentCommand` → `KeywordTreatmentService::executeJob`): sem `--limit`, percorre todas as palavras-chave por ID, em lotes de 200 (500 na tela), em quatro etapas: (1) limpeza, invalidação, fusão de duplicatas e alerta de suspeito; (2) agrupamento exato dentro do lote; (3) ligação exata ao tesauro `keyword`; (4) similaridade, que liga sozinha a partir de 95 sem empate ou sugere revisão entre 75 e 95. Cada execução cria um `KeywordTreatmentJob` com `started_by = 'cli'` e um `KeywordTreatmentLog` por ação.
- **O que não se repete bem**, pela leitura do código:
  1. Etapa 4: cada execução Real grava de novo, para cada termo na faixa de revisão, um log `fuzzy_review_required` pendente e um `ThesaurusMatch` pendente (`recordMatch` não verifica se já existe). A tela de revisão lista os logs pendentes, então o mesmo termo aparece uma vez por noite. Recusar fecha o log e o `ThesaurusMatch` daquela vez, e a sugestão volta na noite seguinte.
  2. Etapa 1, inválidos: cada execução registra de novo `invalid` para termos já invalidados e volta `status = 0` mesmo para termos que um administrador aceitou na Fila de Revisão. O aceite (`AdminReviewController`) só faz `status = 1` e deixa `review_reasons` como estava.
  3. Etapa 1, suspeitos: cada execução registra de novo o alerta `suspicious` dos mesmos termos.
  4. Nada impede dois tratamentos ao mesmo tempo (tela e cron). Também nada impede um tratamento durante uma importação. A importação guarda os IDs das palavras-chave em memória (`DocumentImportService::keywordCache`), e uma fusão que apague uma delas no meio faz o vínculo novo falhar na chave estrangeira.
  5. O precedente já existe: na etapa 2, "termos já agrupados por um processamento anterior não são contados de novo" (ajuda `admin.kwtreat.report_exact`). Os ajustes seguem a mesma regra.
- **`app:qualis:resolve-missing`**: primeiro liga, num UPDATE só, todos os documentos de todos os projetos sem revista cuja forma do ISSN coincide com `qualis_journal`. Depois consulta o Crossref para cada ISSN restante (200 ms entre chamadas, tempo limite de 10 s) e, quando acha o título, a Sucupira para o estrato. A revista é cadastrada mesmo sem estrato. Um ISSN que o Crossref não conhece não fica registrado e é consultado de novo toda noite.
- `build.sh --prod` apaga os `var/log/*.log` com mais de 7 dias.

## Goals / Non-Goals

**Goals:**
- As rotinas rodam toda noite, na forma exigida pelo projeto (runcloud, php84rc direto, `--env=prod`).
- Existe um backup diário do banco, com a restauração conferida, antes de ligar o tratamento, e o tratamento da noite não roda sem o backup do dia.
- Rodar o tratamento todo dia não suja a fila de revisão nem desfaz decisões manuais.
- Um tratamento por vez, e nenhum tratamento Real durante uma importação.
- Dá para ver o que a última noite fez: log da última execução de cada rotina, processamento no histórico e último backup na página do tratamento.
- A primeira execução em produção é manual, medida, com backup antes e conferência depois.

**Non-Goals:**
- Implementar `--auto-create-concepts`.
- Tornar o tratamento incremental (só termos novos). Fica para outra mudança se a primeira execução medida mostrar que o processamento completo pesa demais.
- Guardar os ISSN que o Crossref não conhece para não consultá-los de novo. Também fica para outra mudança, conforme o número de falhas da primeira execução.
- Cópia do backup fora do servidor (ver Open Questions).
- Mudar os limiares de similaridade (95/75) ou as etapas do tratamento.

## Decisions

### 1. Linhas do cron (painel RunCloud, feitas pelo Jonas)
Três tarefas, usuário `runcloud`, **Vendor Binary** `/RunCloud/Packages/php84rc/bin/php` (não `/bin/bash`). O Qualis e o tratamento ficam nos horários de hoje, e o backup (decisão 6) entra entre os dois:

| Horário | Command |
|---|---|
| `0 0 * * *` | `/home/runcloud/webapps/app-bibliomap/bin/console app:qualis:resolve-missing --env=prod --no-interaction --no-ansi > /home/runcloud/webapps/app-bibliomap/var/log/cron_qualis_resolve_missing.log 2>&1` |
| `30 0 * * *` | `/home/runcloud/webapps/app-bibliomap/bin/console app:database:backup --env=prod --no-interaction --no-ansi > /home/runcloud/webapps/app-bibliomap/var/log/cron_db_backup.log 2>&1` |
| `45 0 * * *` | `/home/runcloud/webapps/app-bibliomap/bin/console app:keywords:treat --execute --require-backup --env=prod --no-interaction --no-ansi > /home/runcloud/webapps/app-bibliomap/var/log/cron_keywords_treat.log 2>&1` |

Cada linha gerada deve ficar `M H * * * runcloud /RunCloud/Packages/php84rc/bin/php /home/runcloud/webapps/app-bibliomap/bin/console …`.
- `>` e não `>>`: cada noite substitui o log da anterior. O arquivo não cresce, e o `build.sh --prod` não o apaga, porque ele nunca chega a 7 dias. Se o cron parar, o log some depois de 7 dias, e isso também é sinal.
- O log fica em `var/log/` do webapp, que é do runcloud. Outras pastas de log do servidor podem não aceitar escrita do runcloud.
- Horários mantidos. Se a primeira execução manual do Qualis passar de 40 min, o tratamento vai para 02:00 e o backup para 01:45, para não disputar a única CPU (critério na tarefa 4.6). Em dados, as rotinas não se atrapalham: o Qualis mexe em `qualis_journal` e nas colunas de Qualis de `document`, o tratamento mexe em `keyword` e nas tabelas ligadas a ela, e o backup só lê, com uma cópia consistente que não trava as tabelas (decisão 6).
- Se a execução manual mostrar falta de memória, o Command pode começar com `-d memory_limit=1024M` antes do caminho do `bin/console`. O tratamento limpa o EntityManager a cada lote, então isso não é esperado.

### 2. `--auto-create-concepts` sai da linha
Hoje a opção não faz nada. Se um dia fosse implementada, passaria a criar conceitos no tesauro global toda noite, sem revisão de ninguém. Essa decisão precisa ser consciente quando chegar a hora. A opção continua aceita, para não quebrar chamadas antigas, e sua descrição no `--help` passa a dizer que não está implementada.
- Alternativa: remover a opção do comando. Foi descartada porque não traz ganho e quebraria quem a chama.

### 3. Memória do que já foi registrado (vale para a tela e para o comando, nos dois modos)
No começo de cada processamento, o serviço carrega, com poucas consultas:
- os pares palavra-chave × conceito com log `fuzzy_review_required` pendente ou `manual_rejected`. A etapa 4 não registra (log nem `ThesaurusMatch`) nem conta sugestão para um par já presente;
- os pares palavra-chave × motivo com log `suspicious` aplicado (status `applied`). A etapa 1 não registra nem conta alerta repetido.

Para os inválidos não precisa carregar nada: se `keyword.review_reasons` já é igual ao motivo calculado agora, o termo foi invalidado antes (pendente) ou aceito depois (ativo), e a etapa 1 o deixa como está, sem log e sem contagem. Um motivo diferente (regra nova do normalizador) é tratado de novo.
- O log é a fonte da memória porque é o que a tela de revisão lê e o que a recusa altera (`manual_rejected`). Os `ThesaurusMatch` pendentes são gravados junto com o log e saem junto.
- Alternativa: limpar as pendências antes de cada execução. Foi descartada porque perderia o histórico e não resolveria a recusa que volta.
- Para testar sem banco (os testes do projeto são unitários), a regra fica num objeto pequeno (ex.: `KeywordTreatmentMemory`) montado a partir das linhas carregadas.
- Efeito na tela: "Suspeitos", "Termos inválidos" e "Revisão" de cada relatório passam a mostrar só o que é novo naquele processamento, como já acontece no "Match exato". A ajuda explica isso.

### 4. Um tratamento por vez, e nunca durante uma importação
`executeJob` pega a trava MySQL `bibliomap_keywords_treat` (`GET_LOCK(…, 0)`, o mesmo padrão das travas da importação) antes de criar o `KeywordTreatmentJob` e a solta num `finally`. No modo `execute`, antes de começar, verifica se há dataset com status `importing`. Nos dois casos lança uma exceção própria com a mensagem do motivo. O comando escreve um aviso e devolve `Command::SUCCESS` (nada falhou, a noite só foi pulada), e a tela mostra o aviso em flash.
- A simulação também respeita a trava, porque escreve no histórico e disputa a mesma CPU, mas pode rodar durante uma importação, porque não altera palavras-chave.
- Alternativa: fazer a importação esperar a trava do tratamento. Foi descartada porque prenderia a importação do usuário durante todo o tratamento e mudaria a capacidade de importação. Fica o risco residual de uma importação que comece no meio do tratamento (ver Riscos).

### 5. Primeira execução manual antes de ligar o cron
Em produção, nesta ordem, fora do horário de uso: backup diário criado e restauração conferida (decisão 6) → backup à mão com o mesmo comando → Qualis manual com `time` → simulação do tratamento com `time` (escreve só no histórico e nos logs `simulated`) → o Jonas lê o relatório ("Vínculos e documentos afetados", fusões, inválidos) e decide → tratamento Real manual com `time` e `--require-backup` → conferência. As durações e contagens (sem dados pessoais) são registradas na seção "Medições" abaixo e decidem o horário (decisão 1). O cron do tratamento só é ligado depois.

### 6. Backup diário do banco antes de ligar o cron
O tratamento Real grava em tabelas globais e não tem desfazer, e não existe backup recorrente do banco. Decisão do Jonas em 10/11/2026: o backup diário entra nesta mudança, e o cron do tratamento só é ligado depois dele.

Opções comparadas:

| Opção | A favor | Contra |
|---|---|---|
| (a) Backup de banco do painel RunCloud | Sem código; a cópia fica fora do servidor; o recurso existe na conta | Só cobre bancos criados pelo painel (falta confirmar este); o horário segue o agendamento do painel, sem ligação com o do tratamento; o servidor não tem como saber se a cópia do dia terminou, então o tratamento não consegue exigi-la; restaurar num banco temporário exige baixar a cópia pelo painel |
| (b) Snapshot da DigitalOcean | Sem código; cobre o servidor inteiro | É do servidor inteiro (com o ufscar-cech e o n8n), não do banco; o padrão é semanal; copia o disco com o MySQL rodando, sem garantia de consistência do banco; voltar só o banco exige subir outra máquina a partir do snapshot; o servidor também não sabe quando a cópia terminou |
| (c) Cron próprio com mysqldump comprimido | Roda no horário certo, antes do tratamento; o tratamento confere o arquivo do dia; retenção sob controle; restaura só o banco, inclusive num banco temporário | Fica no mesmo servidor (não protege contra a perda da máquina); ocupa disco do servidor compartilhado; é código a manter |

Escolhida a **(c)**, porque é a única que garante que o dump termina antes do `app:keywords:treat` e permite a regra "sem backup do dia, sem tratamento". A cópia fora do servidor, com a (a) como segunda camada, fica nas Open Questions.

Como funciona:
- **Comando do projeto**: `app:database:backup`, chamado pelo cron com o binário do PHP direto e `--env=prod`, como as outras rotinas (regra do projeto e requisito "Comando agendado sem intérprete de shell"). Um script de shell exigiria `/bin/bash` na tarefa e o escape de `%` na linha do cron, e a regra de "backup completo" ficaria em dois lugares (no script e no tratamento). Com o comando, o backup, o portão do tratamento e o aviso da página usam a mesma classe, testada sem banco.
- **Dump**: `mysqldump --single-transaction --no-tablespaces` do banco da `DATABASE_URL`, com a saída passada direto ao `gzip`. Como todas as tabelas são InnoDB e não há rotinas, gatilhos nem views, `--single-transaction` dá uma cópia consistente sem travar as tabelas. O arquivo nasce com o sufixo `.part` e só vira `bibliomap-AAAAMMDD-HHMM.sql.gz` depois de o `mysqldump` e o `gzip` terminarem sem erro e de o `gzip -t` conferir o arquivo. Um arquivo parcial nunca conta como backup.
- **Credencial**: nunca aparece na linha do cron, no log nem na linha de comando do processo. A cada execução, o comando grava, a partir da `DATABASE_URL`, um arquivo de opções do MySQL temporário com permissão 600 na própria pasta de backups, passa esse arquivo ao `mysqldump` com `--defaults-extra-file` e o apaga num `finally`. Se uma execução morrer no meio, a seguinte apaga as sobras (arquivo de opções e `.part`) antes de começar.
  - Alternativa: um arquivo de opções fixo na pasta do usuário. Foi descartada porque duplica a senha, e cada troca de senha teria de ser repetida nele.
- **Local**: uma pasta do usuário runcloud fora de `webapps/`, portanto fora do webapp e da pasta pública, com permissão 700 e arquivos 600. O caminho fica só no `.env.local` do servidor, na variável `DATABASE_BACKUP_DIR` (o `.env` versionado a declara vazia). O comando recusa a variável vazia, uma pasta dentro do projeto ou uma pasta com acesso de grupo ou de outros.
- **Retenção**: depois de um backup bem-sucedido, o comando apaga os backups com mais de 7 dias, exceto os feitos num domingo, que ficam até 28 dias (a idade vem da data no nome do arquivo). São 7 diários e 4 semanais, cerca de 11 arquivos, mais os feitos à mão na última semana. A regra é por idade, e não pelos N mais recentes, para que um backup feito à mão não tire da pasta o da noite. Um backup que falhou não apaga nada.
- **Disco**: estimativa de 100 a 130 MB por backup (o dump não leva os índices) e de 1,1 a 1,5 GB com a retenção completa, menos de 7% dos cerca de 22 GB livres em 10/11/2026. Para não encher o disco de três aplicações, o comando não começa com menos de 2 GB livres na partição da pasta.
- **Horário**: 00:30, quinze minutos antes do tratamento (se o tratamento for para 02:00 pela decisão 1, o backup vai para 01:45). Tem de ser depois de 00:00, porque o tratamento exige um backup do mesmo dia. Como o servidor e o PHP usam UTC, o dia do nome do arquivo é o mesmo dia do cron. Se a primeira execução medida passar de 10 minutos, a folga de 15 minutos não basta e o horário volta para este design.
- **Portão no tratamento**: a linha do cron do tratamento passa `--require-backup`. Com essa opção, `app:keywords:treat` procura na pasta de backups um arquivo completo com a data do dia. Sem ele (ou sem a pasta configurada), escreve "Backup do banco de hoje não encontrado: tratamento adiado." e termina com `Command::FAILURE`, sem criar processamento nem alterar palavras-chave. Diferente da trava e da importação (decisão 4), aqui o código de falha é intencional: a falta de backup é defeito de outra rotina e precisa de conserto. A tela não usa a opção, porque ali o super administrador decide na hora.
- **Aviso**: a página `/admin/super/keywords/treatment` mostra a data e o tamanho do último backup completo e, sem backup do dia, avisa que o tratamento da noite não vai rodar. A página só lê nome, data e tamanho dos arquivos.
  - Alternativa: e-mail quando o backup falha. Foi descartada por ora porque o código não envia e-mail hoje, e um alerta que depende do envio de e-mail cria outro ponto de falha. Pode entrar noutra mudança.
- **Conferência da restauração**: antes da primeira execução Real, o backup é restaurado num banco temporário local, e as linhas de `document` e `document_keyword` são comparadas com as de produção, contadas logo depois do dump. O banco temporário e a cópia local são apagados no fim, porque o backup tem dados pessoais dos usuários. Restaurar no servidor foi descartado, porque disputaria CPU e disco com as outras aplicações e provavelmente exigiria criar um banco pelo painel.
- **Cron**: tarefa nova no painel RunCloud, criada pelo Jonas (o painel regrava o `/etc/cron.d`), com a saída em `var/log/cron_db_backup.log` (decisão 1).

### Efeito em todos os projetos (tabelas de autoridade globais)
Toda noite, sem ação do dono:
- **Tratamento**: fusões de termos que só diferem na limpeza (o duplicado é apagado, os vínculos passam ao que fica, a grafia vira variação), agrupamentos exatos e ligações ao tesauro (exatas e por similaridade ≥ 95 sem empate) mudam o conceito efetivo. Com isso mudam a contagem de palavras-chave do painel, as redes de palavras-chave, o mapa e a evolução temática, as matrizes com palavras-chave, o gráfico de três campos e o Jaccard do Comparativo da classificação, em **todos os projetos**. A invalidação (status 0) muda o remapeamento da próxima sincronização e a Fila de Revisão. Os resultados gravados da classificação não mudam, mas a próxima execução pode mudar, porque o texto comparado usa `keyword_display`.
- **Qualis**: revistas novas em `qualis_journal` e `document.qualis`/`qualis_journal_id` preenchidos nos documentos de todos os projetos com aquele ISSN. Mudam o relatório por estrato, a lista "Revistas sem Qualis" e, na próxima execução da classificação, os grupos com filtro de estrato.
- **Backup**: só lê o banco. Não muda nenhum número.
- **Reprodutibilidade**: os números de um projeto podem mudar de um dia para o outro. Para figuras de dissertação, o pesquisador exporta e anota a data. A ajuda do painel do projeto (`project.show.kpi_keywords`) e a de "Revistas sem Qualis" passam a dizer isso.

### Medições (preencher nas tarefas 3.2, 3.3 e 4.2 a 4.6)
| Rotina | Duração | Contagens principais |
|---|---|---|
| Backup manual | a medir | tamanho do arquivo, espaço livre antes e depois |
| Restauração local | a medir | linhas de `document` e `document_keyword` em produção e no banco restaurado |
| Qualis manual | a medir | revistas criadas, falhas, documentos ainda sem revista |
| Tratamento, simulação | a medir | processados, fusões, inválidos, suspeitos, ligações, sugestões, vínculos e documentos afetados |
| Tratamento, Real | a medir | idem |
| Simulação logo depois do Real | a medir | inválidos, suspeitos e sugestões novos (esperado: 0) |

## Risks / Trade-offs

- [O tratamento Real não tem desfazer e passa a rodar sem ninguém olhando] → a primeira execução é manual, com backup e simulação antes, e toda noite o tratamento só roda depois do backup do dia (decisão 6). Cada processamento fica no histórico com o CSV das ações, e a grafia de todo termo fundido fica guardada como variação.
- [O backup fica no mesmo servidor e não protege contra a perda da máquina] → ele cobre o risco que esta mudança cria, que é desfazer um tratamento. A cópia fora do servidor fica nas Open Questions.
- [O backup falha e o tratamento para de rodar sem ninguém perceber] → o tratamento falha do lado seguro (não grava), o log registra o motivo e a página do tratamento mostra o aviso. As conferências da manhã seguinte e da semana incluem o backup.
- [Os backups ocupam disco no servidor compartilhado] → até cerca de 1,5 GB com a retenção, e o comando não começa com menos de 2 GB livres.
- [O arquivo do backup tem dados pessoais] → pasta 700 e arquivos 600, fora do webapp e da pasta pública, nunca no git. A cópia local da conferência é apagada no fim.
- [Importação que começa no meio do tratamento noturno pode ter um vínculo recusado pela chave estrangeira] → a importação é disparada pelo usuário, e o cron roda em UTC (21:45 em Brasília; ver Open Questions). Se acontecer, reimportar o mesmo arquivo completa o que faltou, porque a deduplicação ignora o que já entrou.
- [Tratamento completo pesado num servidor de 1 vCPU dividido com o ufscar-cech e o n8n] → a duração é medida na primeira execução. Se for longa, outra mudança torna o tratamento incremental.
- [ISSN desconhecido do Crossref consultado toda noite] → o número aparece na primeira execução. Se for alto, outra mudança guarda as falhas.
- [Sucupira ou Crossref fora do ar] → a revista não é criada (Crossref) ou fica sem estrato (Sucupira), e a noite seguinte tenta de novo o que não foi criado. Nada é apagado.
- [Relatórios passam a mostrar só o que é novo em suspeitos, inválidos e sugestões] → é a mesma regra do "Match exato", e a ajuda explica. O total de pendências continua no cartão de revisão.
- [O histórico mostra os 10 processamentos mais recentes, e os da noite empurram os manuais para fora] → a ajuda do histórico diz que os da noite aparecem com origem `cli`.

## Migration Plan

1. Código (grupo 1 das tarefas), `vendor/bin/phpunit` antes de cada commit, push pelo Jonas.
2. Produção, **escrita**: backup, deploy (`git pull --ff-only`, `./build.sh --prod`, migrations, sem nenhuma nova esperada) e conferência.
3. Produção, **escrita** na pasta de backups e no `.env.local`: cópia do `.env.local`, pasta e variável, primeira execução do backup medida e restauração conferida no banco local (decisão 6).
4. Painel RunCloud, **escrita** na configuração do servidor (feita pelo Jonas): tarefa do backup e conferência na manhã seguinte.
5. Produção, **escrita** nas tabelas globais: backup, execuções manuais medidas (decisão 5) e conferência. Pode começar depois da restauração conferida.
6. Painel RunCloud, **escrita** na configuração do servidor (feita pelo Jonas): anotar as linhas atuais, trocar as duas tarefas (decisão 1) e conferir as linhas geradas. Só depois da conferência do backup na manhã seguinte.
7. **Leitura**: conferência na manhã seguinte e depois de uma semana.
- Rollback do cron do tratamento e do Qualis: apagar as duas tarefas no painel, o que volta ao estado de hoje (nada roda). Rollback do backup: apagar a tarefa no painel e a variável do `.env.local`. Sem o backup do dia, o tratamento da noite para sozinho, como esperado. Os arquivos de backup ficam até alguém apagá-los. Rollback do código: reverter o commit, já que não há migration. Os dados alterados por um tratamento Real só voltam pelo backup.

## Open Questions

- Respondida em 10/11/2026: não existe backup recorrente do banco de produção. O backup diário entrou nesta mudança (decisão 6).
- Cópia fora do servidor: vale ligar também o backup de banco do painel RunCloud (opção a) como segunda camada, se ele cobrir este banco? A resposta não muda estas specs nem estas tarefas.
- Horário: o cron roda em UTC, então 00:00, 00:30 e 00:45 são 21:00, 21:30 e 21:45 em Brasília, quando ainda pode haver uso. Manter ou passar as três rotinas para a madrugada de Brasília (a partir de 03:00 UTC)? A ordem e as folgas continuam as mesmas, mas a mudança exige ajustar os horários na spec `rotinas-agendadas` e nas tarefas do painel.
