# Spec Delta

## MODIFIED Requirements

### Requirement: Formatos e tamanho aceitos
O sistema SHALL aceitar no envio só arquivos `.csv`, `.txt`, `.nbib`, `.ris`, `.xls`, `.json` e `.zip` de até 600 MB e recusar as demais extensões (BibTeX, XLSX, XML, DOC e outras) com a mensagem "Formato não suportado". Cada arquivo aceito SHALL ser gravado em `var/uploads/{id do projeto}/` com o nome `import_<uniqid>.<extensão>`.

#### Scenario: Arquivo RIS
- **WHEN** o dono do projeto envia um arquivo `.ris`
- **THEN** o arquivo é aceito e segue para a prévia

#### Scenario: Arquivo BibTeX
- **WHEN** o dono do projeto envia um arquivo `.bib`
- **THEN** volta para a tela de importação com "Formato não suportado: .bib"
- **AND** nenhum dataset é criado

### Requirement: Arquivo fora de UTF-8
O sistema SHALL recusar, no envio e no comando, um arquivo de texto cujo início não seja UTF-8 válido, com a mensagem para exportar de novo em UTF-8, em vez de importar com acentos perdidos. A regra MUST NOT ser aplicada a arquivo binário (`.xls` binário e `.zip`) nem a arquivo que um leitor reconhece e declara em outra codificação.

#### Scenario: Arquivo em Latin-1
- **WHEN** o dono envia um CSV em Latin-1 que nenhum leitor declara nessa codificação
- **THEN** o envio é recusado com a mensagem e o projeto não fica em `importing`

#### Scenario: Planilha binária
- **WHEN** o dono envia um `.xls` binário
- **THEN** o arquivo não é recusado pelo teste de UTF-8

### Requirement: Escolha do leitor
O comando SHALL escolher o leitor primeiro pela base e pelo formato declarados no dataset e, se não houver, pela detecção automática com pontuação acima de 0,5. Quando o leitor da base declarada reconhece o arquivo com pontuação abaixo de 0,3 e outro leitor o reconhece com 0,8 ou mais, o comando SHALL usar o leitor detectado, gravar a base detectada e avisar a troca. Sem leitor, o dataset SHALL ir para `error` com a mensagem "Formato não reconhecido", que lista os formatos lidos.

#### Scenario: CSV de base desconhecida
- **WHEN** nenhum leitor reconhece o arquivo
- **THEN** o dataset fica com status `error` e a mensagem pede para reexportar num dos formatos lidos

#### Scenario: Base escolhida não combina com o arquivo
- **WHEN** o dono escolhe "LISA" para um arquivo que só o leitor da BDTD reconhece (pontuação 1,0)
- **THEN** o arquivo é importado pelo leitor da BDTD
- **AND** o dataset passa a mostrar a base BDTD e o aviso "Base ajustada de LISA para BDTD pela detecção do arquivo"

### Requirement: Registro sem DOI e sem ano também é deduplicado
Um registro sem DOI e sem título com ano SHALL ser identificado pela base e pelo identificador do registro na base (UT, EID, Lens ID, PMID) e, na falta deles, pelo título normalizado. A identificação dos registros de Scopus, Web of Science, Lens e PubMed que têm DOI ou título com ano MUST NOT mudar.

#### Scenario: Mesmo arquivo importado duas vezes
- **WHEN** um arquivo com um registro da Web of Science sem DOI e sem ano é importado duas vezes no mesmo projeto
- **THEN** o projeto tem um documento só para esse registro, e a segunda importação o conta como duplicado

#### Scenario: Scopus com título e ano
- **WHEN** um registro do Scopus sem DOI, com título e ano, é importado depois desta mudança
- **THEN** o identificador calculado para ele é o mesmo de antes da mudança

### Requirement: Resultado da importação
Ao terminar, o sistema SHALL gravar no dataset os lidos, importados, duplicados, erros e a data. Sem nenhum importado nem duplicado, o dataset SHALL ir para `error` ("Nenhum registro foi lido do arquivo" ou "Nenhum dos N registros lidos pôde ser importado."); senão, para `imported`. O projeto SHALL ficar `ready` se tiver documentos e `imported` se não.

#### Scenario: Arquivo vazio de registros
- **WHEN** o leitor não devolve nenhum registro
- **THEN** o dataset fica `error` com "Nenhum registro foi lido do arquivo. Confira se ele foi exportado no formato indicado para a base escolhida."

#### Scenario: Importação com duplicados e erros
- **WHEN** um arquivo de 100 registros termina com 90 importados, 8 duplicados e 2 erros
- **THEN** o dataset grava 100 lidos, 90 importados, 8 duplicados e 2 erros e fica `imported`

## ADDED Requirements

### Requirement: Arquivo compactado
O sistema SHALL aceitar um `.zip` que contenha exatamente um arquivo de formato lido (ignorando pastas e arquivos de metadados do macOS), extraí-lo com nome gerado pelo sistema e tratá-lo como se tivesse sido enviado sozinho. SHALL recusar o ZIP com mais de um arquivo, sem arquivo de formato lido, protegido por senha ou cujo conteúdo descompactado passe de 600 MB.

#### Scenario: ZIP da BDTD
- **WHEN** o dono envia `search_result-123-csv.zip` com um único `.csv` dentro
- **THEN** a prévia mostra os registros do CSV e o nome original do ZIP

#### Scenario: ZIP com vários arquivos
- **WHEN** o ZIP contém dois arquivos `.csv`
- **THEN** o envio é recusado com "O ZIP deve conter um único arquivo exportado"
- **AND** nada fica gravado em `var/uploads`

### Requirement: Codificação declarada pelo leitor
Quando o leitor que reconhece o arquivo declara que ele vem em Windows-1252, o sistema SHALL convertê-lo para UTF-8 antes de ler e importar os acentos corretos. Um byte inválido isolado dentro de um arquivo UTF-8 SHALL ser trocado pelo caractere de substituição, sem descartar o registro.

#### Scenario: Planilha HTML em Windows-1252
- **WHEN** um arquivo reconhecido como exportação XLS da BRAPCI traz "Ciência da Informação" em Windows-1252
- **THEN** o documento é gravado com "Ciência da Informação"

#### Scenario: Byte inválido no meio do resumo
- **WHEN** um RIS em UTF-8 tem um byte inválido no resumo de um registro
- **THEN** o registro é importado, com o caractere de substituição nesse ponto

### Requirement: RIS de base sem leitor próprio
O sistema SHALL importar um arquivo RIS que nenhum leitor de base reconhece, lendo as marcações usuais (TY, TI/T1, AU/A1, PY/Y1, JO/JF/T2, VL, IS, SP, EP, SN, DO, UR, AB, KW, LA, PB, ER), com a base "Outra". Linha sem marcação SHALL ser tratada como continuação do campo anterior, e as quebras de linha `\n`, `\r\n` e `\r` SHALL ser aceitas.

#### Scenario: RIS de outra base
- **WHEN** o dono envia um RIS com 30 blocos `TY … ER` de uma base sem leitor próprio
- **THEN** a prévia mostra 30 registros e a base "Outra"

#### Scenario: Resumo em várias linhas
- **WHEN** o campo `AB` continua em duas linhas sem marcação
- **THEN** o resumo gravado contém as três linhas

### Requirement: Identidade pelo identificador da base
Para base cujo leitor fornece identificador próprio do registro, dois registros da mesma base SHALL ser duplicados um do outro só quando têm o mesmo identificador ou o mesmo DOI aceito pelo leitor. Título e ano iguais MUST NOT unir dois registros da mesma base com identificadores diferentes. Entre bases diferentes, título normalizado com ano continua identificando o mesmo documento.

#### Scenario: Dois registros da mesma base com o mesmo título e ano
- **WHEN** um CSV da BRAPCI traz os IDs 100 e 200 com o mesmo título e o mesmo ano, sem DOI
- **THEN** os dois são importados como documentos diferentes

#### Scenario: Mesmo artigo vindo de duas bases
- **WHEN** o projeto já tem do Scopus um artigo sem DOI com certo título e ano, e um CSV da BRAPCI traz registro com o mesmo título e ano
- **THEN** o registro da BRAPCI conta como duplicado, com o documento do Scopus indicado

#### Scenario: Mesmo arquivo importado duas vezes
- **WHEN** o mesmo arquivo de uma base com identificador próprio é importado duas vezes no projeto
- **THEN** na segunda vez todos os registros contam como duplicados e nenhum documento é criado

### Requirement: Conferência da importação
Todo registro lido do arquivo SHALL terminar como importado, duplicado ou erro, e a soma dos três SHALL ser igual ao total lido. Cada duplicado e cada erro SHALL ficar listado no dataset com a posição do registro no arquivo, o identificador na base, o título e o motivo. A tela do dataset SHALL mostrar a conta e destacar um aviso quando a soma não fecha com o total lido ou com o total contado na prévia.

#### Scenario: Registro que o leitor não interpreta
- **WHEN** o 37º registro do arquivo não tem título, DOI nem identificador
- **THEN** ele conta como erro e aparece na lista do dataset com a posição 37 e o motivo "sem título, DOI e identificador"

#### Scenario: Conta fechada
- **WHEN** a importação termina com 7.193 lidos, 7.100 importados, 90 duplicados e 3 erros
- **THEN** a tela do dataset mostra "7.193 lidos = 7.100 importados + 90 duplicados + 3 erros" sem aviso

#### Scenario: Conta que não fecha
- **WHEN** a prévia contou 500 registros e a importação leu 498
- **THEN** a tela do dataset mostra o aviso "A prévia contou 500 registros e a importação leu 498"

### Requirement: Total informado pela base
A prévia SHALL oferecer o campo opcional "Total de resultados mostrado pela base". Quando preenchido, o dataset SHALL guardar o valor e mostrar, ao lado do total lido, se os números coincidem ou qual é a diferença.

#### Scenario: Exportação cortada pela base
- **WHEN** o dono informa 1.482 resultados e o arquivo tem 1.000 registros
- **THEN** a tela do dataset mostra "A base informou 1.482 resultados e o arquivo tem 1.000: faltam 482"

#### Scenario: Campo em branco
- **WHEN** o dono confirma a importação sem preencher o campo
- **THEN** a importação segue normalmente e a tela do dataset não mostra comparação com a base

### Requirement: Possíveis repetições na própria base
A tela do dataset SHALL listar os grupos de documentos importados por ele que têm o mesmo título normalizado e o mesmo ano, para o dono decidir o que fazer. Esses documentos MUST NOT ser descartados pela importação.

#### Scenario: Tese em dois repositórios
- **WHEN** o arquivo da BDTD traz a mesma tese duas vezes, vinda de dois repositórios
- **THEN** os dois registros são importados e aparecem juntos em "Possíveis repetições (1 grupo, 2 documentos)"

### Requirement: Bases da tela vêm do catálogo
Toda base com leitor SHALL aparecer na tela de importação com seu guia de exportação e SHALL poder ser escolhida na prévia e na edição do dataset. A base detectada SHALL ser gravada no dataset pela chave da base, e não pelo nome de exibição.

#### Scenario: Envio de vários arquivos do Lens
- **WHEN** o dono envia dois CSV do Lens de uma vez
- **THEN** os dois datasets ficam com a base `lens`

#### Scenario: Base nova no catálogo
- **WHEN** uma base com leitor é acrescentada ao catálogo de bases
- **THEN** ela aparece nas abas da tela de importação, nos cartões da prévia e na edição do dataset
