# tesauro-de-palavras-chave Specification

## Purpose
Unifica as variações de uma palavra-chave (sinônimos, siglas, grafias, idiomas) num conceito, para que contagens, redes, mapa temático, matrizes e classificação usem o termo preferido sem apagar o termo original importado. Há duas camadas: o tesauro global (`thesaurus_scheme`, `thesaurus_concept`, `thesaurus_label`, em `/admin/thesaurus`) e o agrupamento da própria tabela `keyword` (`keyword_concept_id` e as variações em `palavra_chave_variacoes_nome`, em `/admin/keywords`). As duas são globais, valem para todos os projetos. Implementado em `KeywordResolverService`, `KeywordThesaurusMatcherService`, `KeywordTreatmentService`, `ThesaurusFileService`, `Admin\ThesaurusController`, `AdminKeywordController`, `DocumentImportService` e `DocumentEnrichmentService`.

## Requirements

### Requirement: Conceito efetivo nas análises
Toda análise que agrupa palavras-chave SHALL usar o conceito efetivo: o conceito do tesauro (rótulo preferido) quando a palavra tem `thesaurus_concept_id`; senão o `keyword_concept` (sua forma de exibição); senão a própria palavra. O ID de conceito do tesauro SHALL ser usado negativo, para não colidir com os IDs da tabela `keyword`.

#### Scenario: Sigla e nome por extenso
- **WHEN** "AI" e "Artificial Intelligence" estão ligadas ao conceito "Artificial Intelligence" do tesauro
- **THEN** as análises mostram um só item "Artificial Intelligence"
- **AND** um documento que tem as duas formas conta uma vez

### Requirement: Palavra-chave resolvida na importação
Na importação, cada termo SHALL ser procurado pela forma normalizada e pelo tipo (`author_keyword` ou `indexed_keyword`) na tabela global `keyword`. Termo novo SHALL criar uma palavra-chave cujo conceito aponta para ela mesma; termo inválido para o normalizador SHALL ser descartado; o texto original do documento SHALL ficar em `document_keyword.original_term`.

#### Scenario: Termo já usado em outro projeto
- **WHEN** um documento traz uma palavra-chave que já existe por causa de outro projeto
- **THEN** o documento é ligado à palavra-chave existente, sem criar outra

### Requirement: Remapeamento pelas variações
No enriquecimento do projeto, o sistema SHALL agrupar cada `document_keyword`, pela forma normalizada do `original_term`, na palavra-chave oficial (status 1) ou na palavra-chave de uma variação ativa em `palavra_chave_variacoes_nome`, do mesmo tipo, gravando o agrupamento e recalculando a identidade efetiva do vínculo. O enriquecimento MUST NOT alterar o id bruto do vínculo nem apagar vínculos repetidos no mesmo documento.

#### Scenario: Variação cadastrada depois da importação
- **WHEN** o administrador cadastra "IA" como variação de "inteligência artificial" e o projeto é sincronizado
- **THEN** os documentos com a palavra-chave "IA" passam a contar como "inteligência artificial" nas análises
- **AND** o vínculo continua com a palavra-chave "IA" e o `original_term` original

### Requirement: Correspondência com o tesauro global
O sistema SHALL comparar a forma normalizada da palavra-chave só com o esquema `keyword`: rótulo exato, depois conceito exato (nota 100) e, sem isso, comparação aproximada com os rótulos de mesma letra inicial, ficando a melhor nota por conceito. Abaixo de 75 não há correspondência; 95 ou mais é automática quando não há empate.

#### Scenario: Dois conceitos quase empatados
- **WHEN** a melhor nota é 96 e o segundo conceito tem 94
- **THEN** a correspondência é marcada como ambígua e vai para revisão, porque a diferença é menor que 3 pontos

### Requirement: Aplicação no tratamento de palavras-chave
No modo `execute`, o tratamento de palavras-chave SHALL ligar a palavra ao conceito e registrar um `ThesaurusMatch` `automatic` nas correspondências exatas e automáticas, e registrar `pending`, sem ligar, nas que pedem revisão. A simulação (`dry-run`) MUST NOT gravar vínculo nem pendência.

#### Scenario: Simulação
- **WHEN** o super administrador roda o tratamento em modo simulação
- **THEN** o relatório mostra o que seria ligado e nenhuma palavra-chave muda

### Requirement: Criação automática de conceitos
A opção `--auto-create-concepts` (campo `auto_create_concepts` na tela) SHALL ser aceita sem efeito: o tratamento MUST NOT criar conceitos no tesauro.

#### Scenario: Comando do cron
- **WHEN** `app:keywords:treat --execute --auto-create-concepts` roda
- **THEN** nenhuma linha nova aparece em `thesaurus_concept`

### Requirement: Leitura de arquivo .the do VantagePoint
Ao importar um `.the`, o sistema SHALL tratar como termo principal a linha que começa com `**` (sem os `*` e o `#` iniciais) e como variação o texto entre `^` e o `$` final das linhas seguintes, ignorando o prefixo `100 1`. Arquivo que não é UTF-8 SHALL ser lido como ISO-8859-1, e variações repetidas SHALL ser descartadas.

#### Scenario: Bloco de instituição
- **WHEN** o arquivo traz `**#universidade federal de são carlos` seguido de `100 1 ^cca ufscar$`
- **THEN** "cca ufscar" vira variação de "universidade federal de são carlos"

### Requirement: Leitura de tesauro em CSV
Ao importar um `.csv`, o sistema SHALL detectar o separador (`;`, `,` ou tabulação) pela linha de cabeçalho e ler o termo principal da coluna `preferred_name` (ou `preferred_keyword`, `preferred_journal`, `preferred`, `keep_name`, `keep`, `header`) e a variação de `variant_name` (ou equivalentes), ignorando linhas sem um dos dois.

#### Scenario: CSV salvo pelo Excel em português
- **WHEN** o arquivo usa `;` como separador
- **THEN** as colunas são lidas corretamente

### Requirement: Exportação do tesauro de um cadastro
A exportação `.the` SHALL escrever `**#<termo em minúsculas>` e, abaixo, `100 1 ^<variação em minúsculas>$`, com quebras CRLF. A exportação `.csv` SHALL sair com BOM UTF-8, separador `;` e as colunas `preferred_name` e `variant_name`.

#### Scenario: Exportar palavras-chave
- **WHEN** o administrador exporta o tesauro de `/admin/keywords` em `.the`
- **THEN** cada palavra-chave sai como termo principal com suas variações em minúsculas

### Requirement: Importação de tesauro nas palavras-chave
Em `/admin/keywords`, a importação SHALL achar a palavra-chave pelo `keyword_original` normalizado; termo principal desconhecido SHALL criar uma palavra-chave de autor (`author_keyword`, status 1, conceito nela mesma). As variações SHALL entrar como `alternative`, sem repetir as que já existem.

#### Scenario: Termo principal novo
- **WHEN** o arquivo traz um termo principal que não existe
- **THEN** é criada a palavra-chave com o tipo `author_keyword` e as variações do arquivo

### Requirement: Tesauro global por CSV
Em `/admin/thesaurus/import`, o sistema SHALL ler CSV com `;` e as colunas `Scheme;Concept;Label;Type;Language`, criando o esquema, o conceito (com o rótulo preferido) e os rótulos que faltam. Tipo vazio SHALL virar `preferred` quando o rótulo é igual ao conceito e `alternative` quando não é. O modelo em `/admin/thesaurus/template` SHALL trazer exemplos.

#### Scenario: Rótulo já cadastrado
- **WHEN** a linha traz um rótulo cuja forma normalizada já existe no conceito
- **THEN** a linha é ignorada, sem duplicar o rótulo
