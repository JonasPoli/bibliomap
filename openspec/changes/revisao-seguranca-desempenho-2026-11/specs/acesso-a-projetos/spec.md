## MODIFIED Requirements

### Requirement: Áreas por papel
Toda página SHALL exigir login, exceto `/login`, `/register`, `/cadastro-pendente` e a página inicial `/`. As páginas `/admin` SHALL exigir `ROLE_ADMIN`, e o tratamento de palavras-chave em `/admin/super/keywords/treatment` SHALL exigir `ROLE_SUPER_ADMIN`.

#### Scenario: Visitante sem login
- **WHEN** alguém sem sessão abre `/projects`
- **THEN** é levado para `/login`

## ADDED Requirements

### Requirement: Impersonação restrita
Um administrador SHALL poder entrar como um usuário comum ativo; só o super administrador SHALL poder entrar como outro administrador. Ninguém SHALL entrar como usuário pendente ou desativado, e a ação SHALL ser um POST com token.

#### Scenario: Administrador tenta virar super administrador
- **WHEN** um administrador comum acrescenta `?_switch_user=` com o e-mail do super administrador a uma URL
- **THEN** recebe acesso negado e continua como ele mesmo

### Requirement: Cópia só para usuário ativo
A cópia de um projeto para outro usuário SHALL ser recusada quando o destino não está ativo.

#### Scenario: Destino desativado
- **WHEN** o administrador copia um projeto para um usuário desativado
- **THEN** nenhuma cópia é criada e a tela informa que o usuário de destino não está ativo

### Requirement: Recálculo das lentes teóricas só pelo dono
O processamento e o recálculo do relatório de lentes teóricas SHALL exigir a permissão de alterar o projeto. Quem só vê um projeto público SHALL ver o resultado já calculado.

#### Scenario: Visitante de projeto público
- **WHEN** um usuário que não é o dono chama o recálculo das lentes de um projeto público
- **THEN** recebe acesso negado e o resultado guardado não muda

### Requirement: Cadastro de lente teórica só por administradores
A criação de lente teórica pela tela do relatório SHALL exigir `ROLE_ADMIN`, porque a tabela de lentes vale para todos os projetos; o dono de um projeto sem esse papel MUST NOT ver o botão "Nova Lente" nem conseguir chamar a rota.

#### Scenario: Dono de projeto sem papel de administrador
- **WHEN** o dono de um projeto chama `POST /projects/{id}/reports/theoretical-lenses/add`
- **THEN** recebe acesso negado e nenhuma lente é criada
