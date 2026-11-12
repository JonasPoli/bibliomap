# Proposal

## Why

Em 12/11/2026 o Jonas pediu uma revisão completa do BiblioMap (código, banco e produção), com foco em desempenho, e depois pediu para implementar e publicar o que foi encontrado. A revisão leu todo o código em quatro frentes (importação e normalização; relatórios, redes, matriz e classificação; segurança e acesso; administração, tesauro e comandos), mediu as análises no banco local (projeto 13, 10,4 mil documentos) e leu a produção.

O que a revisão mostrou:

- **Produção sem disco** (leitura de 12/11/2026, 14:39 UTC): `/dev/vda1` com 49 G de 49 G. O MySQL já respondia `1114 The table '/tmp/#sql…' is full`. A causa é o app vizinho: `<webapp do app vizinho>/var/cache/public_pages` com 26 GB. O BiblioMap ocupa 1,6 G no webapp, 600 M em `~/backups` e 573 MB de banco. Sem espaço, nenhum deploy, backup ou importação funciona.
- **Segurança**: senha padrão de administrador no repositório público (`app:create-admin`), três XSS armazenados que cruzam usuários, upload da imagem de SEO sem validação dentro de `public/`, qualquer administrador vira super administrador pelo `_switch_user`, `/profile` troca e-mail e senha sem senha atual, login sem limite de tentativas, `APP_SECRET` fixo no `.env` versionado e 55 avisos do `composer audit` (Symfony 7.3 fora de suporte, PhpSpreadsheet, Dompdf).
- **Memória**: classificação, matriz, gráfico de três campos, enriquecimento e cópia de projeto carregam o projeto inteiro em arrays PHP. O gráfico de três campos usa 81–87 MB no projeto de 10 mil documentos, o que leva a ~400 MB no projeto 9 (49.992 documentos). A classificação do projeto 9 já estoura os 256 MB.
- **Trabalho repetido**: nenhuma análise é reaproveitada entre acessos; o painel dispara nove requisições; a mesma agregação de palavras-chave roda até três vezes por tela; a subconsulta `SELECT DISTINCT … FROM document_author` sem filtro de projeto materializa a tabela inteira (560 mil linhas em produção) a cada chamada, e um projeto de 232 documentos paga 37 ms em vez de 2 ms.
- **Importação**: cerca de 2 milhões de idas ao banco para 50 mil registros, uma transação por documento; registro sem DOI e sem ano nunca é deduplicado; sigla de país de duas letras vira estado dos EUA na tabela global; mesclar documentos falha quando só o descartado tem referências (`references` sem crase).
- **Administração**: a fusão simples apaga as variações e o ORCID que deveria mover; comandos destrutivos e de uso único continuam instalados (`app:keywords:clear`, correções pontuais de 07/2026, rotina de testes com caminho e e-mail pessoais); telas sem paginação com N+1.

## What Changes

- **Segurança**: `app:create-admin` sem valores padrão e sem regravar senha de usuário existente; escape nos três pontos de XSS; validação de cor e ícone dos grupos; upload de imagem restrito a jpg/png/webp e sem execução de PHP em `/uploads`; impersonação restrita; senha atual e CSRF no perfil; `login_throttling`; CSRF nas rotas de importação e de datasets; `APP_SECRET` fora do `.env` versionado; cabeçalhos de segurança; rotas de recálculo das lentes só para o dono; URL importada só com `http(s)`; TLS verificado na consulta à Sucupira.
- **Dependências**: Symfony 7.3 → 7.4 (LTS) e atualização de PhpSpreadsheet e Dompdf até o `composer audit` não apontar aviso alto; remoção de Messenger, Notifier e Mailer, que não são usados.
- **Desempenho das análises**: consultas com o filtro de projeto dentro das subconsultas; leitura em lotes na classificação; matriz e três campos sem materializar o projeto; relatório de classificação e telas de resultado sem N+1; dois índices compostos novos; cache dos resultados por versão dos dados (capacidade nova `cache-das-analises`).
- **Classificação**: gravação numa transação com INSERT em lotes (hoje uma falha no meio deixa o projeto sem resultados).
- **Importação e enriquecimento**: transação por lote e INSERT de várias linhas; enriquecimento por faixas de documentos e sem apagar tudo antes; hash de reserva para registros sem DOI e sem ano; uma importação por vez no servidor; retomada de fila interrompida; correção da sigla de duas letras, da mescla de documentos, do CSV de normalização e dos ISSN de NBIB e Lens; limite de memória do processo de importação reduzido de 2048M.
- **Administração e comandos**: fusões simples passam pelo `EntityMergeService`; importar CSV de geografia e de instituições só acrescenta variações; ISSN normalizado igual em todo o sistema; `document.qualis` propagado ao editar revista; listagens paginadas; remoção dos comandos destrutivos e de uso único; fim dos `ini_set` de 1–2 GB.
- **Mapa temático**: recorte por período na tela, como a spec já previa.
- **Determinismo**: critério de desempate nos cortes "top N" das redes, relatórios, três campos e matriz.
- **Produção**: liberar o disco (decisão do Jonas sobre o cache do ufscar-cech), `APP_SECRET` próprio no `.env.local`, conferência da senha do administrador, deploy com backup antes e conferência depois.

Ficam **fora**, por dependerem de decisão do Jonas (listados no design): restringir a mesclagem global da tela de normalização a administradores; trocar Excluir/Descartar por inativação nas telas administrativas; manter as linhas duplicadas de `document_keyword`/`document_author` no remapeamento; agrupar termos iguais entre lotes no tratamento; restringir a criação de lente teórica; índice único em `author_identity.normalized_name`; classificação em segundo plano; CSP estrita.

## Capabilities

### New Capabilities
- `cache-das-analises`: reaproveitamento dos resultados de relatórios, painel, redes, mapa temático, três campos, matriz e comparação de grupos enquanto os dados não mudam, com invalidação automática por versão dos dados.
- `seguranca-da-aplicacao`: regras de autenticação, sessão, upload, escape de saída e criação de administrador.

### Modified Capabilities
- `importacao-de-datasets`: entram os requisitos de deduplicação de registro sem DOI e sem ano, uma importação por vez, retomada de fila interrompida, endereço do documento só com `http(s)` e proteção CSRF.
- `classificacao-tematica`: entram a gravação atômica dos resultados e a validação de cor e ícone do grupo.
- `redes-e-mapa-tematico`: entra o desempate determinístico dos cortes.
- `acesso-a-projetos`: entram a impersonação restrita, a cópia só para usuário ativo e o recálculo das lentes só pelo dono.

## Impact

- Código: quase todos os serviços de `src/Service`, os controllers de projeto e de administração, `config/packages/security.yaml`, templates de relatório, `composer.json`/`composer.lock`, comandos removidos e `README.md`. Novos: `src/Service/Cache/*`, `src/Doctrine/DataVersionMiddleware.php`, `src/EventSubscriber/DataVersionSubscriber.php`, `src/Entity/AnalyticsDataVersion.php`, `config/packages/analytics_cache.yaml`.
- Banco: migrations `Version20261112090000` (índices `document_keyword (document_id, keyword_id)` e `document (project_id, year, cited_by)`) e `Version20261112140000` (tabela `analytics_data_version`). Só criam índice e tabela; nenhum dado existente muda. Outras migrations só se as frentes de importação e de administração precisarem (nomes reservados no design).
- Números dos relatórios: não mudam, com três exceções declaradas no design (matriz passa a usar o mesmo conceito efetivo das outras telas; índice de colaboração conta autores distintos; desempate fixo no último item dos cortes).
- Tabelas globais: nenhuma correção reescreve dados existentes. Mudam as gravações futuras de `estados` (sigla de duas letras), `qualis_journal` (ISSN em minúsculas, ISSN do Lens) e das variações na importação de CSV de geografia e instituições.
- Produção: deploy com backup; escrita no `.env.local` (`APP_SECRET`); sessões "lembrar de mim" são encerradas uma vez pela troca do segredo.
- Servidor: o cache das análises grava em `var/cache/prod/pools` (apagado a cada deploy, validade de 12 h, limpeza automática dos itens vencidos).
