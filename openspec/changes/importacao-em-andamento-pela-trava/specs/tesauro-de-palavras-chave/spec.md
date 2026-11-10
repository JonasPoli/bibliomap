## MODIFIED Requirements

### Requirement: Um tratamento por vez
O tratamento de palavras-chave, pela tela ou pelo comando, SHALL rodar um de cada vez e, no modo `execute`, MUST NOT começar enquanto uma importação estiver em andamento, ou seja, enquanto algum dataset com status `importing` tiver a trava da sua importação ocupada. Quando não puder começar, SHALL terminar sem criar processamento nem alterar palavras-chave, informando o motivo. Um dataset com status `importing` cuja importação não está mais rodando (trava livre) MUST NOT impedir o tratamento, e o tratamento SHALL avisar o ID desse dataset sem alterar o seu status.

#### Scenario: Dois tratamentos ao mesmo tempo
- **WHEN** o comando começa enquanto um tratamento iniciado pela tela ainda roda
- **THEN** o comando termina com "Outro tratamento de palavras-chave está em andamento."
- **AND** nenhum processamento novo aparece no histórico

#### Scenario: Importação em andamento
- **WHEN** um dataset está sendo importado (status `importing` e trava da importação ocupada) e o super administrador inicia um tratamento em modo Real pela tela
- **THEN** a tela mostra "Importação em andamento: tratamento adiado."
- **AND** nenhuma palavra-chave muda

#### Scenario: Importação interrompida
- **WHEN** o dataset #11 está com status `importing`, mas nenhum processo o importa (trava livre), e o tratamento começa em modo Real
- **THEN** o tratamento roda normalmente
- **AND** avisa que o dataset #11 está com status `importing` sem importação em andamento
- **AND** o status do dataset #11 continua `importing`
