## MODIFIED Requirements

### Requirement: Aplicação no tratamento de palavras-chave
No modo `execute`, o tratamento de palavras-chave SHALL ligar a palavra ao conceito e registrar um `ThesaurusMatch` `automatic` nas correspondências exatas e automáticas, e registrar `pending`, sem ligar, nas que pedem revisão. A simulação (`dry-run`) MUST NOT gravar vínculo nem pendência. Uma sugestão de revisão MUST NOT ser registrada nem contada de novo quando a palavra já tem, para o mesmo conceito, uma sugestão pendente ou recusada.

#### Scenario: Simulação
- **WHEN** o super administrador roda o tratamento em modo simulação
- **THEN** o relatório mostra o que seria ligado e nenhuma palavra-chave muda

#### Scenario: Sugestão ainda pendente
- **WHEN** "behaviour change" tem uma sugestão pendente para o conceito "Behavior change" e o tratamento roda de novo em modo Real
- **THEN** a tela de revisão continua com uma única sugestão para esse par

#### Scenario: Sugestão recusada
- **WHEN** o super administrador recusou a sugestão "education" → "Educational" e o tratamento roda de novo
- **THEN** a sugestão não volta para a tela de revisão

### Requirement: Criação automática de conceitos
A opção `--auto-create-concepts` (parâmetro `auto_create_concepts` do formulário, que a tela não exibe) SHALL ser aceita sem efeito e descrita como não implementada na ajuda do comando: o tratamento MUST NOT criar conceitos no tesauro.

#### Scenario: Comando do cron
- **WHEN** a rotina noturna roda `app:keywords:treat --execute`, sem a opção, ou alguém roda `app:keywords:treat --execute --auto-create-concepts`
- **THEN** nenhuma linha nova aparece em `thesaurus_concept`

#### Scenario: Ajuda do comando
- **WHEN** alguém roda `app:keywords:treat --help`
- **THEN** a descrição de `--auto-create-concepts` diz que a opção não está implementada e não cria conceitos

## ADDED Requirements

### Requirement: Invalidação e alerta registrados uma vez
O tratamento MUST NOT invalidar de novo, contar nem registrar como inválida a palavra-chave que já tem gravado o mesmo motivo de invalidação, esteja ela pendente ou aceita depois por um administrador. O alerta de suspeito SHALL ser contado e registrado só na primeira vez que um processamento Real o encontra com o mesmo motivo.

#### Scenario: Termo aceito na Fila de Revisão
- **WHEN** um administrador aceitou na Fila de Revisão um termo invalidado como `too_long` e o tratamento roda de novo em modo Real
- **THEN** o termo continua ativo
- **AND** não aparece entre os inválidos do novo relatório

#### Scenario: Suspeito já registrado
- **WHEN** "COVID-19" já foi registrado como suspeito (`contains_number`) num processamento Real anterior
- **THEN** o novo processamento não o conta nem o registra como suspeito

### Requirement: Um tratamento por vez
O tratamento de palavras-chave, pela tela ou pelo comando, SHALL rodar um de cada vez e, no modo `execute`, MUST NOT começar enquanto algum dataset estiver com status `importing`. Quando não puder começar, SHALL terminar sem criar processamento nem alterar palavras-chave, informando o motivo.

#### Scenario: Dois tratamentos ao mesmo tempo
- **WHEN** o comando começa enquanto um tratamento iniciado pela tela ainda roda
- **THEN** o comando termina com "Outro tratamento de palavras-chave está em andamento."
- **AND** nenhum processamento novo aparece no histórico

#### Scenario: Importação em andamento
- **WHEN** um dataset está com status `importing` e o super administrador inicia um tratamento em modo Real pela tela
- **THEN** a tela mostra "Importação em andamento: tratamento adiado."
- **AND** nenhuma palavra-chave muda
