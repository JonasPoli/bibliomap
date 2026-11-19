# Design: Quadro de organização por cores

## Modelo de dados
- Nova coluna anulável `classification_color_labels` (`JSON`, padrão `NULL`) em `bibliometric_project` via migration Doctrine (`Version20261119020000.php`).
- Mapeia cor hexadecimal em minúsculas (ex.: `#2563eb`) para o título definido pelo dono do projeto (string até 255 caracteres).
- A escrita de `classification_color_labels` ocorre apenas na tabela `bibliometric_project` do próprio projeto; não altera tabelas globais de autoridade.

## Controller e rotas
- `GET /projects/{id}/classification`: continua passando `groups`, `counts`, `hasResult` e passa também `colorGroups` (grupos `type = 'normal'` agrupados por `strtolower($group->getColor())`, na ordem da menor `position` de cada cor, com total de documentos por grupo e soma/indicador do bloco) e `canEdit` (`$this->isGranted('edit', $project)`).
- `POST /projects/{id}/classification/color-labels` (`app_classification_color_labels`): exige permissão `edit` no `ProjectVoter` e CSRF token `clf_color_labels_{id}`. Recebe `color` (hex `#rrggbb`) e `label` (string; vazio remove o rótulo personalizado) e retorna JSON `{ok: true, color, label}`.

## Interface e exportação
- Na página `templates/classification/index.html.twig`, logo abaixo dos cards de estatísticas, guias Bootstrap (`nav-tabs`):
  - **Lista de Grupos** (ativa por padrão, preservando o layout 8/4 atual).
  - **Organização por Cores** (ativa também via `#cores` na URL), exibindo grade de 2 colunas (`row-cols-1 row-cols-md-2 g-4`) com um card por cor no estilo da Figura 6 (borda fina na cor do bloco, cabeçalho colorido suave com título centralizado e edição inline para o dono, corpo listando os grupos da cor com contagem de documentos e link para `/results?group={id}` quando houver resultado).
  - Botões **Exportar SVG** e **Exportar PNG** (2× retina, fundo branco para publicação) gerando o diagrama vetorial limpo diretamente em JS, fiel ao layout dos cards.
- Nova entrada de ajuda `classification.index.color_board` em `config/help/classification.yaml` (com `title`, `what`, `how`, `not`, `example` exigidos pelo `HelpCatalogTest`).

## Produção (leitura vs. escrita)
- **Escrita em produção:** backup (`mysqldump`) antes da migration; `git pull --ff-only origin main`; `./build.sh --prod`; `doctrine:migrations:migrate --no-interaction`; atualização das 4 cores dos 31 grupos temáticos e dos 4 rótulos de cor no Projeto 22.
- **Conferência pós-deploy:** verificar HTTP 200 na página `/projects/22/classification`, existência da coluna e contagens intactas (10.456 documentos).
