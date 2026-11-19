# Tasks

## 1. Mapas sem a camada de azulejos (mapas-dos-relatorios)

- [x] 1.1 Em `templates/report/countries.html.twig`, remover o `tileUrl` do CARTO e os três `L.tileLayer`, pintar o fundo do contêiner com a cor do cartão no tema atual e enquadrar os mapas de países com os limites `[[-58,-170],[78,190]]`. Verificar no navegador local, nos dois temas, que os três mapas aparecem inteiros e que a aba Rede não tem nenhuma requisição a `basemaps.cartocdn.com`.
- [x] 1.2 Em `templates/report/geography_report.html.twig`, remover a camada dos mapas de regiões e de cidades, enquadrar o de regiões pelos limites da camada de estados, desenhar o contorno dos países sob os marcadores de cidades e redesenhar os dois mapas no evento `bm-theme-changed`. Verificar no navegador local que o Brasil aparece inteiro e que a troca de tema muda as cores do mapa.
- [x] 1.3 Revisar em `config/help/reports_topics.yaml` as entradas `report.countries.continents`, `map_linear`, `map_log`, `report.geography.regions_map` e `cities_map` e atualizar as que citam o fundo do mapa. Verificar com `vendor/bin/phpunit tests/Twig/HelpCatalogTest.php`.
- [x] 1.4 Criar um teste que lê os templates de `templates/report/` e falha se `cartocdn` ou `L.tileLayer(` voltarem. Verificar que ele passa agora e falha ao recolocar uma linha com `L.tileLayer(`.

## 2. Catálogo da vitrine

- [x] 2.1 Extrair o `format()` do `HelpExtension` para `App\Text\InlineFormatter` (escape mais `**negrito**`) e fazer o `HelpExtension` usar o novo serviço. Verificar que o `HelpCatalogTest` passa sem mudança nos testes.
- [x] 2.2 Criar `App\Showcase\ShowcaseCatalog`, que lê `config/showcase/recursos.yaml`, mantém a ordem do arquivo, acrescenta largura e altura de cada imagem e formata os textos com o `InlineFormatter`. Verificar com um teste unitário sobre um YAML de fixture em `tests/fixtures/showcase/`: ordem, dimensões e escape de `<script>`.
- [x] 2.3 Mover os 46 prints de `openspec/changes/vitrine-de-recursos-na-home/prints/` para `public/images/recursos/` com `git mv`. Verificar que há 46 arquivos `.webp` em `public/images/recursos/` e nenhum na pasta da mudança.
- [x] 2.4 Escrever `config/showcase/recursos.yaml` com as 7 categorias e os 32 recursos do inventário (design, decisão 6). Cada recurso leva `descricao`, `onde`, `mostra`, `como`, `porque`, `exemplo`, `exporta` e as imagens com `legenda` e `alt`, seguindo as regras de texto da decisão 6, com números reais tirados dos prints. Verificar lendo cada texto ao lado do seu print: os números citados batem com a imagem.
- [x] 2.5 Criar o `ShowcaseCatalogTest` sobre o catálogo real: campos obrigatórios preenchidos, `id` único, categoria existente, arquivo de imagem existente, WebP com no máximo 250 KB, sufixo `-claro` ou `-escuro`, claro e escuro presentes em toda categoria com mais de um recurso, e nenhum texto com `Sci-Hub`, `impersona` ou `@`. Verificar que `vendor/bin/phpunit` passa e que apagar uma imagem faz o teste falhar citando o recurso e o arquivo.

## 3. Seção Recursos na home (vitrine-de-recursos)

- [x] 3.1 Fazer o `HomeController` passar categorias, recursos e totais ao template, mantendo o redirecionamento de quem está logado. Verificar com teste funcional: `GET /` anônimo responde 200 com a seção `#recursos` e 32 elementos `id="recurso-…"`, e o usuário logado é redirecionado para `/projects`.
- [x] 3.2 Montar o HTML e o CSS da seção em `templates/home/index.html.twig`, com os tokens atuais da home: cabeçalho, barra de categorias fixa, títulos `h2`/`h3`/`h4`, linhas alternadas imagem/texto, moldura de janela, blocos "O que mostra", "Como usar", "Por que usar" e "Exemplo real", miniaturas das variações e chips de exportação. Verificar no navegador local, em 1440 px e em 360 px, que `document.documentElement.scrollWidth` é igual a `clientWidth` e que os prints claros e escuros ficam legíveis no fundo escuro.
- [x] 3.3 Escrever o JS da seção: filtro com `aria-pressed` e `hidden`, âncora `#recurso-…` que volta o filtro para "Todos", troca da imagem principal pelas miniaturas, `<dialog>` de ampliação com legenda e foco devolvido ao gatilho, animação de entrada desligada com `prefers-reduced-motion`. Verificar no navegador local os cenários da spec: filtro "Redes e temas", `/#recurso-lei-de-bradford` com outro filtro escolhido antes, Enter num print seguido de `Esc`, e a página com o JavaScript desligado mostrando todos os recursos.
- [x] 3.4 Ajustar o restante da home: link "Recursos" no menu, números do hero (total do catálogo, "8 bases", "100% Web") e texto das bases no cartão "Importação Inteligente" e no passo 2 de "Como funciona". Verificar visualmente e com o teste da 3.1, que confere o total vindo do catálogo.
- [x] 3.5 Conferir a acessibilidade e o desempenho da home local. Todas as imagens têm `alt`, `width` e `height`, e nenhuma imagem da seção é baixada antes de a seção se aproximar da tela. Lighthouse móvel: Acessibilidade ≥ 95, sem piora de mais de 10% no LCP em relação à home atual (medir antes de começar o grupo 3). Mostrar os números no relatório da tarefa.
  - Medido em 19/11/2026 (Lighthouse 13.6, celular, servidor local em modo prod com compressão, mediana de 3): antes desempenho 93, acessibilidade 92, LCP 3,14 s; depois desempenho 92, acessibilidade 100, LCP 3,18 s (+1,3%), 315 KiB contra 220 KiB (68 KiB são as 3 primeiras imagens da vitrine, antecipadas no celular). A acessibilidade subiu porque a cor de texto secundário passou de #64748b para #94a3b8 (contraste AA). No desktop de 1440 px nenhum print é baixado na primeira tela; no celular o Chrome antecipa as 3 primeiras imagens da vitrine (limite padrão do loading="lazy").
- [x] 3.6 Documentar no README, numa seção "Vitrine de recursos da home", como acrescentar um recurso (campos do YAML, nome e tamanho das imagens) e o roteiro de captura do design. Verificar que o texto cita `config/showcase/recursos.yaml`, `public/images/recursos/` e o `ShowcaseCatalogTest`. A home é pública e não tem ajuda "?", então não há entrada em `config/help`.

## 4. Publicação em produção

- [ ] 4.1 Rodar `vendor/bin/phpunit` (tudo verde), fazer commits por tema na `main` (mapas, catálogo e prints, home) e o `git push origin main`. Verificar com `git log origin/main` que os commits estão no remoto.
- [ ] 4.2 Backup antes do deploy (leitura): anotar o commit em produção (`git rev-parse HEAD` na raiz do webapp, como o usuário dono) para a volta atrás. Não há migration nem mudança de banco, por isso não é preciso dump. Verificar que o hash ficou registrado no relatório da tarefa.
- [ ] 4.3 Deploy (escrita): `git pull --ff-only origin main` e `./build.sh --prod`, como o usuário dono do webapp. Verificar que o build termina sem erro e que o commit em produção é o mesmo do `origin/main`.
- [ ] 4.4 Conferência depois do deploy (leitura): home deslogada em 1440 px e 360 px, as 46 imagens respondendo 200, o filtro, a âncora e a ampliação funcionando, e os relatórios de Países e Geográfico do projeto 22 sem "API KEY REQUIRED" nos dois temas e sem requisição ao `cartocdn`. Verificar com prints marcados anexados ao relatório.

## Workflow follow-up

- Abrir uma mudança para o erro 500 de `GET /projects/{id}/classification/compare/data` e, com ele corrigido, acrescentar o recurso "Comparação entre grupos" (sobreposição e Jaccard, perfil geográfico, institucional e Qualis) com prints novos.
- Abrir uma mudança para os documentos com ano inválido do projeto 22 (22, 26, 114…) e outra para o Glossário de projeto grande, que calcula por mais de 10 minutos e prende a sessão.
- Arquivar com `/opsx:archive` depois da conferência em produção.
