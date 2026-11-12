# seguranca-da-aplicacao Specification

## Purpose
TBD - created by archiving change revisao-seguranca-desempenho-2026-11. Update Purpose after archive.

## Requirements

### Requirement: Administrador criado sem credencial padrão
O comando `app:admin:create` SHALL exigir o e-mail e uma senha de pelo menos 10 caracteres, informada por argumento ou digitada no terminal, e MUST NOT ter e-mail nem senha padrão. Para um usuário que já existe, SHALL mudar a senha só com `--reset-password`.

#### Scenario: Comando sem argumentos
- **WHEN** o comando roda sem e-mail
- **THEN** termina com erro e nenhum usuário é criado ou alterado

#### Scenario: Usuário existente
- **WHEN** o comando roda para um e-mail já cadastrado, sem `--reset-password`
- **THEN** o usuário é promovido e ativado, e a senha continua a mesma

### Requirement: Texto vindo de dados nunca vira HTML
Todo valor vindo do banco ou de arquivo importado (termos, títulos, nomes, cor e ícone de grupo, mensagens com nome de projeto ou de usuário) SHALL ser escapado antes de entrar em HTML montado no navegador ou no servidor. A cor do grupo SHALL aceitar só hexadecimal e o ícone só nomes `bi-…`; valor fora do padrão SHALL cair no padrão.

#### Scenario: Palavra-chave com marcação
- **WHEN** existe uma palavra-chave `<img src=x onerror=alert(1)>` e o usuário passa o mouse sobre ela no mapa de árvore do relatório
- **THEN** o texto aparece literal e nenhum script executa

#### Scenario: Cor inválida no grupo
- **WHEN** um grupo é salvo com a cor `"><img src=x onerror=alert(1)>`
- **THEN** o grupo fica com a cor padrão

### Requirement: Imagem de divulgação validada
O envio da imagem de divulgação do site SHALL aceitar só JPG, PNG e WebP reconhecidos pelo conteúdo, com até 5 MB, gravando com a extensão do tipo reconhecido. Arquivos dentro de `/uploads` MUST NOT ser executados como PHP.

#### Scenario: Arquivo PHP com nome de imagem
- **WHEN** o administrador envia um arquivo com conteúdo PHP chamado `foto.jpg`
- **THEN** o envio é recusado e nada é gravado

### Requirement: Login e sessão
O login SHALL bloquear temporariamente depois de 5 tentativas erradas em 1 minuto. As mensagens de cadastro pendente e de conta desativada SHALL aparecer só depois da senha conferida. A sessão de um usuário que deixa de estar ativo SHALL ser encerrada na requisição seguinte. Sair SHALL ser um POST com token.

#### Scenario: Sexta tentativa
- **WHEN** alguém erra a senha 5 vezes em 1 minuto e tenta de novo
- **THEN** a tela mostra, em português, que há tentativas demais e não confere a senha

#### Scenario: Usuário desativado com sessão aberta
- **WHEN** um administrador desativa um usuário que está logado
- **THEN** a próxima página que esse usuário abrir leva ao login

### Requirement: Perfil exige a senha atual
A tela de perfil SHALL exigir a senha atual para mudar o e-mail ou a senha e SHALL validar o token do formulário. A senha nova SHALL ter pelo menos 10 caracteres, a mesma regra do cadastro.

#### Scenario: Troca de e-mail sem senha atual
- **WHEN** o usuário muda o e-mail e deixa a senha atual em branco
- **THEN** a alteração é recusada com a mensagem de que a senha atual é obrigatória
