# Spec Delta

## MODIFIED Requirements

### Requirement: Prévia antes de importar um arquivo
Com um único arquivo, o sistema SHALL detectar a base pela pontuação de cada leitor (e, em CSV, pelas colunas-assinatura das bases cadastradas), mostrar até 10 registros e o total de registros do arquivo, e só criar o dataset, com status pendente, quando o usuário confirmar. Um arquivo no formato de marcações da Web of Science gerado por outra base MUST NOT ser identificado como Web of Science.

#### Scenario: Exportação da Web of Science
- **WHEN** o dono envia um TXT que começa com o cabeçalho "Clarivate Analytics Web of Science"
- **THEN** a prévia indica Web of Science e o total de registros do arquivo
- **AND** a importação só começa depois de "Confirmar"

#### Scenario: TXT do OpenAlex no formato da Web of Science
- **WHEN** o dono envia um TXT que começa com `FN OpenAlex`
- **THEN** a prévia indica OpenAlex, e não Web of Science
