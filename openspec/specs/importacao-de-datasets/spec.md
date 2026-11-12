# importacao-de-datasets Specification

## Purpose
Recebe os arquivos exportados das bases (Web of Science TXT, Scopus, Lens.org e PubMed CSV, PubMed NBIB), cria um dataset por arquivo e importa os registros como documentos do projeto em segundo plano, sem duplicar documentos, e no fim enriquece o projeto (países, instituições, Qualis e remapeamento de palavras-chave). Implementado em `ImportController`, `ImporterResolver`, nos leitores `src/Service/Import/*Importer.php`, em `DocumentImportService`, no comando `app:import:dataset` (`ImportDatasetCommand`) e em `DocumentEnrichmentService`.

## Requirements

### Requirement: Formatos e tamanho aceitos
O sistema SHALL aceitar no envio só arquivos `.csv`, `.txt` e `.nbib` de até 600 MB e recusar as demais extensões (RIS, BibTeX, XLSX, JSON) com a mensagem "Formato não suportado". Cada arquivo aceito SHALL ser gravado em `var/uploads/{id do projeto}/` com o nome `import_<uniqid>.<extensão>`.

#### Scenario: Arquivo RIS
- **WHEN** o dono do projeto envia um arquivo `.ris`
- **THEN** volta para a tela de importação com "Formato não suportado: .ris"
- **AND** nenhum dataset é criado

### Requirement: Só o dono importa
As rotas de `/projects/{projectId}/import` SHALL exigir a permissão `edit` do `ProjectVoter`, que só o dono do projeto tem.

#### Scenario: Usuário que só vê um projeto público
- **WHEN** outro usuário logado abre a importação de um projeto público
- **THEN** recebe 403

### Requirement: Prévia antes de importar um arquivo
Com um único arquivo, o sistema SHALL detectar a base pela pontuação de cada leitor (e, em CSV, pelas colunas-assinatura das bases cadastradas), mostrar até 10 registros e o total de registros do arquivo, e só criar o dataset, com status pendente, quando o usuário confirmar.

#### Scenario: Exportação da Web of Science
- **WHEN** o dono envia um TXT que começa com o cabeçalho "Clarivate Analytics Web of Science"
- **THEN** a prévia indica Web of Science e o total de registros do arquivo
- **AND** a importação só começa depois de "Confirmar"

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
O comando SHALL escolher o leitor primeiro pela base e pelo formato declarados no dataset e, se não houver, pela detecção automática com pontuação acima de 0,5. Sem leitor, o dataset SHALL ir para `error` com a mensagem "Formato não reconhecido", que lista os formatos lidos.

#### Scenario: CSV de base desconhecida
- **WHEN** nenhum leitor reconhece o arquivo
- **THEN** o dataset fica com status `error` e a mensagem pede para reexportar num dos formatos lidos

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
Ao terminar, o sistema SHALL gravar no dataset os importados, duplicados, erros e a data. Sem nenhum importado nem duplicado, o dataset SHALL ir para `error` ("Nenhum registro foi lido do arquivo" ou "Nenhum dos N registros lidos pôde ser importado."); senão, para `imported`. O projeto SHALL ficar `ready` se tiver documentos e `imported` se não.

#### Scenario: Arquivo vazio de registros
- **WHEN** o leitor não devolve nenhum registro
- **THEN** o dataset fica `error` com "Nenhum registro foi lido do arquivo. Confira se ele foi exportado no formato indicado para a base escolhida."

### Requirement: Enriquecimento uma vez no fim
Se algum documento foi importado e a opção `--skip-enrichment` não foi usada, o comando SHALL enriquecer cada projeto afetado uma única vez, depois de todos os datasets, sob a trava `bibliomap_enrich_project_{id}` (espera até 600 s). Falha no enriquecimento SHALL só gerar aviso, sem desfazer a importação.

#### Scenario: Outra sincronização em andamento
- **WHEN** a trava do projeto não é obtida em 600 s
- **THEN** o comando avisa "Enriquecimento do projeto #N não executado: outra sincronização em andamento."
- **AND** os documentos importados continuam gravados

### Requirement: Registro sem DOI e sem ano também é deduplicado
Um registro sem DOI e sem título com ano SHALL ser identificado pela base e pelo identificador do registro na base (UT, EID, Lens ID, PMID) e, na falta deles, pelo título normalizado. A identificação dos registros que têm DOI ou título com ano MUST NOT mudar.

#### Scenario: Mesmo arquivo importado duas vezes
- **WHEN** um arquivo com um registro da Web of Science sem DOI e sem ano é importado duas vezes no mesmo projeto
- **THEN** o projeto tem um documento só para esse registro, e a segunda importação o conta como duplicado

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
O sistema SHALL recusar, no envio e no comando, um arquivo cujo início não seja UTF-8 válido, com a mensagem para exportar de novo em UTF-8, em vez de importar com acentos perdidos.

#### Scenario: Arquivo em Latin-1
- **WHEN** o dono envia um CSV em Latin-1
- **THEN** o envio é recusado com a mensagem e o projeto não fica em `importing`

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
