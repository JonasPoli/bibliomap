## MODIFIED Requirements

### Requirement: Remapeamento pelas variações
No enriquecimento do projeto, o sistema SHALL agrupar cada `document_keyword`, pela forma normalizada do `original_term`, na palavra-chave oficial (status 1) ou na palavra-chave de uma variação ativa em `palavra_chave_variacoes_nome`, do mesmo tipo, gravando o agrupamento e recalculando a identidade efetiva do vínculo. O enriquecimento MUST NOT alterar o id bruto do vínculo nem apagar vínculos repetidos no mesmo documento.

#### Scenario: Variação cadastrada depois da importação
- **WHEN** o administrador cadastra "IA" como variação de "inteligência artificial" e o projeto é sincronizado
- **THEN** os documentos com a palavra-chave "IA" passam a contar como "inteligência artificial" nas análises
- **AND** o vínculo continua com a palavra-chave "IA" e o `original_term` original
