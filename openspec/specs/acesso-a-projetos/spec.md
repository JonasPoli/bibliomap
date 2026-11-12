# acesso-a-projetos Specification

## Purpose
Controla quem entra no BiblioMap e quem vê ou altera cada projeto bibliométrico: cadastro com aprovação de um administrador, projetos privados ou públicos e escrita só do dono. Implementado em `config/packages/security.yaml`, `UserChecker`, `ProjectVoter`, `SecurityController`, `ProjectController`, `AdminController` e `AdminProjectController`; as telas de análise pedem `view` e as de alteração pedem `edit` ao voter.

## Requirements

### Requirement: Cadastro pendente de aprovação
O cadastro em `/register` SHALL criar o usuário com `ROLE_USER` e status `pending` e levar para `/cadastro-pendente`. O login de usuário `pending` ou `inactive` MUST ser recusado com a mensagem do status.

#### Scenario: Login antes da aprovação
- **WHEN** um usuário recém-cadastrado tenta entrar
- **THEN** vê "Sua conta está aguardando aprovação do administrador. Você será notificado quando o acesso for liberado."

#### Scenario: Aprovação pelo administrador
- **WHEN** um administrador aprova o usuário em `/admin/usuarios`
- **THEN** o status passa a `active` e o login é aceito

### Requirement: Áreas por papel
Toda página SHALL exigir login, exceto `/login`, `/register`, `/cadastro-pendente` e a página inicial `/`. As páginas `/admin` SHALL exigir `ROLE_ADMIN`, e o tratamento de palavras-chave em `/admin/super/keywords/treatment` SHALL exigir `ROLE_SUPER_ADMIN`.

#### Scenario: Visitante sem login
- **WHEN** alguém sem sessão abre `/projects`
- **THEN** é levado para `/login`

### Requirement: Permissões do projeto
O `ProjectVoter` SHALL dar `view` ao dono e, quando a visibilidade é `public`, a qualquer usuário logado; `edit` e `delete` SHALL ser só do dono. O papel de administrador MUST NOT dar acesso a projetos de outros pelo voter.

#### Scenario: Projeto público aberto por outro usuário
- **WHEN** um usuário logado abre o link de um projeto público de outra pessoa
- **THEN** o painel, os relatórios, as redes, os mapas e os resultados da classificação abrem
- **AND** importar, normalizar, editar, mover documentos e executar a classificação devolvem 403

#### Scenario: Projeto privado de outro usuário
- **WHEN** um usuário logado abre o link de um projeto privado de outra pessoa
- **THEN** recebe 403

### Requirement: Lista de projetos
A página `/projects` SHALL listar só os projetos do usuário logado que não estão arquivados, do mais recente ao mais antigo; os arquivados SHALL aparecer só com `?archived=1`. Projetos públicos de outros MUST NOT aparecer na lista.

#### Scenario: Projeto arquivado
- **WHEN** o dono arquiva um projeto
- **THEN** ele sai da lista principal e aparece em "arquivados"

### Requirement: Arquivar e reativar
O dono SHALL poder arquivar um projeto (status `archived`) e reativá-lo; ao reativar, o status SHALL voltar a `ready` se o projeto tem documentos e a `draft` se não tem.

#### Scenario: Reativar projeto sem documentos
- **WHEN** o dono reativa um projeto arquivado sem documentos
- **THEN** o projeto volta com status `draft`

### Requirement: Exclusão do projeto
A exclusão, só do dono e com token CSRF, SHALL apagar numa transação os registros de `dataset_skip`, `document_author`, `document_keyword`, os documentos, os datasets e o projeto, e depois apagar os arquivos enviados, os logs `var/log/import_{dataset}.log` e a pasta `var/uploads/{id}`.

#### Scenario: Erro no banco durante a exclusão
- **WHEN** uma das exclusões falha
- **THEN** a transação é desfeita, nenhum arquivo é apagado e o dono volta para a edição com a mensagem do erro

### Requirement: Administração de projetos de outros
O administrador SHALL ver os projetos de um usuário em `/admin/projetos/usuario/{id}`, copiar um projeto para outro usuário ativo e entrar como outro usuário (impersonação), o que só é permitido para usuários ativos.

#### Scenario: Impersonar usuário pendente
- **WHEN** o administrador tenta entrar como um usuário pendente ou inativo
- **THEN** vê "Não é possível impersonar um usuário inativo ou pendente." e continua na lista de usuários

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
