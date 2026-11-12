## ADDED Requirements

### Requirement: Registro sem DOI e sem ano também é deduplicado
Um registro sem DOI e sem título com ano SHALL ser identificado pela base e pelo identificador do registro na base (UT, EID, Lens ID, PMID) e, na falta deles, pelo título normalizado. A identificação dos registros que têm DOI ou título com ano MUST NOT mudar.

#### Scenario: Mesmo arquivo importado duas vezes
- **WHEN** um arquivo com um registro da Web of Science sem DOI e sem ano é importado duas vezes no mesmo projeto
- **THEN** o projeto tem um documento só para esse registro, e a segunda importação o conta como duplicado

### Requirement: Endereço do documento
O endereço do documento SHALL ser gravado só quando começar com `http://` ou `https://`; qualquer outro valor SHALL ser gravado como vazio.

#### Scenario: Endereço com outro esquema
- **WHEN** um CSV traz `javascript:alert(1)` na coluna do link
- **THEN** o documento é importado sem endereço

### Requirement: Sigla de duas letras no país
Uma sigla de duas letras no campo de país SHALL ser procurada primeiro no cadastro de países. SHALL ser tratada como estado dos Estados Unidos só quando for sigla de estado ou território dos EUA, e a importação MUST NOT criar estado a partir de uma sigla que não seja de estado dos EUA.

#### Scenario: Afiliação do Reino Unido
- **WHEN** a afiliação termina em "London, UK"
- **THEN** o documento é ligado ao Reino Unido e nenhum estado é criado

### Requirement: Enriquecimento por partes
O enriquecimento SHALL processar o projeto em blocos de documentos, trocando os vínculos de cada bloco numa transação, e MUST NOT apagar os vínculos nem o Qualis do projeto inteiro antes de recalcular.

#### Scenario: Processo interrompido
- **WHEN** o enriquecimento de um projeto de 50.000 documentos é interrompido na metade
- **THEN** os blocos já processados têm os vínculos novos e os demais continuam com os vínculos anteriores

### Requirement: Envio protegido por token
As rotas de envio e de confirmação da importação e a edição do dataset SHALL recusar requisição sem o token do formulário, e a base informada na confirmação SHALL ser uma das bases aceitas.

#### Scenario: Confirmação sem token
- **WHEN** a confirmação da importação chega sem token válido
- **THEN** nada é importado e o usuário volta à tela de importação com erro
