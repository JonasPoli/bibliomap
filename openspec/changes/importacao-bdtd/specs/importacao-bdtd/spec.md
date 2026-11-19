# Spec Delta

## Purpose

Lê os arquivos que o pesquisador obtém no botão "Exportar" da BDTD (CSV e RIS, soltos ou dentro do ZIP) e transforma cada linha em um documento do projeto, sem descartar registros por falta de identificador, por valores "não informado" ou por título repetido.

## ADDED Requirements

### Requirement: Reconhecimento do CSV da BDTD
O sistema SHALL reconhecer como BDTD o CSV cujo cabeçalho contém as colunas `Autor(a)`, `Orientadores`, `Título`, `Ano de defesa`, `Instituição de defesa`, `Sigla da instituição de defesa`, `Tipo de documento` e `Link de acesso`, enviado solto ou dentro do ZIP que a BDTD entrega.

#### Scenario: ZIP do botão Exportar
- **WHEN** o dono envia `search_result--1705555757-csv.zip`, com o CSV de 7.193 registros
- **THEN** a prévia indica BDTD e 7.193 registros

#### Scenario: CSV já descompactado
- **WHEN** o dono envia o mesmo CSV fora do ZIP
- **THEN** a prévia indica BDTD e 7.193 registros

### Requirement: Valor não informado
O texto "Não informado pela instituição" SHALL ser tratado como ausência do valor em qualquer coluna, inclusive quando aparece como um dos itens de uma lista separada por `||`.

#### Scenario: Orientador seguido de item não informado
- **WHEN** a coluna de assuntos traz `Inteligência artificial||Não informado pela instituição`
- **THEN** o documento fica com uma palavra-chave, "Inteligência artificial"

#### Scenario: Registro sem título
- **WHEN** a coluna `Título` traz "Não informado pela instituição"
- **THEN** o registro é importado sem título, e não com esse texto como título

### Requirement: Campos do CSV
O leitor SHALL gravar: o autor; o título sem a marca de idioma inicial (`[pt]`, `[en]`); o ano de defesa; o tipo (`masterThesis` como "Dissertação (mestrado)" e `doctoralThesis` como "Tese (doutorado)"); a instituição de defesa como instituição do documento e como editora; o país; os assuntos em português e em inglês como palavras-chave do autor; o primeiro idioma; o resumo; e o primeiro endereço `http(s)` da coluna `Link de acesso`.

#### Scenario: Dissertação completa
- **WHEN** a linha traz autor "Ortiz, Mariana Fleming Soares", título "Inteligência artificial e responsabilidade penal", ano 2023, `masterThesis`, instituição "Universidade Nove de Julho (UNINOVE)" e cinco assuntos separados por `||`
- **THEN** o documento tem esse autor, esse título, ano 2023, tipo "Dissertação (mestrado)", instituição "Universidade Nove de Julho" e cinco palavras-chave

#### Scenario: Título e assuntos com marca de idioma
- **WHEN** o título é `[pt] A RESPONSABILIDADE CIVIL NA INTELIGÊNCIA ARTIFICIAL` e os assuntos são `[pt] TECNOLOGIA||[en] TECHNOLOGY`
- **THEN** o título gravado começa em "A RESPONSABILIDADE" e as palavras-chave são "TECNOLOGIA" e "TECHNOLOGY"

#### Scenario: País ausente ou em outra grafia
- **WHEN** a coluna de país traz "BR", "brasil" ou "Não informado pela instituição"
- **THEN** o país do documento é Brasil

#### Scenario: Idioma com duas formas
- **WHEN** a coluna `Idioma` traz `por||pt_BR`
- **THEN** o idioma gravado é `por`

### Requirement: Autor único e limpo
O leitor SHALL gravar como autor só o primeiro nome válido da coluna `Autor(a)`, descartando itens que são data, endereço eletrônico ou endereço de página, e removendo do nome o sufixo de ano de nascimento (`, 1973-`) e o sufixo de instituição entre colchetes (`[UNESP]`).

#### Scenario: Coluna de autor com orientador e data
- **WHEN** a coluna traz `Barbosa, Eduardo Henrique de Oliveira||Pimenta, Eduardo Goulart, 1973-||2024-10-10`
- **THEN** o único autor do documento é "Barbosa, Eduardo Henrique de Oliveira"

#### Scenario: Coluna de autor com ORCID
- **WHEN** a coluna traz `Maia, Juliane Elizabete de Souza||https://orcid.org/0000-0002-4778-1807`
- **THEN** o único autor do documento é "Maia, Juliane Elizabete de Souza"

#### Scenario: Autor não informado
- **WHEN** a coluna traz "Não informado pela instituição"
- **THEN** o registro é importado sem autor

### Requirement: Resumo
O leitor SHALL usar como resumo a coluna `Resumo em Português` quando informada e, na falta dela, a coluna `Resumo`, removendo a marca de idioma inicial e convertendo entidades HTML.

#### Scenario: Só a coluna Resumo
- **WHEN** `Resumo` traz `[pt] A presente dissertação…` e `Resumo em Português` não foi informado
- **THEN** o resumo gravado começa em "A presente dissertação"

### Requirement: Endereço e DOI
Quando um dos itens de `Link de acesso` é um endereço de DOI (`doi.org`), o leitor SHALL gravá-lo como DOI do documento, e o endereço do documento SHALL ser o primeiro item `http(s)` que não é DOI (ou o próprio endereço do DOI, se for o único). Item que não começa com `http://` ou `https://` SHALL ser ignorado.

#### Scenario: Três endereços, um deles DOI
- **WHEN** a coluna traz `https://www.maxwell.vrac.puc-rio.br/colecao.php?nrSeq=61871&idi=1||https://www.maxwell.vrac.puc-rio.br/colecao.php?nrSeq=61871&idi=2||http://doi.org/10.17771/PUCRio.acad.61871`
- **THEN** o endereço é o primeiro e o DOI é `10.17771/pucrio.acad.61871`

#### Scenario: Coluna com texto que não é endereço
- **WHEN** a coluna traz `(Broch.)`
- **THEN** o documento é importado sem endereço

### Requirement: Identidade da linha
Como o arquivo não traz identificador, cada registro SHALL receber uma chave calculada do autor, do título, do ano e do endereço, com um número de ordem quando a mesma combinação se repete no arquivo. Registros com chaves diferentes MUST NOT ser unidos por terem o mesmo título e ano, e o mesmo arquivo importado de novo SHALL ter todos os registros contados como duplicados.

#### Scenario: Mesma tese em dois repositórios
- **WHEN** o arquivo traz a mesma tese duas vezes, com endereços diferentes
- **THEN** os dois registros são importados e aparecem em "Possíveis repetições"

#### Scenario: Duas linhas com autor, título, ano e endereço iguais
- **WHEN** o arquivo traz duas linhas que só diferem na sigla da instituição
- **THEN** as duas são importadas, a segunda com o número de ordem 2 na chave

#### Scenario: CSV e RIS da mesma busca
- **WHEN** o dono importa o CSV e depois o RIS exportados da mesma busca
- **THEN** todos os registros do RIS contam como duplicados

#### Scenario: Arquivo real de 7.193 registros
- **WHEN** o CSV de 7.193 registros é importado num projeto vazio
- **THEN** o dataset mostra 7.193 lidos e 7.193 importados

#### Scenario: Mesmo arquivo importado duas vezes
- **WHEN** o mesmo CSV é importado de novo no projeto
- **THEN** os 7.193 registros contam como duplicados e nenhum documento é criado

### Requirement: RIS da BDTD
O sistema SHALL reconhecer como BDTD o RIS cujos registros têm `TY` igual a `masterThesis` ou `doctoralThesis`, ler autor, ano, título, idioma, resumo e endereço com as mesmas limpezas do CSV e avisar na prévia que esse formato não traz instituição nem assuntos.

#### Scenario: RIS do botão Exportar
- **WHEN** o dono envia o ZIP com o RIS de 7.193 registros
- **THEN** a prévia indica BDTD, 7.193 registros e o aviso "O RIS da BDTD não traz instituição nem assuntos. Prefira o CSV."

#### Scenario: Marcação AU repetida com data
- **WHEN** um registro traz `AU  - Oliveira, Guilherme Augusto Alves de`, `AU  - Manzi, Flávio Ricardo` e `AU  - 2021-02-19`
- **THEN** o único autor do documento é "Oliveira, Guilherme Augusto Alves de"

### Requirement: Guia de exportação da BDTD
A tela de importação SHALL ter a aba BDTD com o passo a passo do botão "Exportar" (escolher CSV, marcar as duas caixas de resumo, resolver o captcha, informar o e-mail e baixar o ZIP na hora ou pelo link recebido), a recomendação do CSV, a orientação de enviar o ZIP como veio e onde ver o total de resultados da busca.

#### Scenario: Dono abre a aba
- **WHEN** o dono do projeto abre a aba BDTD na tela de importação
- **THEN** vê os passos da exportação, o formato recomendado e onde conferir o total de resultados
