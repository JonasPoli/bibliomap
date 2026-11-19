# importacao-lista Specification

## Purpose
Lê o CSV que o pesquisador exporta da LISTA (Library, Information Science & Technology Abstracts) na plataforma EBSCO e transforma cada linha em um documento do projeto, identificado pela base e pelo número de acesso da EBSCO, sem perder registros por falta de DOI nem duplicá-los quando a busca é exportada em vários arquivos.

## Requirements

### Requirement: Reconhecimento do CSV da EBSCO
O sistema SHALL reconhecer como exportação da EBSCO o CSV, com ou sem BOM, cujo cabeçalho contém as colunas `title`, `publicationDate`, `authors`, `plink`, `longDbName`, `shortDbName`, `accessionNumber` e `source`, e SHALL atribuí-lo à base LISTA.

#### Scenario: Arquivo como a EBSCO entrega
- **WHEN** o dono envia `EBSCO-Metadata-11_19_2026.csv`, em UTF-8 com BOM, com 50 registros
- **THEN** a prévia indica LISTA e 50 registros

#### Scenario: CSV de outra base
- **WHEN** o dono envia um CSV sem as colunas `accessionNumber` e `shortDbName`
- **THEN** o arquivo não é atribuído à LISTA

### Requirement: Campos do CSV
O leitor SHALL gravar: o título; o ano e a data de `publicationDate` (`AAAAMMDD`); os autores separados por ` ; `; a fonte (`source`); a editora; volume, número e páginas; o primeiro ISSN válido; o primeiro ISBN; o idioma; o resumo; o primeiro valor de `docType` como tipo (na falta dele, o de `pubType`); o DOI em minúsculas; os `subjects` como palavras-chave indexadas e as `keywords` como palavras-chave do autor; e o endereço permanente (`plink`).

#### Scenario: Artigo completo
- **WHEN** a linha traz data `20260102`, autores `Procópio, Daiane Campos ; Silva, Patrícia Nascimento`, fonte "Information Research", volume 31, páginas 193 e 211, ISSN `1368-1613`, idioma `por` e sete assuntos separados por ` ; `
- **THEN** o documento tem ano 2026, dois autores, essa fonte, volume 31, páginas 193 a 211, esse ISSN, idioma `por` e sete palavras-chave indexadas

#### Scenario: ISSN com valor que não é ISSN
- **WHEN** a coluna `issn` traz `1678-4561 ; Electronic`
- **THEN** o ISSN gravado é `1678-4561`

#### Scenario: ISSN sem hífen
- **WHEN** a coluna `issn` traz `16460758`
- **THEN** o ISSN gravado é `1646-0758`

#### Scenario: Tipo com vários valores
- **WHEN** `docType` traz `Scoping Review ; Journal Article`
- **THEN** o tipo gravado é "Scoping Review"

#### Scenario: Página que não é número
- **WHEN** `pageStart` traz `e23892025` e `pageEnd` está vazia
- **THEN** o documento é importado com a página inicial `e23892025` e sem página final

#### Scenario: Registro sem resumo, sem DOI e sem assuntos
- **WHEN** as colunas `abstract`, `doi` e `subjects` estão vazias
- **THEN** o documento é importado sem resumo, sem DOI e sem palavras-chave

### Requirement: Título sem o ponto final da EBSCO
O leitor SHALL remover um único ponto final do título, mantendo reticências e os títulos terminados em outro sinal, para que o mesmo documento vindo de outra base seja reconhecido pelo título e ano.

#### Scenario: Título com ponto acrescentado
- **WHEN** o título vem como `Large language models na ciência da informação: perspectivas técnicas.`
- **THEN** o título gravado termina em "perspectivas técnicas", sem o ponto

#### Scenario: Título terminado em interrogação
- **WHEN** o título vem como `Inteligência Artificial (IA): o que temos conseguido com isso?`
- **THEN** o título é gravado como veio

#### Scenario: Mesmo artigo já importado do Scopus
- **WHEN** o projeto já tem do Scopus, sem DOI, o artigo "Uso de metadados em bibliotecas digitais" de 2025, e o CSV da EBSCO traz `Uso de metadados em bibliotecas digitais.` de 2025, sem DOI
- **THEN** o registro da EBSCO conta como duplicado, com o documento do Scopus indicado

### Requirement: Identidade pela base e pelo número de acesso
Cada linha SHALL ser identificada pela sigla da base da EBSCO (`shortDbName`) junto com o número de acesso (`accessionNumber`). Dois registros com identificadores diferentes MUST NOT ser unidos por terem o mesmo título e ano, e o mesmo identificador importado de novo no projeto SHALL contar como duplicado. Linha sem número de acesso SHALL ser identificada pelo endereço permanente.

#### Scenario: Arquivos sobrepostos
- **WHEN** o dono envia um arquivo com 10 registros e depois outro com 50 que contém os mesmos 10
- **THEN** o segundo dataset mostra 40 importados e 10 duplicados

#### Scenario: Mesmo número de acesso em bases diferentes
- **WHEN** o arquivo traz o número de acesso 123456 na base `lih` e o mesmo número na base `aph`, com títulos diferentes
- **THEN** os dois registros são importados

#### Scenario: Mesmo artigo em duas bases da EBSCO, com DOI
- **WHEN** o arquivo traz o mesmo artigo nas bases `lih` e `aph`, com o mesmo DOI
- **THEN** o primeiro é importado e o segundo conta como duplicado por DOI

### Requirement: Identificador do PubMed só quando numérico
O leitor SHALL gravar a coluna `pmid` só quando ela contém apenas dígitos.

#### Scenario: Código que não é PMID
- **WHEN** a coluna `pmid` traz `EPTOC198526235`
- **THEN** o documento é importado sem PMID

### Requirement: Registros de outras bases da EBSCO
O leitor SHALL importar todas as linhas do arquivo, inclusive as que não são da LISTA, e a prévia SHALL avisar quantas linhas vêm de outras bases e quais são elas.

#### Scenario: Busca em várias bases
- **WHEN** o arquivo tem 50 registros, 6 da LISTA e 26 da Academic Search Premier
- **THEN** os 50 são importados e a prévia mostra "44 dos 50 registros não são da LISTA" com a lista das outras bases e suas quantidades

#### Scenario: Busca só na LISTA
- **WHEN** todos os registros do arquivo são da LISTA
- **THEN** a prévia não mostra o aviso

### Requirement: Guia de exportação da LISTA
A tela de importação SHALL ter a aba LISTA com o caminho de acesso pela EBSCO, o passo a passo da exportação em CSV, a orientação de limitar a busca à LISTA e de enviar todos os arquivos de uma vez quando a busca for exportada em partes, onde ver o total de resultados, e o aviso de que o BibTeX e o XML da EBSCO não são lidos (o BibTeX corta o resumo).

#### Scenario: Dono abre a aba
- **WHEN** o dono do projeto abre a aba LISTA na tela de importação
- **THEN** vê o caminho de acesso, o passo a passo do CSV e a orientação de limitar a busca à LISTA
