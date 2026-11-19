# Tasks: quadro-agrupamento-por-cores

- [x] 1. Adicionar campo `classificationColorLabels` (JSON) em `BibliometricProject` e criar migration `Version20261119020000.php`.
- [x] 2. Implementar agrupamento por cor em `ClassificationController::index()` e rota `POST /projects/{id}/classification/color-labels` com verificação `edit` + CSRF.
- [x] 3. Adicionar as guias **Lista de Grupos** e **Organização por Cores** em `templates/classification/index.html.twig`, com edição inline do título da cor (para o dono) e exportação em SVG e PNG.
- [x] 4. Adicionar entrada de ajuda `classification.index.color_board` em `config/help/classification.yaml`.
- [x] 5. Escrever testes unitários/funcionais para o agrupamento por cor e validação dos rótulos, e rodar `php bin/phpunit` completo (392/392 OK).
- [x] 6. Em produção: fazer backup (`bibliomap-20261119-0519.sql.gz`, 138,4 MB) antes da migration, dar `git pull --ff-only`, rodar `./build.sh --prod` e `doctrine:migrations:migrate --no-interaction`.
- [x] 7. Ajustar os 31 grupos temáticos do Projeto 22 para 4 cores (uma por macrodimensão) e gravar os 4 títulos das macrodimensões em `classification_color_labels`, conferindo 10.456 documentos intactos.
- [x] 8. Sincronizar a spec `openspec/specs/classificacao-tematica/spec.md`, arquivar a change e fazer commit + push.
