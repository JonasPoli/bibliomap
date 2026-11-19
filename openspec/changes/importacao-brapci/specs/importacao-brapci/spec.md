# Spec Delta

## Purpose

Lê os arquivos que o pesquisador exporta da cesta da BRAPCI (CSV, XLS e RIS) e transforma cada registro em um documento do projeto, identificado pelo ID da BRAPCI, sem perder registros por quebra de linha, codificação, DOI repetido ou título repetido.

## ADDED Requirements

### Requirement: Reconhecimento do CSV da BRAPCI
O sistema SHALL reconhecer como BRAPCI o CSV cujo cabeçalho contém as colunas `ID`, `CLASS`, `YEAR`, `PUBLICATION`, `AUTHORS`, `TITLE`, `KEYWORDS`, `ABSTRACTS`, `SESSION`, `LEGEND` e `URL`, e SHALL lê-lo quando as linhas terminam só em `\r`, em `\n` ou em `\r\n`, com ou sem a vírgula sobrando no fim de cada linha.

#### Scenario: Arquivo como a BRAPCI entrega
- **WHEN** o dono envia o CSV exportado da cesta, com 884 registros, quebras só em `\r` e vírgula no fim das linhas
- **THEN** a prévia indica BRAPCI e 884 registros

#### Scenario: Arquivo regravado por um editor
- **WHEN** o mesmo CSV é regravado com quebras `\r\n` e sem a vírgula final
- **THEN** a prévia indica BRAPCI e os mesmos 884 registros

### Requirement: Campos do CSV
O leitor SHALL gravar: `ID` como identificador do registro na base; `CLASS` como tipo; `YEAR` como ano; `PUBLICATION` como título da fonte; `AUTHORS` separados por ponto e vírgula; `TITLE`; `KEYWORDS` separadas por ponto e vírgula como palavras-chave do autor; `ABSTRACTS` como resumo; e o volume e o número extraídos de `LEGEND`. Entidades HTML (`&nbsp;`, `&quot;`, `&#39;`) SHALL ser convertidas no texto.

#### Scenario: Artigo completo
- **WHEN** a linha traz `4316,"Article",2000,"DataGramaZero","Richard Matthew Stallman","O projeto gnu","Gnu; Software livre",…,"DataGramaZero, v. 1, n. 1, 2000",…`
- **THEN** o documento tem identificador 4316, tipo Article, ano 2000, fonte DataGramaZero, um autor, duas palavras-chave, volume 1 e número 1

#### Scenario: Organizador de livro
- **WHEN** `AUTHORS` traz `Maria Silva (Org.); João Souza (Org.)`
- **THEN** os autores gravados são "Maria Silva" e "João Souza"

#### Scenario: Legenda sem volume
- **WHEN** `LEGEND` é `Revista X, n. esp., 2019`
- **THEN** o documento fica sem volume e com o número "esp."

#### Scenario: Livro
- **WHEN** `CLASS` é `Book` e `PUBLICATION` é `LIVRO`
- **THEN** o documento é importado com tipo Book e sem título de fonte

### Requirement: Identidade pelo ID da BRAPCI
Cada linha com `ID` SHALL virar um registro identificado por esse ID. Dois registros com IDs diferentes MUST NOT ser unidos por terem o mesmo título e ano, e o mesmo ID importado de novo no projeto (pelo CSV, pelo XLS ou pelo RIS) SHALL contar como duplicado.

#### Scenario: Artigo e trabalho de evento com o mesmo título
- **WHEN** o arquivo traz o ID 1001 (Article) e o ID 2002 (Proceeding) com o mesmo título e ano
- **THEN** os dois são importados

#### Scenario: Mesma cesta exportada em CSV e depois em XLS
- **WHEN** o dono importa o CSV e depois o XLS da mesma cesta no mesmo projeto
- **THEN** todos os registros do XLS contam como duplicados

### Requirement: DOI só quando confiável
O leitor SHALL gravar o DOI da coluna `URL` só quando ele é bem formado (`10.` seguido de 4 a 9 dígitos, barra e sufixo, sem ponto final sobrando), aparece em um único ID do arquivo e o registro é `Article`. Nos demais casos o documento SHALL ficar sem DOI. O endereço do documento SHALL ser sempre o handle `https://hdl.handle.net/20.500.11959/brapci/{ID}`.

#### Scenario: DOI compartilhado por dois registros
- **WHEN** os IDs 300 e 301 trazem `https://doi.org/10.3322/caac.21492.` na coluna `URL`
- **THEN** os dois são importados, sem DOI, cada um com seu handle

#### Scenario: DOI malformado
- **WHEN** a coluna `URL` traz `https://doi.org/10.18617/`
- **THEN** o registro é importado sem DOI

#### Scenario: DOI válido e único de um artigo
- **WHEN** um `Article` traz `https://doi.org/10.18225/ci.inf.v13i2.200` e nenhum outro ID do arquivo usa esse DOI
- **THEN** o documento é gravado com o DOI `10.18225/ci.inf.v13i2.200`

#### Scenario: DOI em livro
- **WHEN** um `Book` traz um DOI bem formado e único
- **THEN** o documento é importado sem DOI

### Requirement: Valores padrão da BRAPCI
O leitor SHALL tratar como ausentes o título `:: Sem titulo ::` e o valor `null` sem aspas, e SHALL importar o registro mesmo sem título, autores, palavras-chave ou resumo, desde que tenha `ID`.

#### Scenario: Registro sem título
- **WHEN** a linha traz `ID` 555 e o título `:: Sem titulo ::`
- **THEN** o documento é importado sem título, identificado pelo ID 555

#### Scenario: Registro sem autores
- **WHEN** a coluna `AUTHORS` está vazia
- **THEN** o documento é importado sem autores

### Requirement: XLS da BRAPCI
O sistema SHALL reconhecer como BRAPCI o arquivo `.xls` que é uma tabela HTML com o mesmo cabeçalho do CSV, convertê-lo de Windows-1252 para UTF-8 e lê-lo com as mesmas regras do CSV, inclusive quando uma célula contém o sinal `<` sem escape.

#### Scenario: Planilha da cesta
- **WHEN** o dono envia o XLS de 884 registros exportado da cesta
- **THEN** a prévia indica BRAPCI e 884 registros, com os acentos corretos

#### Scenario: Célula com sinal de menor
- **WHEN** o resumo de um registro contém `p < 0,01`
- **THEN** o resumo é gravado inteiro e o registro seguinte não é afetado

### Requirement: RIS da BRAPCI
O sistema SHALL reconhecer como BRAPCI o RIS que traz `DB  - BRAPCI`, tirar o ID do registro do fim do endereço em `UR`, usar `M3` como tipo (ignorando o `TY`, que é sempre `JOUR`) e avisar na prévia que esse formato vem sem acentos e sem DOI. Registro sem `UR` SHALL contar como erro, com a posição no arquivo.

#### Scenario: Registro do RIS
- **WHEN** o registro traz `UR  - https://hdl.handle.net/20.500.11959/brapci/18014` e `M3  - Article`
- **THEN** o documento tem identificador 18014 e tipo Article

#### Scenario: Aviso na prévia
- **WHEN** o dono envia um RIS da BRAPCI
- **THEN** a prévia mostra "O RIS da BRAPCI vem sem acentos e sem DOI. Prefira o CSV."

### Requirement: Contagem por tipo para conferir com a cesta
A tela do dataset importado da BRAPCI SHALL mostrar quantos registros lidos são de cada tipo (Artigos, Trabalhos de eventos, Livros, Capítulos), para o dono comparar com os quatro cartões da cesta.

#### Scenario: Cesta com quatro tipos
- **WHEN** o arquivo tem 663 Article, 211 Proceeding, 7 Book e 3 BookChapter
- **THEN** a tela do dataset mostra "Artigos 663 · Trabalhos de eventos 211 · Livros 7 · Capítulos 3"

### Requirement: Guia de exportação da BRAPCI
A tela de importação SHALL ter a aba BRAPCI com o passo a passo da exportação pela cesta, a recomendação do CSV e os avisos de que a busca mostra no máximo 1.000 resultados sem login, de que a exportação pode ser cortada em 2.000 registros e de que os arquivos DOC, Citações, TT e .NET não são listas de registros.

#### Scenario: Dono abre a aba
- **WHEN** o dono do projeto abre a aba BRAPCI na tela de importação
- **THEN** vê os passos da cesta, o formato recomendado e os dois limites
