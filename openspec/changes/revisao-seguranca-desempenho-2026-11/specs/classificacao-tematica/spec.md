## ADDED Requirements

### Requirement: Gravação atômica dos resultados
A execução da classificação SHALL apagar os resultados anteriores e gravar os novos numa única transação: uma falha em qualquer ponto SHALL deixar os resultados e as movimentações manuais anteriores intactos.

#### Scenario: Falha no meio da gravação
- **WHEN** a gravação dos resultados novos falha depois de parte das linhas
- **THEN** o projeto continua com todos os resultados da execução anterior

### Requirement: Leitura por partes
A classificação SHALL ler os documentos do projeto em blocos, sem manter título, resumo e palavras-chave de todos os documentos na memória, e o resultado MUST ser o mesmo da leitura de uma vez.

#### Scenario: Projeto de 50 mil documentos
- **WHEN** a classificação do projeto 9 (49.992 documentos) é calculada pela linha de comando
- **THEN** termina dentro do limite de 256 MB de memória

### Requirement: Cor e ícone do grupo
A cor do grupo SHALL ser um hexadecimal de seis dígitos e o ícone um nome `bi-…`; no formulário e na importação de grupos por CSV, valor fora do padrão SHALL ser trocado pelo padrão.

#### Scenario: CSV com cor inválida
- **WHEN** o CSV de grupos traz `red; background:url(x)` na cor
- **THEN** o grupo é criado com a cor padrão
