# Design

## Context

Motivação em proposal.md. Estado atual (código lido em 09/11/2026; produção conforme o `openspec/config.yaml`, sem acesso novo ao servidor):

- **Cron**: em `/etc/cron.d/runcloud-runcloud`, às 00:00 `app:qualis:resolve-missing --env=prod` e às 00:45 `app:keywords:treat --execute --auto-create-concepts`. As duas linhas chamam `/bin/bash` antes do binário do PHP e falham. A segunda nem tem `--env=prod` (o `.env.local` já define `APP_ENV=prod`, mas a regra do projeto pede o parâmetro explícito).
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
- As duas rotinas rodam toda noite, na forma exigida pelo projeto (runcloud, php84rc direto, `--env=prod`).
- Rodar o tratamento todo dia não suja a fila de revisão nem desfaz decisões manuais.
- Um tratamento por vez, e nenhum tratamento Real durante uma importação.
- Dá para ver o que a última noite fez: log da última execução e processamento no histórico.
- A primeira execução em produção é manual, medida, com backup antes e conferência depois.

**Non-Goals:**
- Implementar `--auto-create-concepts`.
- Tornar o tratamento incremental (só termos novos). Fica para outra mudança se a primeira execução medida mostrar que o processamento completo pesa demais.
- Guardar os ISSN que o Crossref não conhece para não consultá-los de novo. Também fica para outra mudança, conforme o número de falhas da primeira execução.
- Backup automático recorrente do banco (ver Open Questions).
- Mudar os limiares de similaridade (95/75) ou as etapas do tratamento.

## Decisions

### 1. Linhas do cron (painel RunCloud, feitas pelo Jonas)
Duas tarefas, usuário `runcloud`, **Vendor Binary** `/RunCloud/Packages/php84rc/bin/php` (não `/bin/bash`), nos mesmos horários de hoje:

| Horário | Command |
|---|---|
| `0 0 * * *` | `/home/runcloud/webapps/app-bibliomap/bin/console app:qualis:resolve-missing --env=prod --no-interaction --no-ansi > /home/runcloud/webapps/app-bibliomap/var/log/cron_qualis_resolve_missing.log 2>&1` |
| `45 0 * * *` | `/home/runcloud/webapps/app-bibliomap/bin/console app:keywords:treat --execute --env=prod --no-interaction --no-ansi > /home/runcloud/webapps/app-bibliomap/var/log/cron_keywords_treat.log 2>&1` |

Cada linha gerada deve ficar `M H * * * runcloud /RunCloud/Packages/php84rc/bin/php /home/runcloud/webapps/app-bibliomap/bin/console …`.
- `>` e não `>>`: cada noite substitui o log da anterior. O arquivo não cresce, e o `build.sh --prod` não o apaga, porque ele nunca chega a 7 dias. Se o cron parar, o log some depois de 7 dias, e isso também é sinal.
- O log fica em `var/log/` do webapp, que é do runcloud. Outras pastas de log do servidor podem não aceitar escrita do runcloud.
- Horários mantidos. Se a primeira execução manual do Qualis passar de 40 min, o tratamento vai para 02:00, para não disputar a única CPU (critério na tarefa 3.6). Em dados, as duas rotinas não se atrapalham: o Qualis mexe em `qualis_journal` e nas colunas de Qualis de `document`, e o tratamento mexe em `keyword` e nas tabelas ligadas a ela.
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
Em produção, nesta ordem, fora do horário de uso: backup → Qualis manual com `time` → simulação do tratamento com `time` (escreve só no histórico e nos logs `simulated`) → o Jonas lê o relatório ("Vínculos e documentos afetados", fusões, inválidos) e decide → tratamento Real manual com `time` → conferência. As durações e contagens (sem dados pessoais) são registradas na seção "Medições" abaixo e decidem o horário (decisão 1). O cron só é ligado depois.

### Efeito em todos os projetos (tabelas de autoridade globais)
Toda noite, sem ação do dono:
- **Tratamento**: fusões de termos que só diferem na limpeza (o duplicado é apagado, os vínculos passam ao que fica, a grafia vira variação), agrupamentos exatos e ligações ao tesauro (exatas e por similaridade ≥ 95 sem empate) mudam o conceito efetivo. Com isso mudam a contagem de palavras-chave do painel, as redes de palavras-chave, o mapa e a evolução temática, as matrizes com palavras-chave, o gráfico de três campos e o Jaccard do Comparativo da classificação, em **todos os projetos**. A invalidação (status 0) muda o remapeamento da próxima sincronização e a Fila de Revisão. Os resultados gravados da classificação não mudam, mas a próxima execução pode mudar, porque o texto comparado usa `keyword_display`.
- **Qualis**: revistas novas em `qualis_journal` e `document.qualis`/`qualis_journal_id` preenchidos nos documentos de todos os projetos com aquele ISSN. Mudam o relatório por estrato, a lista "Revistas sem Qualis" e, na próxima execução da classificação, os grupos com filtro de estrato.
- **Reprodutibilidade**: os números de um projeto podem mudar de um dia para o outro. Para figuras de dissertação, o pesquisador exporta e anota a data. A ajuda do painel do projeto (`project.show.kpi_keywords`) e a de "Revistas sem Qualis" passam a dizer isso.

### Medições (preencher nas tarefas 3.2 a 3.6)
| Rotina | Duração | Contagens principais |
|---|---|---|
| Qualis manual | a medir | revistas criadas, falhas, documentos ainda sem revista |
| Tratamento, simulação | a medir | processados, fusões, inválidos, suspeitos, ligações, sugestões, vínculos e documentos afetados |
| Tratamento, Real | a medir | idem |
| Simulação logo depois do Real | a medir | inválidos, suspeitos e sugestões novos (esperado: 0) |

## Risks / Trade-offs

- [O tratamento Real não tem desfazer e passa a rodar sem ninguém olhando] → a primeira execução é manual, com backup e simulação antes. Cada processamento fica no histórico com o CSV das ações, e a grafia de todo termo fundido fica guardada como variação. Um backup recorrente está nas Open Questions.
- [Importação que começa no meio do tratamento noturno pode ter um vínculo recusado pela chave estrangeira] → o horário é de pouco uso, e a importação é disparada pelo usuário. Se acontecer, reimportar o mesmo arquivo completa o que faltou, porque a deduplicação ignora o que já entrou.
- [Tratamento completo pesado num servidor de 1 vCPU dividido com o ufscar-cech e o n8n] → a duração é medida na primeira execução. Se for longa, outra mudança torna o tratamento incremental.
- [ISSN desconhecido do Crossref consultado toda noite] → o número aparece na primeira execução. Se for alto, outra mudança guarda as falhas.
- [Sucupira ou Crossref fora do ar] → a revista não é criada (Crossref) ou fica sem estrato (Sucupira), e a noite seguinte tenta de novo o que não foi criado. Nada é apagado.
- [Relatórios passam a mostrar só o que é novo em suspeitos, inválidos e sugestões] → é a mesma regra do "Match exato", e a ajuda explica. O total de pendências continua no cartão de revisão.
- [O histórico mostra os 10 processamentos mais recentes, e os da noite empurram os manuais para fora] → a ajuda do histórico diz que os da noite aparecem com origem `cli`.

## Migration Plan

1. Código (grupo 1 das tarefas), `vendor/bin/phpunit` antes de cada commit, push pelo Jonas.
2. Produção, **escrita**: backup, deploy (`git pull --ff-only`, `./build.sh --prod`, migrations, sem nenhuma nova esperada) e conferência.
3. Produção, **escrita** nas tabelas globais: backup, execuções manuais medidas (decisão 5) e conferência.
4. Painel RunCloud, **escrita** na configuração do servidor (feita pelo Jonas): anotar as linhas atuais, trocar as duas tarefas (decisão 1) e conferir a linha gerada.
5. **Leitura**: conferência na manhã seguinte e depois de uma semana.
- Rollback do cron: apagar as duas tarefas no painel, o que volta ao estado de hoje (nada roda). Rollback do código: reverter o commit, já que não há migration. Os dados alterados por um tratamento Real só voltam pelo backup.

## Open Questions

- Existe backup recorrente do banco de produção (RunCloud Backup ou snapshot da DigitalOcean)? Se não existir, vale uma mudança própria para um dump noturno antes do tratamento, com rotação. A resposta não muda estas specs nem estas tarefas.
