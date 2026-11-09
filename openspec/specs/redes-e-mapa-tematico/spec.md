# redes-e-mapa-tematico Specification

## Purpose
Calcula as redes de coocorrência (coautoria, palavras-chave e colaboração entre países) e o mapa temático (diagrama estratégico de Callon), com o mesmo método do `thematicMap()` do Bibliometrix. O mapa de cada período da Evolução Temática usa o mesmo cálculo. Implementado em `NetworkService`, `ThematicMapService`, `ThematicEvolutionService`, `NetworkController` e `ThematicMapController`; as rotas exigem a permissão `view` do projeto.

## Requirements
### Requirement: Coocorrência conta documentos
O peso da ligação entre dois itens SHALL ser o número de documentos distintos do projeto em que os dois aparecem; o par de um item com ele mesmo SHALL ser ignorado. Nas palavras-chave, os itens SHALL ser os conceitos efetivos do tesauro.

#### Scenario: Palavras repetidas no mesmo documento
- **WHEN** duas palavras-chave aparecem juntas em 5 documentos, uma delas duas vezes em um deles
- **THEN** a ligação entre elas tem peso 5

### Requirement: Parâmetros das redes
A rota `/projects/{id}/networks/data/{type}` SHALL aceitar `coauthorship`, `keywords` (com `kwType` `author` ou `indexed`) e `countries`. SHALL manter os `limit` nós mais frequentes (entre 10 e 300, padrão 100), só as ligações entre eles com peso de pelo menos `minWeight` (padrão 1); outro tipo SHALL devolver 404.

#### Scenario: Rede de palavras-chave indexadas
- **WHEN** a tela pede `keywords` com `kwType=indexed` e `limit=50`
- **THEN** a rede traz até 50 Keywords Plus, as mais frequentes, e as ligações entre elas

### Requirement: Projetos grandes
Para projetos com mais de 35.000 documentos, a rota das redes SHALL devolver 400 com `project_too_large` e a mensagem de que a rede interativa está desabilitada, sem calcular nada.

#### Scenario: Corpus de 40 mil documentos
- **WHEN** a rede de coautoria é pedida num projeto com 40.000 documentos
- **THEN** a resposta é 400 com `error` igual a `project_too_large`

### Requirement: Rede de países
A rede de países SHALL usar os países sincronizados dos documentos (`documento_paises` ligado a `paises.common_name`), contando cada país uma vez por documento.

#### Scenario: Documento com três autores do Brasil e um da Espanha
- **WHEN** o documento tem afiliações sincronizadas com Brasil (três vezes) e Espanha
- **THEN** o Brasil conta um documento e a ligação Brasil–Espanha ganha peso 1

### Requirement: Nós do mapa temático
O mapa temático SHALL usar os `limit` conceitos efetivos mais frequentes do tipo escolhido (entre 10 e 300, padrão 100) com pelo menos `minOccur` documentos (padrão 2, mínimo 1). O mínimo SHALL valer só para os nós: qualquer coocorrência de pelo menos 1 documento entre eles vira ligação.

#### Scenario: Termo citado em um só documento
- **WHEN** `minOccur` é 2 e uma palavra-chave aparece em um único documento
- **THEN** ela fica fora do mapa

### Requirement: Temas por força de associação
O peso de cada ligação no mapa SHALL ser a força de associação `c_ij / (c_i × c_j)`. Nós sem ligação SHALL ser removidos, e os temas SHALL ser as comunidades do Louvain (resolução 1, 10 execuções com semente fixa 42, fica a de maior modularidade).

#### Scenario: Mesmo pedido duas vezes
- **WHEN** o mapa do mesmo projeto é pedido duas vezes com os mesmos parâmetros
- **THEN** os temas saem iguais nas duas vezes

### Requirement: Centralidade e densidade de Callon
Para cada tema, a centralidade SHALL ser a soma dos pesos das ligações com termos de outros temas (a ligação externa conta para os dois temas) e a densidade SHALL ser 100 × a soma dos pesos internos ÷ o número de termos. O nome do tema SHALL ser o termo mais frequente, e a resposta SHALL trazer as médias e as medianas, que dividem os quadrantes.

#### Scenario: Tema sem ligação externa
- **WHEN** todos os termos de um tema só se ligam entre si
- **THEN** a centralidade do tema é 0

### Requirement: Recorte por período
Com ano inicial, ano final ou os dois, o mapa temático SHALL usar só os documentos publicados no intervalo, incluindo os limites. A Evolução Temática SHALL montar dois mapas a partir de um ano de corte: P1 até o corte e P2 do ano seguinte em diante.

#### Scenario: Corte em 2019
- **WHEN** a Evolução Temática é pedida com corte em 2019
- **THEN** o mapa P1 usa os documentos até 2019 e o mapa P2 os de 2020 em diante
