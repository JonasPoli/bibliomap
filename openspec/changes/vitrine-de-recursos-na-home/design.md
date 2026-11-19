# Design

## Context

- A home pública é um template único, `templates/home/index.html.twig` (740 linhas), que não estende o `base.html.twig`: CSS e JS ficam no próprio arquivo, com tokens em `:root` (`--bg #060b14`, `--card #111827`, `--border #1e2d45`, `--gradient` azul → roxo → ciano), fonte Inter, Bootstrap Icons e largura útil de 1200 px (`padding: … max(24px, calc((100% - 1200px)/2))`). O `HomeController` redireciona quem está logado para `/projects` e só renderiza o template; `site_settings` chega como global do Twig.
- O sistema já tem um catálogo de textos em YAML, o da ajuda contextual (`config/help/*.yaml`, lido pelo `HelpExtension`, conferido pelo `HelpCatalogTest`), com formatação `**negrito**` escapada pelo método `format()`. A vitrine segue o mesmo padrão.
- Prints: capturados em 19/11/2026 na produção pelo Chrome do Jonas (janela de 1440 px, densidade 1×, por isso os arquivos têm de 1080 a 1280 px de largura), recortados no cartão de cada gráfico, nos temas claro e escuro do sistema. Ficam em `prints/` dentro desta mudança (46 arquivos WebP, 1,8 MB) até a implementação movê-los para `public/images/recursos/`.
- Durante a coleta (ação em produção, a maior parte só leitura):
  - **Escrita**: abrir as Lentes Teóricas dispara o mapeamento do projeto. O do projeto 1 terminou (498 documentos). O do projeto 22 foi interrompido por volta de 5% e retoma sozinho na próxima abertura do relatório.
  - Leitura: todas as outras telas. A matriz 15 × 15 do projeto 22 foi gerada e não foi salva.
  - O aviso de impersonação foi removido da página antes de cada captura, a camada de azulejos do CARTO foi escondida nos mapas (ver a decisão 7) e os botões "Sci-Hub" foram escondidos nos resultados da classificação e na busca.
- Problemas encontrados na produção que afetam a vitrine:
  - O CARTO devolve "API KEY REQUIRED" em todos os azulejos (`light_nolabels`, `dark_nolabels`, `light_all`, `dark_all`), então os cinco mapas Leaflet aparecem com a marca d'água.
  - `GET /projects/22/classification/compare/data` responde 500 para qualquer grupo (até um só), então o comparativo entre grupos não tem print.
  - O projeto 22 tem documentos com ano inválido (22, 26, 114…). A produção anual, a tabela de termos e a produção por grupo dele começam no ano 22. Por isso os gráficos de série anual da vitrine vêm do projeto 1.
  - O Glossário do projeto 22 ficou calculando por mais de 10 minutos e, como o PHP trava a sessão por requisição, prendeu todas as outras páginas da mesma sessão até um novo login.

## Goals / Non-Goals

**Goals:**
- Vitrine com 32 recursos em 7 categorias e 46 prints reais, no visual atual da home, legível do celular ao monitor largo.
- Conteúdo fora do template (catálogo), validado por teste, para que novos recursos entrem sem mexer em HTML.
- Mapas dos relatórios sem a marca d'água, já que os prints mostram como eles ficam depois da correção.

**Non-Goals:**
- Tela de administração para editar a vitrine (o catálogo é versionado e revisado como a ajuda).
- Página própria por recurso (`/recursos/{id}`), que mudaria a regra de rotas públicas de `acesso-a-projetos`. Fica para depois, se a home crescer demais.
- Corrigir o erro 500 do comparativo, os anos inválidos do projeto 22 ou a lentidão do Glossário. Cada um vai para uma mudança própria.
- Hospedar localmente os GeoJSON que os mapas buscam em `raw.githubusercontent.com` (continuam como hoje).
- Recapturar os prints automaticamente. A recaptura é manual, seguindo o roteiro em "Roteiro de captura".

## Decisions

### 1. Catálogo em `config/showcase/recursos.yaml` lido por um serviço
Estrutura:

```yaml
categorias:
  indicadores:
    titulo: "Indicadores e produção"
    descricao: "…"
    icone: "bi-speedometer2"
recursos:
  - id: lei-de-lotka
    categoria: autores-fontes
    titulo: "Lei de Lotka"
    descricao: "Frase curta que aparece em destaque."
    onde: "Análises › Autores"
    mostra: "O que o gráfico permite ver."
    como: "Como chegar e usar, passo a passo curto."
    porque: "Por que usar e o que decide com ele."
    exemplo: "Caso concreto, com números."
    exporta: [CSV, PNG]
    imagens:
      - arquivo: autores-ranking-lotka-escuro.webp
        legenda: "Ranking de autores e curva de Lotka, projeto com 28 mil documentos da Web of Science"
        alt: "Gráfico de barras dos dez autores mais produtivos ao lado da curva de Lotka…"
```

`App\Showcase\ShowcaseCatalog` lê o arquivo com `Symfony\Component\Yaml`, devolve categorias e recursos na ordem do arquivo, acrescenta `largura` e `altura` de cada imagem com `getimagesize()` e guarda o resultado em memória durante a requisição. Os textos passam pelo mesmo formatador da ajuda (escape mais `**negrito**`). Para isso, o `format()` do `HelpExtension` vira um pequeno serviço compartilhado, `App\Text\InlineFormatter`, sem mudar o resultado da ajuda.

Alternativas: textos no próprio Twig (mais rápido de escrever, mas mistura conteúdo e layout e não dá para testar campo a campo) e tabela no banco com tela de administração (mais trabalho, ninguém pediu edição pelo painel). A leitura do YAML e das 46 dimensões custa poucos milissegundos. A tarefa 6.3 mede o tempo de resposta da home antes e depois. Se passar de 20 ms, o resultado vai para o pool `cache.app`, com o `filemtime` do YAML na chave.

### 2. Imagens WebP com nome que diz o conteúdo e o tema
Arquivos em `public/images/recursos/<assunto>-<tema>.webp` (por exemplo, `evolucao-linhas-escuro.webp`), convertidos com `cwebp -q 82 -m 6` e sem redimensionar. O sufixo `-claro`/`-escuro` permite ao teste conferir a alternância de temas. O HTML declara `width`/`height`, usa `loading="lazy"` e `decoding="async"` e recebe `sizes` coerente com a coluna (a imagem não passa de 760 px na tela).

### 3. Layout: linhas de vitrine alternadas dentro de cada categoria
A seção `#recursos` entra entre "Funcionalidades" e "Como funciona", com:
- Cabeçalho no padrão das outras seções (sobrelinha "Recursos", título com `gradient-text`, subtítulo) e o total de recursos e de prints vindo do catálogo.
- Barra de categorias fixa ao rolar (`position: sticky` logo abaixo do menu compacto de 62 px), com chips "Todos" e as 7 categorias.
- Para cada categoria, um título com ícone e uma linha de descrição, seguidos dos recursos. Cada recurso é uma grade de duas colunas (imagem 7fr, texto 5fr) que alterna o lado a cada recurso. Abaixo de 900 px vira uma coluna, com a imagem em cima.
- Imagem principal numa moldura de "janela" (barra superior com três pontos e o rótulo "BiblioMap · <onde>"). A mesma moldura serve para prints claros e escuros e dá unidade ao conjunto. As variações aparecem como miniaturas com legenda: o clique troca a imagem principal e, sem JavaScript, as miniaturas são links para o arquivo.
- Hierarquia de títulos: seção `h2`, categoria `h3`, recurso `h4`.
- Coluna de texto: chip "Onde" (`Análises › Autores`), título do recurso, descrição em destaque e quatro blocos com ícone, "O que mostra", "Como usar", "Por que usar" e "Exemplo real" (este num quadro com borda em gradiente), além dos chips de exportação.

Alternativa descartada: grade de cartões pequenos com o texto num modal. É mais compacta, mas esconde justamente a explicação que o Jonas pediu e deixa os prints pequenos demais para ler.

### 4. Filtro, âncoras e ampliação sem biblioteca nova
- Filtro: os chips são `button` com `aria-pressed`, e o JS liga `hidden` nos blocos de categoria. Sem JS, tudo fica visível e os chips viram links para as âncoras das categorias.
- Âncoras: cada recurso tem `id="recurso-<id>"`. No `load` e no `hashchange` com `#recurso-…`, o filtro volta para "Todos" antes de rolar.
- Ampliação: um único `<dialog>` com `showModal()` (o `Esc` é nativo), fechado pelo botão ou por clique no fundo, com a legenda abaixo da imagem. O foco volta ao gatilho guardado antes de abrir.
- Animação de entrada com `IntersectionObserver` (subida suave), desligada com `prefers-reduced-motion`.

### 5. Ajustes no restante da home
- Menu: link "Recursos" antes de "Entrar".
- Faixa de números do hero: "<N> recursos" (do catálogo), "8 bases" (Web of Science, Scopus, Lens, PubMed, BRAPCI, BDTD, LISTA/EBSCO e OpenAlex) e "100% Web".
- Cartão "Importação Inteligente" e passo 2 de "Como funciona": citar as oito bases e o RIS, no lugar de "Scopus e Web of Science".
- `og:description` e JSON-LD continuam vindo de `site_settings`, sem mudança.

### 6. Inventário: 32 recursos, 46 prints
Origem dos dados: P22 = projeto 22 (Web of Science, 28.012 docs), P1 = projeto 1 (Web of Science, 498 docs). C = claro, E = escuro.

| Categoria | Recurso (id) | Prints |
|---|---|---|
| Indicadores e produção | `painel-de-indicadores` | dash-kpis (P1 C), dash-producao-citacoes (P1 C), dash-top-autores-periodicos (P1 C) |
| | `perfil-do-corpus` | dash-perfil-corpus (P1 C) |
| | `producao-anual` | anual-graficos (P1 E), anual-tabela (P1 E) |
| Autores, fontes e documentos | `produtividade-e-lei-de-lotka` | autores-ranking-lotka (P22 E) |
| | `indices-h-e-g` | autores-tabela-hg (P1 C) |
| | `lei-de-bradford` | fontes-ranking-bradford (P22 E), fontes-zona1 (P22 E) |
| | `impacto-das-fontes-e-qualis` | fontes-tabela-qualis (P22 E) |
| | `documentos-mais-citados` | documentos-mais-citados (P1 C) |
| Palavras-chave e vocabulário | `palavras-chave-em-barras` | palavras-barras (P22 C) |
| | `nuvem-de-palavras` | palavras-nuvem (P22 E) |
| | `mapa-de-arvore` | palavras-treemap (P22 C) |
| | `evolucao-de-palavras-chave` | evolucao-agrupado (P22 C), evolucao-empilhado (P22 C), evolucao-linhas (P22 E) |
| | `glossario-de-termos` | glossario (P1 E) |
| | `lentes-teoricas` | lentes-teoricas (P1 C) |
| Redes e estrutura temática | `rede-de-coautoria` | rede-coautoria (P1 C) |
| | `rede-de-palavras-chave` | rede-palavras (P1 C) |
| | `rede-de-paises` | rede-paises (P1 E) |
| | `mapa-tematico` | mapa-tematico (P1 C) |
| | `evolucao-tematica` | evolucao-tematica (P1 E) |
| | `grafico-de-tres-campos` | tres-campos (P22 C) |
| | `matrizes-de-coocorrencia` | matriz (P22 E) |
| Geografia e instituições | `paises-e-continentes` | paises-continentes (P22 C), paises-ranking-tabela (P22 C) |
| | `mapas-de-intensidade` | paises-mapa-linear (P22 C), paises-mapa-log (P22 C) |
| | `instituicoes` | instituicoes (P22 E) |
| | `relatorio-geografico` | geo-continente-regiao (P22 E), geo-mapa-regioes (P22 C), geo-estados (P22 E), geo-natureza-tipos (P22 E) |
| Classificação temática | `grupos-tematicos` | classificacao-grupos (P22 C) |
| | `organizacao-por-cores` | classificacao-cores (P22 E) |
| | `resultados-da-classificacao` | classificacao-resultados (P22 C) |
| | `relatorio-de-classificacao` | rel-classif-volume-citacoes (P22 C), rel-classif-media-citacoes (P22 C), rel-classif-crescimento-concentracao (P22 E) |
| | `mapa-de-calor-e-maturidade` | rel-classif-mapa-calor (P22 E), rel-classif-maturidade (P22 E) |
| Relatórios e busca | `relatorio-geral` | relatorio-geral-capa (P1 E) |
| | `busca-de-trabalhos` | busca-de-trabalhos (P1 C) |

O comparativo entre grupos entra como 33º recurso depois da correção do erro 500, com prints novos.

Regras para os textos de cada recurso:
- Escritos para pesquisador e orientador, não para programador. Diga o que o gráfico responde ("quem são os autores centrais do campo?"), não como foi programado.
- O método de referência aparece quando existe: Lotka, Bradford, índice h e g, Callon (centralidade e densidade), Louvain, índice de inclusão e Jaccard.
- O exemplo usa números reais dos prints (por exemplo: "131 periódicos formam o núcleo de Bradford e concentram 6.324 dos 28 mil documentos").
- Nada de promessa que o sistema não cumpre. Os limites que valem ser ditos (rede só até 35 mil documentos, mapa temático sem rótulo nas bolhas) aparecem no "Como usar".
- Base de partida: os campos `what`, `how` e `example` de `config/help/*.yaml` da tela correspondente, reescritos para quem ainda não usa o sistema.

### 7. Mapas: sem camada de azulejos, enquadramento pelos dados
- Remover `L.tileLayer(...)` dos cinco mapas e o `tileUrl` do CARTO. O contêiner do mapa usa a cor de fundo do cartão no tema atual. Países sem dados continuam com as cores atuais (`#e8ecf0` no claro, `#21262d` no escuro).
- Enquadramento: `fitBounds` na camada GeoJSON depois do carregamento (mundo: limites fixos `[[-58,-170],[78,190]]` para não dar zoom na Antártida; Brasil: limites da camada de estados; cidades: limites dos marcadores, ou o país filtrado quando houver um só).
- Mapa de cidades: carrega o GeoJSON de países (o mesmo do relatório de Países) como camada de contorno neutra, sob os marcadores.
- O evento `bm-theme-changed`, que já existe em `countries.html.twig`, passa a redesenhar também os mapas do Relatório Geográfico.

Alternativas: OpenStreetMap padrão (gratuito com atribuição, mas colorido, sem versão escura, e com política de uso que não admite tráfego alto), Stadia ou MapTiler (exigem chave e cadastro de domínio) e CARTO com chave (conta comercial). O mapa coroplético não precisa de ruas nem de relevo, então tirar a camada resolve sem dependência nova. Os prints da vitrine foram tirados exatamente assim (camada escondida).

## Risks / Trade-offs

- [Os prints envelhecem quando a interface muda] → O roteiro de captura fica neste design e na tarefa de documentação. O teste só garante que os arquivos existem e alternam o tema.
- [Home mais pesada] → Nenhum print na primeira dobra, `loading="lazy"`, 1,8 MB no total, 96 KB no maior arquivo. A medição de antes e depois (tamanho do HTML, LCP no Lighthouse móvel) está nas tarefas.
- [Repositório público com prints de dados reais] → Os prints só mostram dados bibliográficos públicos (artigos, autores de artigos, periódicos, termos, países). Nenhum mostra usuário, e-mail, impersonação ou nome de projeto de outro usuário. A tarefa de conferência abre cada arquivo.
- [Botão "Sci-Hub" nas telas de resultados] → Ele foi escondido nos prints, porque a home é a vitrine pública do sistema. Manter ou não esse link no sistema é decisão do Jonas, fora desta mudança.
- [Mapa sem azulejos perde a referência de oceano e relevo] → Para contagem por país e região isso não faz falta, e o contorno dos países continua visível. Se fizer falta, um fundo próprio (GeoJSON de oceanos) entra depois, sem serviço externo.
- [Dados do projeto 22 com anos inválidos aparecem em alguns textos de período] → Os prints escolhidos evitam esses gráficos. A correção dos dados vai para outra mudança.

## Migration Plan

1. Implementar e testar localmente (`vendor/bin/phpunit`), com commits pequenos por tema na `main`.
2. Deploy pelo roteiro de sempre (escrita em produção): `git pull --ff-only origin main` e `./build.sh --prod` como o usuário dono do webapp. Não há migration.
3. Conferência (leitura): home deslogada em 1440 px e 360 px, filtro, âncora, ampliação, nenhum 404 de imagem; relatório de Países e Relatório Geográfico do projeto 22 sem "API KEY REQUIRED" nos dois temas.
4. Volta atrás: `git revert` dos commits e novo `./build.sh --prod`. As imagens saem junto.

### Roteiro de captura (para recapturar um print)
Janela do Chrome com 1440 px de largura, tema escolhido em `localStorage['bm-theme']`, aviso de impersonação removido do DOM, cursor fora da área e recorte no cartão com 10 px de folga. Exportar em PNG e converter com `cwebp -q 82 -m 6`. Nas telas com mapa, conferir que não há azulejo. Nas telas de resultados, esconder o botão "Sci-Hub". Nunca abrir o Glossário, as Lentes Teóricas ou as redes de um projeto grande só para tirar print: são cálculos longos e o primeiro prende a sessão.
