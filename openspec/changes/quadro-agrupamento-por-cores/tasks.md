# Tasks: quadro-agrupamento-por-cores

- [ ] 1. Adicionar campo `classificationColorLabels` (JSON) em `BibliometricProject` e criar migration `Version20261119020000.php`.
- [ ] 2. Implementar agrupamento por cor em `ClassificationController::index()` e rota `POST /projects/{id}/classification/color-labels` com verificação `edit` + CSRF.
- [ ] 3. Adicionar as guias **Lista de Grupos** e **Organização por Cores** em `templates/classification/index.html.twig`, com edição inline do título da cor (para o dono) e exportação em SVG e PNG.
- [ ] 4. Adicionar entrada de ajuda `classification.index.color_board` em `config/help/classification.yaml`.
- [ ] 5. Escrever testes unitários/funcionais para o agrupamento por cor e validação dos rótulos, e rodar `php bin/phpunit` completo.
- [ ] 6. Em produção: fazer backup (`mysqldump`) antes da migration, dar `git pull --ff-only`, rodar `./build.sh --prod` e `doctrine:migrations:migrate --no-interaction`.
- [ ] 7. Ajustar os 31 grupos temáticos do Projeto 22 para 4 cores (uma por macrodimensão) e gravar os 4 títulos das macrodimensões em `classification_color_labels`, conferindo o resultado na interface.
- [ ] 8. Sincronizar a spec `openspec/specs/classificacao-tematica/spec.md`, arquivar a change e fazer commit + push.
