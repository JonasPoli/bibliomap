## ADDED Requirements

### Requirement: Diário das ações que alteram autoridades
Toda ação que reponte vínculos, apague ou funda uma identidade de autor ou uma palavra-chave (telas do projeto e de administração, tratamento de palavras-chave, enriquecimento) SHALL gravar, na mesma transação, uma linha em `authority_merge_log` com quando, quem, projeto, tipo da ação e entidades de origem e de destino, e uma linha em `authority_merge_log_link` por vínculo alterado, com documento, id anterior, texto original e posição. Essas tabelas SHALL ser só de inserção.

#### Scenario: Mesclagem de autores pela tela do projeto
- **WHEN** um usuário mescla o autor B no autor A pela tela de normalização
- **THEN** o diário registra a ação com o usuário e o projeto, a identidade B e as variações dela
- **AND** uma linha por vínculo de `document_author` de B com o `original_name` e a posição de cada um

#### Scenario: Ação que falha
- **WHEN** a mesclagem falha e a transação é desfeita
- **THEN** nenhuma linha do diário permanece

#### Scenario: Tratamento noturno
- **WHEN** o tratamento de palavras-chave funde duas palavras-chave
- **THEN** o diário registra a fusão com origem `treatment` e sem usuário

### Requirement: Excluir e descartar só sem documentos ligados
Excluir ou descartar um autor, uma palavra-chave ou uma instituição pelas telas de administração (inclusive a Fila de Revisão) SHALL ser recusado quando existir ao menos um vínculo de documento com a entidade, informando a contagem de documentos. Sem vínculos, a ação SHALL ser permitida. Nenhuma dessas ações MUST apagar `original_name` ou `original_term` de documentos.

#### Scenario: Palavra-chave com documentos
- **WHEN** o administrador descarta uma palavra-chave ligada a 12 documentos
- **THEN** a palavra-chave permanece, nenhum documento perde o vínculo e a tela informa que há 12 documentos ligados

#### Scenario: Autor sem documentos
- **WHEN** o administrador exclui um autor sem nenhum documento
- **THEN** o autor é excluído

### Requirement: Dados importados são imutáveis
Depois da importação, `document_author.author_identity_id`, `document_keyword.keyword_id`, `original_name`, `original_term` e `position` MUST NOT ser alterados nem apagados por nenhuma ação do sistema. A identidade efetiva usada nas análises SHALL ser guardada em colunas derivadas e reconstruíveis (`effective_author_id`, `effective_keyword_id`).

#### Scenario: Mesclar não muda o vínculo bruto
- **WHEN** o administrador mescla o autor B no autor A
- **THEN** os vínculos de B continuam apontando para B
- **AND** as análises passam a contar esses vínculos como A

### Requirement: Mapeamento por escopo
O sistema SHALL guardar as decisões de agrupamento em `authority_mapping` (tipo, escopo `global`, `user` ou `project`, id bruto, id efetivo, origem, autor e data) e resolver a identidade efetiva de um vínculo pela ordem projeto, usuário dono do projeto, global e, por último, o próprio id bruto. O alvo de um mapeamento MUST ser final, e quem vê um projeto público SHALL ver o resultado com o tesauro do dono. Nenhuma tela grava o escopo `user` neste change.

#### Scenario: Exceção do projeto sobre o padrão global
- **WHEN** o administrador mapeia "Silva J" para "Silva, João" no global e o dono de um projeto mapeia "Silva J" para "Silva, José" no seu projeto
- **THEN** nesse projeto "Silva J" conta como "Silva, José"
- **AND** nos demais projetos conta como "Silva, João"

#### Scenario: Desfazer
- **WHEN** a linha do mapeamento é apagada
- **THEN** a identidade efetiva volta à do nível seguinte e nenhum vínculo bruto precisou ser restaurado

### Requirement: Quem pode gravar em cada escopo
Somente administradores SHALL gravar mapeamentos de escopo `global`. Um usuário comum SHALL gravar apenas mapeamentos dos seus próprios projetos (escopo `project`), e MUST NOT alterar o tesauro global nem os dados de outro usuário. Um administrador que mescla pela tela de normalização de um projeto SHALL gravar no global.

#### Scenario: Usuário comum mescla na tela do projeto
- **WHEN** um usuário comum mescla dois autores na tela de normalização do seu projeto
- **THEN** é gravado um mapeamento de escopo `project` e os outros projetos não mudam
- **AND** nenhuma identidade nem vínculo é apagado

#### Scenario: Administrador mescla na tela de administração
- **WHEN** um administrador mescla dois autores pela tela de administração
- **THEN** é gravado um mapeamento global e todos os projetos passam a ver os dois como um

### Requirement: Mesclar agrupa sem apagar
As mesclagens de autores e de palavras-chave (telas de administração, tela de normalização do projeto, importação de CSV de normalização), a colisão de formas normalizadas do tratamento de palavras-chave e o remapeamento pelas variações no enriquecimento SHALL gravar o agrupamento (mapeamento ou `keyword_concept_id`) e recalcular a identidade efetiva, e MUST NOT apagar identidades, apagar vínculos nem alterar o id bruto dos vínculos. As variações da identidade de origem SHALL permanecer com ela.

#### Scenario: Mesclar autores
- **WHEN** o administrador mescla o autor B no autor A
- **THEN** B continua existindo com as suas variações e os vínculos de B continuam apontando para B
- **AND** as análises contam esses vínculos como A

#### Scenario: Tratamento noturno
- **WHEN** o tratamento de palavras-chave encontra duas palavras com a mesma forma normalizada
- **THEN** a segunda passa a ser agrupada na primeira sem que nenhum vínculo ou palavra-chave seja apagado

### Requirement: Desfazer um agrupamento
A tela de administração (agrupamentos globais) e a tela de normalização do projeto (agrupamentos do projeto) SHALL listar os agrupamentos gravados e permitir desfazer cada um, o que SHALL apagar a linha de mapeamento (ou restaurar o `keyword_concept_id` da palavra à própria palavra) e recalcular a identidade efetiva dos vínculos afetados.

#### Scenario: Desfazer uma mesclagem global
- **WHEN** o administrador desfaz a mesclagem de B em A
- **THEN** as análises voltam a contar os vínculos de B como B

### Requirement: Cópia de projeto leva os agrupamentos do projeto
A cópia de um projeto SHALL copiar os mapeamentos de escopo `project` do projeto de origem para o projeto novo, de modo que os números da cópia sejam iguais aos do original.

#### Scenario: Copiar um projeto com exceções
- **WHEN** o administrador copia um projeto que tem 3 agrupamentos próprios para outro usuário
- **THEN** o projeto novo tem os mesmos 3 agrupamentos e os mesmos números

### Requirement: Análises leem a identidade efetiva
Relatórios, painel, redes, mapa e evolução temática, três campos, matriz, comparação de grupos e classificação SHALL agrupar pela identidade efetiva do vínculo e contar documentos distintos, de modo que dois vínculos do mesmo documento com a mesma identidade efetiva contem uma vez. Com o tesauro vazio e nenhuma mesclagem anterior, os números MUST ser iguais aos obtidos antes da mudança.

#### Scenario: Dois vínculos, mesma identidade efetiva
- **WHEN** um documento tem os autores "Silva J" e "Silva, João", mapeados para a mesma identidade
- **THEN** o documento conta uma vez para essa identidade

### Requirement: Identidade efetiva consistente
O sistema SHALL manter `effective_author_id` e `effective_keyword_id` iguais ao valor resolvido pelos mapeamentos: toda gravação em `keyword`, `thesaurus_concept` ou `authority_mapping` SHALL disparar o recálculo dos vínculos afetados, e o comando `app:authority:rebuild --check` SHALL listar divergências sem alterar dados.

#### Scenario: Conferência sem divergência
- **WHEN** `app:authority:rebuild --check` roda depois de uma mesclagem administrativa
- **THEN** informa zero divergências
