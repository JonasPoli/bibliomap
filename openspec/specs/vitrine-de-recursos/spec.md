# vitrine-de-recursos Specification

## Purpose
Apresenta, na home pública, cada recurso do BiblioMap (gráficos, mapas, redes e relatórios, e depois os demais tipos) com título, explicação de uso e prints reais do sistema, para que quem ainda não tem conta veja o que a plataforma entrega.

## Requirements

### Requirement: Seção de recursos na home pública
A home pública (`/`) SHALL mostrar a quem não está logado uma seção "Recursos" com todos os recursos do catálogo, agrupados por categoria e na ordem do catálogo, no mesmo visual da página (cores, fonte, cartões e largura máxima). O menu da home SHALL ter um link para a seção. Quem está logado SHALL continuar sendo levado para `/projects`, sem ver a home.

#### Scenario: Visitante abre a home
- **WHEN** um visitante sem login abre `/`
- **THEN** a página mostra a seção "Recursos" com as categorias do catálogo e, em cada uma, os seus recursos na ordem do catálogo

#### Scenario: Usuário logado abre a home
- **WHEN** um usuário logado abre `/`
- **THEN** ele é redirecionado para `/projects`

### Requirement: Conteúdo obrigatório de cada recurso
Cada recurso SHALL mostrar título, descrição curta, o que ele permite ver, onde fica no sistema, como usar, por que usar, um exemplo concreto e pelo menos uma imagem com legenda e texto alternativo. Quando o recurso tem variações (modos de exibição, tipos de rede), SHALL haver uma imagem por variação mostrada.

#### Scenario: Recurso completo
- **WHEN** a seção mostra o recurso "Lei de Bradford"
- **THEN** aparecem o título, a descrição, os textos de o que mostra, onde fica, como usar e por que usar, o exemplo e o print do gráfico, com legenda e texto alternativo

#### Scenario: Recurso com variações
- **WHEN** a seção mostra a evolução de palavras-chave
- **THEN** aparecem as imagens dos modos agrupado, empilhado e em linhas, cada uma com a sua legenda

### Requirement: Catálogo conferido por teste
O conteúdo da vitrine SHALL vir de um catálogo versionado no repositório. Um catálogo com campo obrigatório vazio, identificador repetido, categoria inexistente ou imagem que não existe no projeto MUST fazer o teste do catálogo falhar, indicando o recurso e o campo ou arquivo.

#### Scenario: Identificador repetido
- **WHEN** duas entradas do catálogo usam o identificador `lei-de-lotka`
- **THEN** o teste do catálogo falha e cita o identificador repetido

#### Scenario: Imagem ausente no catálogo
- **WHEN** uma entrada do catálogo aponta para um arquivo de imagem que não existe
- **THEN** o teste do catálogo falha e indica o recurso e o arquivo

### Requirement: Prints reais, sem dado pessoal, nos dois temas
As imagens da vitrine SHALL ser capturas reais de telas do BiblioMap com dados de projetos reais, recortadas na área do recurso. As imagens MUST NOT mostrar e-mail, nome de usuário do sistema, aviso de impersonação, token, endereço de servidor ou qualquer dado de conta; dados bibliográficos públicos (títulos, autores de artigos, periódicos, países, termos) PODEM aparecer. O conjunto SHALL ter imagens no tema claro e no tema escuro do sistema, alternadas ao longo da seção.

#### Scenario: Conferência de um print
- **WHEN** um print da vitrine é aberto em tamanho original
- **THEN** ele mostra só o cartão do recurso, sem barra superior com nome de usuário, sem aviso de impersonação e sem e-mail

#### Scenario: Alternância de temas
- **WHEN** a seção é percorrida do início ao fim
- **THEN** há prints no tema claro e no tema escuro, e nenhuma categoria tem só prints de um tema quando ela tem mais de um recurso

### Requirement: Navegação e ampliação das imagens
A seção SHALL permitir filtrar os recursos por categoria e SHALL mostrar todos os recursos quando o JavaScript estiver desligado. Cada recurso SHALL ter uma âncora própria na URL. Ao clicar num print, a imagem SHALL abrir ampliada sobre a página, com legenda, e SHALL fechar com `Esc`, com o botão de fechar ou com clique fora da imagem; o foco do teclado SHALL voltar ao print que foi aberto.

#### Scenario: Filtro por categoria
- **WHEN** o visitante escolhe a categoria "Redes e temas"
- **THEN** só os recursos dessa categoria ficam visíveis, e escolher "Todos" volta a mostrar todos

#### Scenario: Link direto para um recurso
- **WHEN** o visitante abre `/#recurso-lei-de-bradford`
- **THEN** a página rola até o recurso "Lei de Bradford", mesmo que outro filtro estivesse escolhido antes

#### Scenario: Ampliar e fechar pelo teclado
- **WHEN** o visitante dá Enter num print e depois aperta `Esc`
- **THEN** a imagem abre ampliada com a legenda e, ao fechar, o foco volta ao mesmo print

### Requirement: Desempenho e responsividade da home
As imagens da vitrine SHALL ser servidas em WebP, com largura e altura declaradas e carregamento sob demanda (fora da primeira dobra), cada arquivo com no máximo 250 KB. A seção MUST NOT fazer consulta ao banco por recurso nem carregar biblioteca externa nova. Na largura de celular (360 px) a seção SHALL ficar em uma coluna, sem rolagem horizontal da página.

#### Scenario: Primeira carga da home
- **WHEN** a home é aberta numa janela de 1440 × 900
- **THEN** as imagens da seção "Recursos" não são baixadas antes de a seção se aproximar da área visível

#### Scenario: Celular
- **WHEN** a home é aberta com 360 px de largura
- **THEN** os recursos aparecem em uma coluna, os prints cabem na largura e a página não rola na horizontal
