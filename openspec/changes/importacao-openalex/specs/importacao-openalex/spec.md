# Spec Delta

## Purpose

Lê os arquivos do OpenAlex (JSON da API e CSV, TXT no formato Web of Science e RIS exportados pela interface) e transforma cada work em um documento do projeto, identificado pelo Work ID, sem perder registros por falta de DOI, por tamanho do arquivo ou por ser confundido com outra base.

## ADDED Requirements

### Requirement: JSON do OpenAlex
O sistema SHALL reconhecer como OpenAlex o arquivo `.json` que é uma resposta da API com a lista `results`, uma lista de works ou um work por linha, e SHALL lê-lo em fluxo, sem carregar o arquivo inteiro na memória. Quando o arquivo traz `meta.count`, a prévia SHALL mostrar esse total ao lado do número de registros do arquivo.

#### Scenario: Página da API
- **WHEN** o dono envia a resposta de `api.openalex.org/works` com 100 works e `meta.count` 801
- **THEN** a prévia indica OpenAlex, 100 registros e "a busca tem 801 resultados; este arquivo traz 100"

#### Scenario: Várias páginas de uma vez
- **WHEN** o dono envia de uma vez as 7 páginas de 100 works da mesma busca
- **THEN** são criados 7 datasets e o projeto termina com 700 documentos

#### Scenario: JSON de outra origem
- **WHEN** o dono envia um `.json` que não tem works do OpenAlex
- **THEN** a prévia mostra "Formato não reconhecido" e nada é importado

### Requirement: Campos do work
O leitor SHALL gravar: o Work ID curto (`W…`) como identificador; o título; o ano e a data; o tipo; o idioma; o DOI em minúsculas e sem o prefixo `https://doi.org/`; o nome, o ISSN-L e a editora da fonte principal; volume, número e páginas; o número de citações, inclusive 0; a situação de acesso aberto; os autores na ordem do arquivo; as instituições e os países dos autores, sem repetição; e as palavras-chave do OpenAlex como palavras-chave indexadas.

#### Scenario: Artigo completo
- **WHEN** o work traz `id` `https://openalex.org/W1630686127`, ano 2006, `biblio` com volume 12 e número 1, `cited_by_count` 1680, fonte "Em Questão" com `issn_l` 1807-8893 e um autor da UFMG com país `BR`
- **THEN** o documento tem identificador W1630686127, ano 2006, volume 12, número 1, 1.680 citações, fonte "Em Questão", ISSN 1807-8893, instituição "Universidade Federal de Minas Gerais" e país BR

#### Scenario: Work sem DOI e sem fonte
- **WHEN** o work não tem `doi` nem `primary_location.source`
- **THEN** o documento é importado sem DOI e sem fonte, identificado pelo Work ID

#### Scenario: Work sem autores
- **WHEN** `authorships` é uma lista vazia
- **THEN** o documento é importado sem autores

#### Scenario: Citações zeradas
- **WHEN** `cited_by_count` é 0
- **THEN** o documento fica com 0 citações, e não com o campo vazio

### Requirement: Resumo reconstruído
O leitor SHALL reconstruir o resumo a partir de `abstract_inverted_index`, colocando cada palavra nas posições indicadas, e deixar o resumo vazio quando o índice não existe.

#### Scenario: Índice invertido
- **WHEN** o índice é `{"In": [0], "this": [1, 4], "study": [2], "of": [3]}`
- **THEN** o resumo gravado é "In this study of this"

#### Scenario: Work sem resumo
- **WHEN** `abstract_inverted_index` é nulo
- **THEN** o documento é importado sem resumo

### Requirement: Referências do work
O leitor SHALL gravar as referências do work (`referenced_works`) como a lista dos Work IDs curtos citados.

#### Scenario: Work com duas referências
- **WHEN** `referenced_works` traz `https://openalex.org/W111` e `https://openalex.org/W222`
- **THEN** as referências do documento são `W111` e `W222`

### Requirement: Identidade pelo Work ID
Cada work SHALL ser identificado pelo Work ID. Dois works com IDs diferentes MUST NOT ser unidos por terem o mesmo título e ano; dois works com o mesmo DOI SHALL contar como o mesmo documento, com o segundo listado como duplicado; e o mesmo Work ID importado de novo, por qualquer formato que o traga, SHALL contar como duplicado.

#### Scenario: Preprint e artigo com o mesmo título
- **WHEN** o arquivo traz W100 e W200 com o mesmo título e ano e DOIs diferentes
- **THEN** os dois são importados e aparecem em "Possíveis repetições"

#### Scenario: Dois works com o mesmo DOI
- **WHEN** W300 e W301 trazem o mesmo DOI
- **THEN** o primeiro é importado e o segundo conta como duplicado por DOI, listado com o Work ID W301

#### Scenario: Página repetida
- **WHEN** o dono envia duas vezes a mesma página da API
- **THEN** na segunda vez os 100 registros contam como duplicados

### Requirement: CSV exportado pela interface
O sistema SHALL reconhecer como OpenAlex o CSV exportado pela interface, com ou sem BOM, lendo as colunas pelo nome nas três gerações de cabeçalho (rótulos como `Work ID` e `Citation count`; caminhos como `authorships.author.display_name`; nomes como `author_names` e `cited_by_count`) e separando os valores múltiplos pelo separador usado no arquivo. Coluna desconhecida SHALL ser ignorada e coluna ausente SHALL deixar o campo vazio.

#### Scenario: Exportação com as colunas recomendadas
- **WHEN** o dono envia um CSV com `Work ID`, `Title`, `DOI`, `Year`, `Author`, `Institution`, `Country`, `Source`, `ISSN`, `Type`, `Language`, `Citation count` e `Abstract`
- **THEN** a prévia indica OpenAlex e cada linha vira um documento com esses campos

#### Scenario: Cabeçalho da geração anterior
- **WHEN** o CSV traz `id`, `title`, `doi`, `publication_year`, `authorships.author.display_name` e `cited_by_count`
- **THEN** a prévia indica OpenAlex e os mesmos campos são lidos

#### Scenario: Célula com quebra de linha
- **WHEN** a afiliação de um autor contém uma quebra de linha dentro das aspas
- **THEN** o registro é lido inteiro e a contagem de registros não aumenta

#### Scenario: Lista por autor com posições vazias
- **WHEN** a coluna `Country` traz `BR||BR|BR||||BR|` e a coluna `Institution` traz `Universidade Federal de Campina Grande||Instituto Federal do Rio Grande do Norte`
- **THEN** o documento fica com o país BR e com as duas instituições

#### Scenario: Título ou autor com vírgula
- **WHEN** a coluna `Author` traz `Universidad Complutense de Madrid, España|Jacinto Gómez López`
- **THEN** o documento fica com dois autores, e o primeiro não é partido na vírgula

#### Scenario: CSV sem colunas de volume e páginas
- **WHEN** o CSV exportado com todas as colunas não traz volume, número nem páginas
- **THEN** os documentos são importados com esses campos vazios

### Requirement: CSV sem identificador
Quando o CSV do OpenAlex não traz nem a coluna do Work ID nem a do DOI (o padrão de cinco colunas da interface), o sistema SHALL recusar a importação na prévia, explicando quais colunas acrescentar na exportação.

#### Scenario: Exportação com as cinco colunas padrão
- **WHEN** o dono envia um CSV só com `Title`, `Author`, `Year`, `Citation count` e `Open access`
- **THEN** a prévia mostra "Este CSV do OpenAlex não traz Work ID nem DOI. Exporte de novo acrescentando as colunas Work ID e DOI." e o botão de confirmar não aparece

### Requirement: TXT do OpenAlex no formato Web of Science
O sistema SHALL reconhecer como OpenAlex o TXT cujo cabeçalho é `FN OpenAlex`, gravar a base como OpenAlex, usar `UT` como identificador quando presente e ler as citações de `TC` ou, na falta dela, de `CT`.

#### Scenario: Arquivo com FN OpenAlex
- **WHEN** o dono envia um TXT que começa com `FN OpenAlex` e `VR 1.0`
- **THEN** a prévia indica OpenAlex, e não Web of Science

#### Scenario: Citações em CT
- **WHEN** um registro traz `CT 15` e não traz `TC`
- **THEN** o documento fica com 15 citações

#### Scenario: Registro sem UT
- **WHEN** um registro não traz `UT` e traz `DI 10.1234/abcd`
- **THEN** o documento é importado com esse DOI e sem identificador do OpenAlex

### Requirement: RIS do OpenAlex
O sistema SHALL reconhecer o RIS exportado pelo OpenAlex, gravar a base como OpenAlex, ler as afiliações de `C1` (inclusive as que continuam em outra linha) e avisar na prévia que esse formato não traz citações, referências, palavras-chave nem o Work ID. As marcações `KW`, que nesse arquivo trazem os conceitos do OpenAlex, MUST NOT ser gravadas como palavras-chave.

#### Scenario: RIS exportado pela interface
- **WHEN** o dono envia `works-2026-04-18T01-00-07.ris`, com 9.540 registros
- **THEN** a prévia indica OpenAlex e 9.540 registros

#### Scenario: Afiliação em várias linhas
- **WHEN** um `C1` continua em duas linhas sem marcação
- **THEN** a afiliação é lida inteira e o registro seguinte não é afetado

#### Scenario: Conceitos em KW
- **WHEN** o registro traz `KW  - Computer science` e `KW  - Context (archaeology)`
- **THEN** o documento é importado sem palavras-chave

#### Scenario: Mesmo work já importado pelo CSV
- **WHEN** o projeto já tem um work importado do CSV e o RIS traz o mesmo work, com o mesmo DOI ou com o mesmo título e ano
- **THEN** o registro do RIS conta como duplicado

#### Scenario: Aviso na prévia
- **WHEN** o dono envia um RIS do OpenAlex
- **THEN** a prévia mostra "O RIS do OpenAlex não traz citações, referências, palavras-chave nem Work ID. Prefira o CSV com a coluna Work ID."

### Requirement: Guia de exportação do OpenAlex
A aba do OpenAlex na tela de importação SHALL explicar como exportar pela interface (entrar na conta, exportar em CSV acrescentando ao menos as colunas Work ID e DOI e marcando os resumos, baixar o arquivo em Settings → Exports), listar os formatos lidos e dizer onde está o total de resultados da busca. A aba MUST NOT dizer que a base não tem leitor.

#### Scenario: Dono abre a aba
- **WHEN** o dono do projeto abre a aba OpenAlex
- **THEN** vê o passo a passo da exportação, as colunas a acrescentar e os formatos lidos, sem o aviso "Ainda sem leitor"
