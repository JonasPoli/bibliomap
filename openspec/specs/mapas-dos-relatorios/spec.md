# mapas-dos-relatorios Specification

## Purpose
Define como os mapas dos relatórios de Países e Geográfico são desenhados: só com os dados do projeto sobre formas geográficas, sem depender de serviço de mapas que exija chave, legíveis nos temas claro e escuro.

## Requirements

### Requirement: Mapas sem serviço de azulejos de terceiros
Os mapas do relatório de Países (continentes, intensidade linear e escala logarítmica) e do Relatório Geográfico (regiões do Brasil e cidades) SHALL ser desenhados só com as formas geográficas e os marcadores do projeto, sobre o fundo do cartão. Eles MUST NOT carregar camada de azulejos de serviço que exija chave de acesso, e nenhuma marca d'água de serviço de mapas (como "API KEY REQUIRED") SHALL aparecer.

#### Scenario: Mapa de intensidade do projeto 22
- **WHEN** o relatório de Países do projeto 22 é aberto
- **THEN** o mapa mostra os países coloridos pela quantidade de documentos, sem nenhuma marca "API KEY REQUIRED" e sem requisição a `basemaps.cartocdn.com`

#### Scenario: Mapa de cidades sem fundo de azulejos
- **WHEN** o Relatório Geográfico de um projeto com cidades mapeadas é aberto
- **THEN** os marcadores das cidades aparecem sobre o contorno dos países, sem camada de azulejos

### Requirement: Enquadramento e temas
Ao abrir, cada mapa SHALL enquadrar a área que tem dados (o mundo nos mapas de países, o Brasil no mapa de regiões, as cidades no mapa de cidades), sem cortar parte dela. As formas sem dados, as bordas e o fundo SHALL usar cores legíveis no tema claro e no escuro, e trocar de tema SHALL redesenhar o mapa com as cores do novo tema.

#### Scenario: Mapa de regiões inteiro
- **WHEN** o mapa de produção por região do Brasil é aberto
- **THEN** o país aparece inteiro, do Norte ao Sul, sem precisar de zoom

#### Scenario: Troca de tema
- **WHEN** o usuário troca do tema claro para o escuro com o relatório de Países aberto
- **THEN** os mapas são redesenhados com fundo, bordas e países sem dados nas cores do tema escuro
