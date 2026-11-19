# Proposal

## Why

A home pública (`/`) descreve o BiblioMap em seis cartões genéricos ("Redes de Coautoria", "Análises Estatísticas"…) sem nenhuma imagem real, e parte do texto está desatualizada (fala só de Scopus e Web of Science, quando o sistema já importa oito bases). Quem chega pela primeira vez não vê o que a plataforma entrega de fato: são mais de 40 gráficos, mapas, redes e relatórios, e nenhum deles aparece antes do cadastro. Para mostrar o potencial do sistema a pesquisadores e orientadores, a home precisa de uma vitrine com cada recurso, explicado e com um print real.

## What Changes

- Nova seção **Recursos** na home pública, no mesmo visual escuro da página atual (gradiente azul/roxo/ciano, cartões `--card`, fonte Inter), com todos os recursos do BiblioMap agrupados por categoria. A primeira leva cobre os gráficos e relatórios: dashboard, produção anual, autores (Lotka, h/g), fontes (Bradford, Qualis), documentos mais citados, palavras-chave (barras, nuvem, treemap, tabela), evolução de palavras-chave (agrupado, empilhado, linhas), glossário, países e mapas, instituições, relatório geográfico, redes (coautoria, palavras-chave, países), mapa temático, evolução temática, três campos, matrizes, lentes teóricas, classificação temática (grupos, resultados, comparação e relatório) e relatório geral.
- Cada recurso tem **título**, **descrição curta**, um texto de **utilidade** (o que o gráfico permite ver, onde fica no sistema, como e por que usar) com **exemplo** concreto, e **pelo menos um print real**. Recursos com variações (modos de exibição, tipos de rede, tema claro e escuro) mostram uma imagem por variação.
- Os prints são capturas reais da produção, tiradas dos projetos 22 (Web of Science, 28 mil documentos), 1 (Web of Science, 498 documentos, com citações e anos limpos) e 4 (PubMed), alternando o tema **claro** e o **escuro** do sistema, recortados no cartão do gráfico, sem aviso de impersonação, e-mail ou nome de usuário.
- O conteúdo da vitrine vem de um **catálogo em YAML** (no mesmo espírito do `config/help`), com teste que exige os campos e confere se cada imagem existe. Novos tipos de recurso (importação, normalização, tesauros) entram depois só com novas entradas no catálogo.
- Navegação da seção: filtro por categoria, imagem ampliada ao clicar (com teclado e `Esc`), âncora por recurso e carregamento preguiçoso das imagens.
- Ajuste do texto que já está desatualizado na home: bases suportadas no hero e no cartão de importação.
- **Correção dos mapas** de Países e do Relatório Geográfico: o CARTO passou a exigir chave e hoje todos os mapas Leaflet aparecem cobertos pela marca "API KEY REQUIRED" (conferido em 19/11/2026 nos projetos 22 e 1). Os mapas passam a ser desenhados só com os polígonos e marcadores, sem camada de azulejos de terceiros. Os prints da vitrine já foram tirados assim.
- Fora do escopo, registrado como bug à parte: a comparação entre grupos temáticos (`/classification/compare/data`) responde erro 500 no projeto 22 para qualquer grupo. O recurso "Comparação entre grupos" entra na vitrine só depois da correção, porque não há print possível hoje.

## Capabilities

### New Capabilities
- `vitrine-de-recursos`: a seção pública da home que apresenta os recursos do BiblioMap a partir de um catálogo (conteúdo obrigatório de cada recurso, imagens reais sem dado pessoal, alternância de tema, navegação por categoria, ampliação de imagem, desempenho e responsividade).
- `mapas-dos-relatorios`: como os mapas dos relatórios de Países e Geográfico são desenhados (sem serviço de azulejos que exija chave, enquadramento da área com dados, aparência nos dois temas).

### Modified Capabilities
<!-- Nenhuma: a home continua pública e o redirecionamento de quem está logado continua igual (acesso-a-projetos). -->

## Impact

- `templates/home/index.html.twig` (nova seção, estilos e scripts no próprio arquivo, como hoje) e `src/Controller/HomeController.php` (passa o catálogo ao template).
- Novo `config/showcase/recursos.yaml` e um serviço que lê e valida o catálogo (com cache do Symfony), mais o teste do catálogo em `tests/`.
- Novas imagens WebP em `public/images/recursos/` (46 prints já capturados e convertidos: 1,8 MB no total, o maior com 96 KB, carregados sob demanda).
- `templates/report/countries.html.twig` e `templates/report/geography_report.html.twig` (camada de azulejos removida dos cinco mapas).
- Nenhuma mudança em banco, rota, permissão ou cálculo bibliométrico. A home continua sem login e sem consulta ao banco por recurso.
- O repositório é público: os prints só mostram dados bibliográficos públicos (títulos, autores de artigos, periódicos, termos), nunca dados de usuários do sistema.
