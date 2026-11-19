# importacao-de-datasets Specification

## Purpose
Recebe os arquivos exportados das bases (Web of Science TXT, Scopus, Lens.org e PubMed CSV, PubMed NBIB, BRAPCI, BDTD, LISTA/EBSCO, OpenAlex e RIS de outras bases, também dentro de um ZIP), cria um dataset por arquivo e importa os registros como documentos do projeto em segundo plano, sem duplicar documentos, e no fim enriquece o projeto (países, instituições, Qualis e remapeamento de palavras-chave). Todo registro lido termina importado, duplicado ou com erro, e a tela do dataset mostra a conta. Os leitores das bases novas têm spec própria (importacao-brapci, importacao-bdtd, importacao-lista, importacao-openalex). Implementado em `ImportController`, `ImporterResolver`, nos leitores `src/Service/Import/*Importer.php`, em `DocumentImportService`, no comando `app:import:dataset` (`ImportDatasetCommand`) e em `DocumentEnrichmentService`.

## Requirements

### Requirement: Formatos e tamanho aceitos
O sistema SHALL aceitar no envio só arquivos `.csv`, `.txt`, `.nbib`, `.ris`, `.xls`, `.json` e `.zip` de até 600 MB e recusar as demais extensões (BibTeX, XLSX, XML, DOC e outras) com a mensagem "Formato não suportado". Cada arquivo aceito SHALL ser gravado em `var/uploads/{id do projeto}/` com o nome `import_<uniqid>.<extensão>`.

#### Scenario: Arquivo RIS
- **WHEN** o dono do projeto envia um arquivo `.ris`
- **THEN** o arquivo é aceito e segue para a prévia

#### Scenario: Arquivo BibTeX
- **WHEN** o dono do projeto envia um arquivo `.bib`
- **THEN** volta para a tela de importação com "Formato não suportado: .bib"
- **AND** nenhum dataset é criado

### Requirement: Só o dono importa
As rotas de `/projects/{projectId}/import` SHALL exigir a permissão `edit` do `ProjectVoter`, que só o dono do projeto tem.

#### Scenario: Usuário que só vê um projeto público
- **WHEN** outro usuário logado abre a importação de um projeto público
- **THEN** recebe 403

### Requirement: Prévia antes de importar um arquivo
Com um único arquivo, o sistema SHALL detectar a base pela pontuação de cada leitor (e, em CSV, pelas colunas-assinatura das bases cadastradas), mostrar até 10 registros e o total de registros do arquivo, e só criar o dataset, com status pendente, quando o usuário confirmar. Um arquivo no formato de marcações da Web of Science gerado por outra base MUST NOT ser identificado como Web of Science.

#### Scenario: Exportação da Web of Science
- **WHEN** o dono envia um TXT que começa com o cabeçalho "Clarivate Analytics Web of Science"
- **THEN** a prévia indica Web of Science e o total de registros do arquivo
- **AND** a importação só começa depois de "Confirmar"

#### Scenario: TXT do OpenAlex no formato da Web of Science
- **WHEN** o dono envia um TXT que começa com `FN OpenAlex`
- **THEN** a prévia indica OpenAlex, e não Web of Science

### Requirement: Vários arquivos em um só processo
Com vários arquivos, o sistema SHALL criar um dataset pendente por arquivo aceito, marcar o projeto como `importing` e disparar um único processo em segundo plano que importa os datasets em sequência.

#### Scenario: Três arquivos de uma vez
- **WHEN** o dono envia três arquivos válidos
- **THEN** um único `app:import:dataset` recebe os três IDs
- **AND** a página volta para o projeto com "3 arquivo(s) enviado(s) com sucesso e enfileirados para processamento!"

### Requirement: Execução em segundo plano
O sistema SHALL disparar `app:import:dataset` com `nohup`, o binário do PHP do pool (no RunCloud, `/RunCloud/Packages/phpXXrc/bin/php`), `memory_limit` de 768M, sem limite de tempo, o mesmo `APP_ENV` da requisição e a saída anexada a `var/log/import_{dataset}.log`.

#### Scenario: Acompanhamento da importação
- **WHEN** o dono confirma a importação
- **THEN** é levado à página de processamento, que consulta `/status/{id}` a cada 3 s até o dataset ficar `imported` ou `error`

### Requirement: Falha ao iniciar o processo
Se o processo em segundo plano não puder ser iniciado, o dataset SHALL continuar pendente e a tela SHALL avisar que `proc_open`, `proc_close`, `proc_get_status`, `proc_terminate` e `exec` precisam estar liberados no `disable_functions`.

#### Scenario: proc_open bloqueado no PHP
- **WHEN** o pool do PHP bloqueia `proc_open`
- **THEN** o dataset fica com status pendente
- **AND** aparece o aviso para liberar as funções no RunCloud

### Requirement: Uma execução por dataset
O comando MUST NOT importar o mesmo dataset em dois processos ao mesmo tempo (trava MySQL `bibliomap_import_dataset_{id}`). Numa reexecução, SHALL apagar os registros de `dataset_skip` do dataset e contar como importados, e não como duplicados, os documentos já gravados por esse mesmo dataset.

#### Scenario: Segunda execução simultânea
- **WHEN** `app:import:dataset 12` roda enquanto outra execução do dataset 12 está ativa
- **THEN** a segunda sai com "Dataset #12 já está sendo importado por outro processo." sem gravar nada

### Requirement: Escolha do leitor
O comando SHALL escolher o leitor primeiro pela base e pelo formato declarados no dataset e, se não houver, pela detecção automática com pontuação acima de 0,5. Quando o leitor da base declarada reconhece o arquivo com pontuação abaixo de 0,3 e outro leitor o reconhece com 0,8 ou mais, o comando SHALL usar o leitor detectado, gravar a base detectada e avisar a troca. Sem leitor, o dataset SHALL ir para `error` com a mensagem "Formato não reconhecido", que lista os formatos lidos.

#### Scenario: CSV de base desconhecida
- **WHEN** nenhum leitor reconhece o arquivo
- **THEN** o dataset fica com status `error` e a mensagem pede para reexportar num dos formatos lidos

#### Scenario: Base escolhida não combina com o arquivo
- **WHEN** o dono escolhe "LISA" para um arquivo que só o leitor da BDTD reconhece (pontuação 1,0)
- **THEN** o arquivo é importado pelo leitor da BDTD
- **AND** o dataset passa a mostrar a base BDTD e o aviso "Base ajustada de LISA para BDTD pela detecção do arquivo"

### Requirement: Deduplicação dentro do projeto
O sistema SHALL ignorar o registro cujo DOI já existe no projeto ou cujo hash coincide (md5 do DOI em minúsculas ou, sem DOI, do título normalizado com o ano), gravando em `dataset_skip` o motivo (`doi`, `hash` ou `db_constraint`) e o documento encontrado. Registro sem título, DOI e identificador externo SHALL contar como erro.

#### Scenario: Mesmo arquivo importado duas vezes
- **WHEN** o dono importa de novo um arquivo já importado no mesmo projeto
- **THEN** todos os registros contam como duplicados e nenhum documento novo é criado
- **AND** o dataset termina como `imported`

#### Scenario: Mesmo DOI em outro projeto
- **WHEN** o DOI já existe só em outro projeto
- **THEN** o documento é importado normalmente neste projeto

### Requirement: Citações da Web of Science
O leitor da Web of Science SHALL gravar o campo `TC` como número de citações, inclusive quando vale 0, e deixar vazio quando o campo falta ou não é numérico.

#### Scenario: Artigo sem citações
- **WHEN** o registro traz `TC 0`
- **THEN** o documento fica com 0 citações, e não com o campo vazio

### Requirement: Resultado da importação
Ao terminar, o sistema SHALL gravar no dataset os lidos, importados, duplicados, erros e a data. Sem nenhum importado nem duplicado, o dataset SHALL ir para `error` ("Nenhum registro foi lido do arquivo" ou "Nenhum dos N registros lidos pôde ser importado."); senão, para `imported`. O projeto SHALL ficar `ready` se tiver documentos e `imported` se não.

#### Scenario: Arquivo vazio de registros
- **WHEN** o leitor não devolve nenhum registro
- **THEN** o dataset fica `error` com "Nenhum registro foi lido do arquivo. Confira se ele foi exportado no formato indicado para a base escolhida."

#### Scenario: Importação com duplicados e erros
- **WHEN** um arquivo de 100 registros termina com 90 importados, 8 duplicados e 2 erros
- **THEN** o dataset grava 100 lidos, 90 importados, 8 duplicados e 2 erros e fica `imported`

### Requirement: Enriquecimento uma vez no fim
Se algum documento foi importado e a opção `--skip-enrichment` não foi usada, o comando SHALL enriquecer cada projeto afetado uma única vez, depois de todos os datasets, sob a trava `bibliomap_enrich_project_{id}` (espera até 600 s). Falha no enriquecimento SHALL só gerar aviso, sem desfazer a importação.

#### Scenario: Outra sincronização em andamento
- **WHEN** a trava do projeto não é obtida em 600 s
- **THEN** o comando avisa "Enriquecimento do projeto #N não executado: outra sincronização em andamento."
- **AND** os documentos importados continuam gravados

### Requirement: Registro sem DOI e sem ano também é deduplicado
Um registro sem DOI e sem título com ano SHALL ser identificado pela base e pelo identificador do registro na base (UT, EID, Lens ID, PMID) e, na falta deles, pelo título normalizado. A identificação dos registros de Scopus, Web of Science, Lens e PubMed que têm DOI ou título com ano MUST NOT mudar.

#### Scenario: Mesmo arquivo importado duas vezes
- **WHEN** um arquivo com um registro da Web of Science sem DOI e sem ano é importado duas vezes no mesmo projeto
- **THEN** o projeto tem um documento só para esse registro, e a segunda importação o conta como duplicado

#### Scenario: Scopus com título e ano
- **WHEN** um registro do Scopus sem DOI, com título e ano, é importado depois desta mudança
- **THEN** o identificador calculado para ele é o mesmo de antes da mudança

### Requirement: Endereço do documento
O endereço do documento SHALL ser gravado só quando começar com `http://` ou `https://`; qualquer outro valor SHALL ser gravado como vazio.

#### Scenario: Endereço com outro esquema
- **WHEN** um CSV traz `javascript:alert(1)` na coluna do link
- **THEN** o documento é importado sem endereço

### Requirement: Sigla de duas letras no país
Uma sigla de duas letras no campo de país SHALL ser procurada primeiro no cadastro de países. SHALL ser tratada como estado dos Estados Unidos só quando for sigla de estado ou território dos EUA, e a importação MUST NOT criar estado a partir de uma sigla que não seja de estado dos EUA.

#### Scenario: Afiliação do Reino Unido
- **WHEN** a afiliação termina em "London, UK"
- **THEN** o documento é ligado ao Reino Unido e nenhum estado é criado

### Requirement: Enriquecimento por partes
O enriquecimento SHALL processar o projeto em blocos de documentos, trocando os vínculos de cada bloco numa transação, e MUST NOT apagar os vínculos nem o Qualis do projeto inteiro antes de recalcular.

#### Scenario: Processo interrompido
- **WHEN** o enriquecimento de um projeto de 50.000 documentos é interrompido na metade
- **THEN** os blocos já processados têm os vínculos novos e os demais continuam com os vínculos anteriores

### Requirement: Envio protegido por token
As rotas de envio e de confirmação da importação e a edição do dataset SHALL recusar requisição sem o token do formulário, e a base informada na confirmação SHALL ser uma das bases aceitas.

#### Scenario: Confirmação sem token
- **WHEN** a confirmação da importação chega sem token válido
- **THEN** nada é importado e o usuário volta à tela de importação com erro

### Requirement: Uma importação por vez no servidor
O comando de importação SHALL pegar a trava `bibliomap_import` antes de começar e, se outra importação estiver rodando, SHALL esperar (até 6 horas) em vez de rodar junto. O dataset em espera continua `pending` até a sua vez.

#### Scenario: Dois arquivos enviados quase juntos
- **WHEN** dois usuários confirmam importações com poucos segundos de diferença
- **THEN** a segunda começa só depois que a primeira termina

### Requirement: Retomar uma fila interrompida
A tela de datasets SHALL oferecer "Retomar importação" ao dono quando houver dataset `importing` cuja trava está livre, ou `pending` há mais de 2 minutos sem importação rodando. A ação SHALL exigir token e voltar a disparar o processo para esses datasets, e o aviso MUST NOT aparecer enquanto houver importação em andamento.

#### Scenario: Processo morto no meio da fila
- **WHEN** o processo morre depois do segundo de cinco arquivos
- **THEN** a tela mostra o botão, e a retomada importa os arquivos restantes e faz o enriquecimento no fim

### Requirement: Arquivo fora de UTF-8
O sistema SHALL recusar, no envio e no comando, um arquivo de texto cujo início não seja UTF-8 válido, com a mensagem para exportar de novo em UTF-8, em vez de importar com acentos perdidos. A regra MUST NOT ser aplicada a arquivo binário (`.xls` binário e `.zip`) nem a arquivo que um leitor reconhece e declara em outra codificação.

#### Scenario: Arquivo em Latin-1
- **WHEN** o dono envia um CSV em Latin-1 que nenhum leitor declara nessa codificação
- **THEN** o envio é recusado com a mensagem e o projeto não fica em `importing`

#### Scenario: Planilha binária
- **WHEN** o dono envia um `.xls` binário
- **THEN** o arquivo não é recusado pelo teste de UTF-8

### Requirement: ISSN dos leitores
O leitor NBIB SHALL ler a tag `IS`, e o leitor do Lens SHALL usar o primeiro ISSN válido (formato NNNN-NNNN) quando a coluna trouxer vários.

#### Scenario: Coluna com dois ISSN
- **WHEN** o Lens traz `1234-5678; 8765-4321`
- **THEN** o documento fica com o ISSN `1234-5678`

### Requirement: Retomada e lotes sem mudar o resultado
A gravação SHALL ocorrer numa transação por lote de 200 documentos, com INSERT de várias linhas para autores e palavras-chave; ao falhar por duplicidade, o lote SHALL ser desfeito e refeito registro a registro. O conteúdo gravado MUST ser o mesmo da gravação registro a registro.

#### Scenario: Duplicidade que só o banco enxerga
- **WHEN** um lote contém um documento que já existe no projeto
- **THEN** os demais são importados e esse é contado como duplicado

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

### Requirement: CSV lido sem caractere de escape
Os leitores de CSV SHALL ler o arquivo como CSV padrão, em que aspas dentro de uma célula vêm dobradas e a barra invertida é um caractere comum. Uma barra invertida antes de uma aspa MUST NOT deslocar as colunas seguintes nem juntar dois registros num só.

#### Scenario: Texto com barra invertida antes da aspa
- **WHEN** um CSV do Scopus traz no texto de financiamento `iniciativas como \""LocoBoar\"" (financiado, código 16KN)`
- **THEN** o EID, o idioma e as demais colunas do registro são lidos nas colunas certas

#### Scenario: Célula terminada em barra invertida
- **WHEN** uma célula termina com uma barra invertida
- **THEN** o registro seguinte é lido como um registro separado, e a contagem de registros não diminui
