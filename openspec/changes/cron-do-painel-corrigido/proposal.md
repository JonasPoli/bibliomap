# Proposal

## Why

As duas tarefas do cron do painel RunCloud do BiblioMap (00:00 `app:qualis:resolve-missing` e 00:45 `app:keywords:treat --execute --auto-create-concepts`) nunca rodaram. As duas linhas passam o binário do PHP para o `/bin/bash`, que responde "cannot execute binary file". Decisão do Jonas em 09/11/2026: corrigir o cron, ciente de que a partir daí roda toda noite um tratamento em modo Real que grava na tabela **global** de palavras-chave (afeta todos os projetos) e uma resolução de ISSN que consulta o Crossref e grava no cadastro global do Qualis.

A leitura do código mostrou que o tratamento não foi feito para rodar todo dia. Cada execução Real registra de novo as mesmas sugestões de revisão (a fila cresce uma cópia por noite, e uma sugestão recusada volta na noite seguinte), invalida de novo termos que um administrador já aceitou e repete os alertas de suspeito. Além disso, nada impede dois tratamentos ao mesmo tempo, nem um tratamento durante uma importação. Ligar o cron sem esses ajustes estragaria a fila de revisão já na primeira semana.

Também não existe backup recorrente do banco de produção, e o tratamento Real não tem desfazer. Decisão do Jonas em 10/11/2026: antes de ligar o tratamento noturno, esta mudança cria um backup diário do banco, e o tratamento só roda depois do backup do dia.

## What Changes

- Cron do painel RunCloud refeito pelo Jonas: o binário `/RunCloud/Packages/php84rc/bin/php` chamado direto (sem `/bin/bash`), o caminho completo do `bin/console`, `--env=prod`, e a saída da última execução gravada em `var/log/` do webapp. Mesmos horários de hoje para o Qualis e o tratamento.
- Backup diário do banco: comando novo `app:database:backup`, numa tarefa nova do cron às 00:30, antes do tratamento. Gera um dump comprimido fora do webapp e da pasta pública, com acesso só do dono e sem a credencial na linha do cron, e guarda 7 backups diários e 4 semanais. A restauração é conferida antes da primeira execução Real.
- O tratamento da noite passa `--require-backup`: sem backup completo do banco feito no mesmo dia, não grava nada e registra o motivo. A página do tratamento mostra o último backup e avisa quando falta o do dia.
- `--auto-create-concepts` sai da linha do cron. A opção continua aceita sem efeito, e a ajuda do comando passa a dizer que ela não está implementada.
- O tratamento de palavras-chave fica repetível sem efeito colateral: não registra de novo uma sugestão pendente ou recusada para o mesmo conceito; não invalida, não conta e não registra de novo um termo cujo motivo de invalidação já está gravado nele (inclusive se um administrador o aceitou); e registra cada alerta de suspeito uma vez só.
- Um tratamento por vez (trava no banco), e nenhum tratamento Real durante uma importação em andamento. Quem chega depois termina sem alterar nada, com a mensagem do motivo.
- Antes de ligar o cron do tratamento: deploy com backup, backup diário criado e conferido por restauração, depois simulação e execuções manuais medidas em produção (com backup antes e conferência depois). Depois de ligar: conferência na manhã seguinte e depois de uma semana.
- Ajuda contextual (`config/help/*.yaml`) e `README.md` atualizados: rotina noturna, backup antes do tratamento, efeito em todos os projetos, contagens que passam a mostrar só o que é novo.

## Capabilities

### New Capabilities
- `rotinas-agendadas`: rotinas que o servidor de produção executa sozinho pelo cron do painel RunCloud para manter os cadastros globais (Qualis e palavras-chave), e o backup diário do banco que vem antes do tratamento, com horário, forma do comando e registro da última execução.

### Modified Capabilities
- `tesauro-de-palavras-chave`: muda "Aplicação no tratamento de palavras-chave" (sugestão pendente ou recusada não se repete) e "Criação automática de conceitos" (a rotina não passa a opção). Entram os requisitos "Invalidação e alerta registrados uma vez" e "Um tratamento por vez".

## Impact

- Código: `src/Service/KeywordTreatment/KeywordTreatmentService.php` (trava, verificação de importação, memória de sugestões, invalidações e alertas), `src/Command/KeywordTreatmentCommand.php` (mensagens, descrição da opção e `--require-backup`), `src/Controller/Admin/SuperAdminKeywordTreatmentController.php` e o template da página do tratamento (mensagem quando o tratamento não pode começar e aviso do backup). Novos: o comando `app:database:backup` e um serviço de backup (ex.: `src/Service/DatabaseBackup/`), e a variável `DATABASE_BACKUP_DIR` declarada vazia no `.env`. Testes em `tests/Service/`. Sem migration.
- Servidor: três tarefas do cron no painel RunCloud, editadas ou criadas pelo Jonas; três arquivos de log em `var/log/` do webapp; uma pasta de backups do usuário runcloud fora do webapp, configurada só no `.env.local`, com até cerca de 1,5 GB.
- Dados globais, toda noite: `keyword`, `document_keyword`, `palavra_chave_variacoes_nome`, `thesaurus_match`, `keyword_treatment_job`/`keyword_treatment_log`, `qualis_journal` e as colunas de Qualis de `document`. Os números de palavras-chave, das redes, do mapa e da evolução temática, das matrizes e do Qualis de **todos os projetos** podem mudar de um dia para o outro sem ação do dono (ver design.md).
- Externo: consultas noturnas à API do Crossref e à página do Qualis na Sucupira para os ISSN ainda sem revista.
- Servidor dividido (1 vCPU, 2 GB) com o ufscar-cech e o n8n: o tratamento completo e o backup disputam CPU, MySQL e disco à noite. As durações e o tamanho do backup se medem nas primeiras execuções manuais.
