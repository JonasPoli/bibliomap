## ADDED Requirements

### Requirement: Resultado reaproveitado enquanto os dados não mudam
O sistema SHALL guardar o resultado de cada análise de projeto (painel, relatórios, redes, mapa e evolução temática, três campos, matriz e comparação de grupos) por projeto, análise e parâmetros, e SHALL devolver o resultado guardado enquanto a versão dos dados for a mesma. A verificação de acesso ao projeto MUST acontecer antes de qualquer leitura do resultado guardado.

#### Scenario: Segunda abertura do relatório
- **WHEN** o relatório geral do projeto 13 é aberto duas vezes seguidas sem nenhuma gravação entre elas
- **THEN** a segunda abertura mostra os mesmos números sem refazer as agregações

#### Scenario: Parâmetros diferentes
- **WHEN** a rede de palavras-chave é pedida com `limit=100` e depois com `limit=50`
- **THEN** cada pedido tem o seu resultado, calculado com o seu limite

### Requirement: Toda gravação invalida os resultados
A versão dos dados SHALL subir sempre que o sistema gravar, por qualquer caminho (tela, importação, comando ou rotina), em tabela que alimenta as análises, e um resultado calculado numa versão anterior MUST NOT ser devolvido. Gravações em tabelas que não mudam nenhuma análise (usuários, configurações do site, matrizes salvas, histórico do tratamento) MUST NOT subir a versão.

#### Scenario: Palavra-chave mesclada no administrador
- **WHEN** um administrador mescla duas palavras-chave e em seguida o dono de qualquer projeto abre o relatório de palavras-chave
- **THEN** o relatório é recalculado e mostra a mescla

#### Scenario: Login não invalida
- **WHEN** um usuário faz login (gravação do último acesso) e abre um relatório já calculado
- **THEN** o resultado guardado é usado

#### Scenario: Gravação durante o cálculo
- **WHEN** uma importação grava documentos enquanto um relatório do mesmo banco é calculado
- **THEN** esse resultado não é guardado

### Requirement: Falha do cache não aparece para o usuário
Quando a versão dos dados não puder ser lida ou o resultado não puder ser guardado, o sistema SHALL calcular e mostrar a análise normalmente, sem cache. Um resultado SHALL valer por no máximo 12 horas, e resultados vencidos SHALL ser apagados do disco sem ação manual.

#### Scenario: Tabela de versão ainda não criada
- **WHEN** o código novo está no ar e a migration da versão dos dados ainda não rodou
- **THEN** os relatórios abrem normalmente, calculados a cada acesso
