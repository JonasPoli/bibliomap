# Spec Delta

## ADDED Requirements

### Requirement: CSV lido sem caractere de escape
Os leitores de CSV SHALL ler o arquivo como CSV padrão, em que aspas dentro de uma célula vêm dobradas e a barra invertida é um caractere comum. Uma barra invertida antes de uma aspa MUST NOT deslocar as colunas seguintes nem juntar dois registros num só.

#### Scenario: Texto com barra invertida antes da aspa
- **WHEN** um CSV do Scopus traz no texto de financiamento `iniciativas como \""LocoBoar\"" (financiado, código 16KN)`
- **THEN** o EID, o idioma e as demais colunas do registro são lidos nas colunas certas

#### Scenario: Célula terminada em barra invertida
- **WHEN** uma célula termina com uma barra invertida
- **THEN** o registro seguinte é lido como um registro separado, e a contagem de registros não diminui
