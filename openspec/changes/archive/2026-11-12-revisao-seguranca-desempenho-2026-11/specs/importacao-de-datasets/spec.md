## MODIFIED Requirements

### Requirement: Execução em segundo plano
O sistema SHALL disparar `app:import:dataset` com `nohup`, o binário do PHP do pool (no RunCloud, `/RunCloud/Packages/phpXXrc/bin/php`), `memory_limit` de 768M, sem limite de tempo, o mesmo `APP_ENV` da requisição e a saída anexada a `var/log/import_{dataset}.log`.

#### Scenario: Acompanhamento da importação
- **WHEN** o dono confirma a importação
- **THEN** é levado à página de processamento, que consulta `/status/{id}` a cada 3 s até o dataset ficar `imported` ou `error`

## ADDED Requirements

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
