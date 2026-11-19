## ADDED Requirements

### Requirement: Organização visual dos grupos temáticos por cor
A tela `/projects/{id}/classification` SHALL oferecer uma guia "Organização por Cores" que agrupa os grupos do tipo `normal` pela cor configurada (em minúsculas), ordenando os blocos pela menor `position` de seus grupos e exibindo os grupos de cada cor em ordem de `position` com a contagem de documentos quando houver classificação executada. Grupos dos tipos `validator`, `noise` e `unclassified` MUST NOT aparecer nesse quadro.

#### Scenario: Grupos temáticos agrupados em 4 blocos de cor
- **WHEN** um projeto tem 31 grupos temáticos distribuídos em 4 cores distintas e 14 grupos de ruído
- **THEN** a guia "Organização por Cores" exibe exatamente 4 blocos, cada um listando os grupos temáticos da sua cor

### Requirement: Rótulo editável por cor no projeto
O dono do projeto (`edit` no `ProjectVoter`) SHALL poder salvar ou limpar um título personalizado para cada cor via `POST /projects/{id}/classification/color-labels` com token CSRF válido, persistido em `bibliometric_project.classification_color_labels`. Usuários sem permissão `edit` MUST receber 403 ao tentar salvar rótulos e ver apenas o título em modo leitura.

#### Scenario: Dono nomeia um agrupamento de cor
- **WHEN** o dono envia `color=#2563eb` e `label="1. Inteligência aplicada aos processos produtivos"` com CSRF válido
- **THEN** o título é salvo no projeto e aparece no cabeçalho do bloco correspondente

#### Scenario: Visitante de projeto público tenta alterar rótulo
- **WHEN** outro usuário logado envia `POST /projects/{id}/classification/color-labels` em um projeto público
- **THEN** o sistema responde 403

### Requirement: Exportação do quadro por cores em SVG e PNG
A guia "Organização por Cores" SHALL oferecer botões para baixar o quadro em SVG e em PNG diretamente no navegador, refletindo os títulos das cores e a lista de grupos exibidos na tela.

#### Scenario: Exportação do diagrama
- **WHEN** o usuário clica em "Exportar SVG" ou "Exportar PNG"
- **THEN** o navegador baixa o arquivo com os blocos agrupados por cor e seus respectivos grupos temáticos
