## ADDED Requirements

### Requirement: Tesauro pessoal do usuário
O usuário SHALL poder cadastrar agrupamentos de autores e de palavras-chave no seu tesauro pessoal (escopo `user`), que valem para todos os projetos dele, prevalecem sobre o tesauro global e perdem para o agrupamento do próprio projeto. O usuário MUST NOT alterar o tesauro global.

#### Scenario: Agrupamento pessoal em dois projetos
- **WHEN** um usuário agrupa "Silva J" em "Silva, José" no seu tesauro pessoal
- **THEN** os dois projetos dele contam "Silva J" como "Silva, José"
- **AND** os projetos de outros usuários não mudam
