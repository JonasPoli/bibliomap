# Proposta: Quadro de organização por cores na Classificação Temática

## Motivação
Na tela de Classificação Temática (`/projects/{id}/classification`), os grupos temáticos aparecem apenas em lista linear. Quando o pesquisador organiza subcategorias em macrodimensões por meio da cor dos grupos (como a Figura 6 da dissertação — 4 macrodimensões reunindo 10 categorias e 31 subcategorias), não há uma visão estrutural agrupada por cor nem uma forma direta de nomear cada agrupamento de cor e exportar o diagrama para uso em relatórios e publicações.

## O que muda
1. Adicionar na tela `/projects/{id}/classification` duas guias: **Lista de Grupos** (atual) e **Organização por Cores** (novo quadro em grade de 2 colunas, com um card por cor distinta dos grupos temáticos `normal`).
2. Permitir ao dono do projeto nomear cada agrupamento de cor (`POST /projects/{id}/classification/color-labels`), salvando o mapa cor → título na coluna JSON `classification_color_labels` de `bibliometric_project`.
3. Exibir dentro de cada card de cor os grupos temáticos daquela cor (ordenados por `position`), com subagrupamento visual opcional por prefixo de categoria quando aplicável (ou lista limpa), contagem de documentos e link direto para os resultados filtrados.
4. Adicionar exportação do quadro em **SVG** e **PNG** (gerados no navegador a partir dos dados estruturados do quadro, em layout limpo pronto para dissertação/artigo).
5. Atualizar a ajuda contextual em `config/help/classification.yaml` e a spec `openspec/specs/classificacao-tematica/spec.md`.
6. No Projeto 22 em produção, ajustar os 31 grupos temáticos para 4 cores (uma por macrodimensão) e preencher os 4 títulos das macrodimensões.

## Escopo fora desta mudança
- Nenhuma alteração no motor de classificação (`ClassificationEngine`).
- Nenhuma alteração em tabelas de autoridade globais.
