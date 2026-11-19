# Tasks

Regras para quem executa: `vendor/bin/phpunit` verde antes de cada commit; um commit por grupo, direto na `main`; tela alterada atualiza `config/help/import.yaml` (o `HelpCatalogTest` exige `title`, `what`, `how`, `not` e `example`); nada de arquivo real de base no commit (o repositório é público): os testes usam arquivos pequenos escritos à mão em `tests/fixtures/import/`. Os arquivos reais ficam em `var/exemplos/` (fora do git) e os testes que os usam são pulados quando a pasta não existe. Em produção, dizer se a ação é leitura ou escrita.

Esta mudança vem antes de `importacao-brapci`, `importacao-bdtd`, `importacao-openalex` e `importacao-lista-ebsco`.

## 0. Retrato de antes (regressão das bases atuais)

- [ ] 0.1 No banco local, criar um projeto de teste e importar um arquivo real de cada base atual (Scopus CSV, WoS TXT, Lens CSV, PubMed CSV e NBIB; usar os de `docs/sources/` ou pedir ao Jonas). Gravar em `var/exemplos/retrato-antes.json`, por dataset: lidos, importados, duplicados, erros e a lista ordenada de `hash` dos documentos (`SELECT hash FROM document WHERE dataset_id = ? ORDER BY hash`). Verificação: o arquivo existe com os cinco datasets.
- [ ] 0.2 Guardar capturas de tela de `/projects/{id}/import` (cada aba), da prévia e da tela de um dataset, em `var/exemplos/telas-antes/`. Verificação: as imagens existem.

## 1. Chave da base e escolha do leitor

- [ ] 1.1 `src/Service/Import/BibliographicImporterInterface.php`: acrescentar `getSourceKey(): string`. Implementar nos cinco leitores (`scopus`, `wos`, `lens`, `pubmed`, `pubmed`). Verificação: `bin/console lint:container`.
- [ ] 1.2 `ImporterResolver::detectAll()`: incluir `sourceKey` em cada resultado (no ramo de `academic_database`, a sigla). `ImportController::upload()`: gravar `sourceKey` na sessão (`import_source`) e, no envio múltiplo, em `Dataset::setSource()` (linhas 148 e 198-203). Teste novo `tests/Service/Import/ImporterResolverTest.php`: dois CSV do Lens no envio múltiplo resultam em `source = lens`. Verificação: teste passa.
- [ ] 1.3 `templates/import/preview.html.twig` linhas 20-29: usar `detected.sourceKey` em vez da cadeia de `if` com nomes de exibição. Verificação: prévia de um CSV do Lens marca o cartão Lens.org.
- [ ] 1.4 `ImporterResolver::resolve()`: regra da base declarada que não combina (declarado < 0,3 e melhor detecção ≥ 0,8 → usa o detectado). `ImportDatasetCommand::doImportDataset()`: quando a chave do leitor difere da base declarada (e ela não é `generic`, `unknown` nem vazia), trocar `dataset.source`, gravar `import_notice` (depende de 3.1) e escrever no log. Teste no `ImporterResolverTest`: CSV do Scopus com base declarada `lens` devolve o leitor do Scopus; CSV do Scopus com base declarada `scopus` não muda. Verificação: teste passa.
- [ ] 1.5 `vendor/bin/phpunit` e commit "Importação: chave da base no leitor e base ajustada pela detecção".

## 2. Extensões, ZIP e codificação

- [ ] 2.1 `ImportController`: constante `ALLOWED_EXTENSIONS = ['csv','txt','nbib','ris','xls','json','zip']` usada nos dois ramos de `upload()`; mensagem de formato não suportado montada a partir do catálogo (design D10); `accept` do input em `templates/import/index.html.twig:143`. Verificação: enviar `.bib` devolve "Formato não suportado: .bib"; enviar `.ris` chega à prévia (com o leitor de 3.x) ou à mensagem de formato não reconhecido.
- [ ] 2.2 Criar `src/Service/Import/ZipUploadExtractor.php` conforme design D1. Teste `tests/Service/Import/ZipUploadExtractorTest.php` criando os ZIP em tempo de execução com `ZipArchive`: um CSV (ok); CSV + pasta `__MACOSX` e `._x.csv` (ok); dois CSV (recusa); só `.exe` (recusa); entrada com nome `../../x.csv` (extrai com nome gerado, dentro da pasta); entrada com tamanho declarado acima do limite (recusa; usar limite injetável de 1 kB no teste). Verificação: teste passa.
- [ ] 2.3 `ImportController::upload()`: nos dois ramos, depois de mover o arquivo, se a extensão é `zip`, chamar o extrator, apagar o `.zip` e seguir com o arquivo extraído (extensão interna em `import_format` e em `Dataset::setFileFormat()`); em erro, apagar o que foi gravado e mostrar a mensagem do requisito. Verificação: enviar `var/exemplos/bdtb/search_result--1705555757-csv.zip` chega à tela de formato não reconhecido (ainda sem leitor) sem deixar o ZIP em `var/uploads/{projeto}/`.
- [ ] 2.4 `TextNormalizer`: `needsUtf8Check(string $path): bool` (falso para OLE2 e ZIP) e `scrub(string $s): string`. Interface `DeclaresEncodingInterface` (design D2). `ImportController` (linhas 112 e 190) e `ImportDatasetCommand` (linha 215): pular o teste de UTF-8 quando `needsUtf8Check` é falso ou quando um leitor que detecta o arquivo declara codificação. Testes em `tests/Service/Import/ImportFixesTest.php`: assinatura OLE2 não é checada; CSV em Latin-1 sem leitor que declare codificação continua recusado; `scrub("a\xE7b")` devolve `a\u{FFFD}b`. Verificação: testes passam.
- [ ] 2.5 Conferir (leitura, no servidor de produção) que `zip`, `mbstring` e `iconv` estão no PHP do pool: `<php do pool> -m`. Verificação: os três aparecem; se `zip` faltar, registrar aqui e tratar no painel RunCloud antes do deploy.
- [ ] 2.6 Ajuda: atualizar `import.index.file` e `import.index.supported_formats` em `config/help/import.yaml` (extensões novas, ZIP com um arquivo só). Verificação: `vendor/bin/phpunit tests/Twig/HelpCatalogTest.php`.
- [ ] 2.7 `vendor/bin/phpunit` e commit "Importação: aceita RIS, XLS, JSON e ZIP; codificação declarada pelo leitor".

## 3. Migration e entidades

- [ ] 3.1 Migration nova (design D7): `dataset.read_count`, `dataset.expected_count`, `dataset.import_notice`; `dataset_skip.external_id`, `position`, `detail`. `down()` remove as colunas. Atributos e getters/setters em `src/Entity/Dataset.php` e `src/Entity/DatasetSkip.php`. Verificação: `bin/console doctrine:migrations:migrate` no banco local e `bin/console doctrine:schema:validate` sem diferença nessas tabelas.
- [ ] 3.2 `Dataset::getReconciliation(): array` (design D6), com teste unitário `tests/Entity/DatasetReconciliationTest.php`: conta fechada; conta que não fecha; `read_count` nulo (dataset antigo) usa a soma; diferença para a prévia; diferença para o total informado. Verificação: teste passa.
- [ ] 3.3 Commit "Importação: colunas de conferência no dataset e nos registros não importados".

## 4. Identidade pelo identificador da base e conferência

- [ ] 4.1 `src/DTO/BibliographicRecordDTO.php`: `bool $strictIdentity = false` e `?int $position = null`. Criar `src/DTO/UnreadableRecord.php` (`position`, `message`, `externalId`, `title`). Verificação: `lint:container`.
- [ ] 4.2 `DocumentImportService::computeHash()`: ramo `strictIdentity` logo depois do DOI (design D5). Testes em `ImportFixesTest`: `testHashComDoiOuTituloEAnoNaoMuda` continua igual; registro com `strictIdentity`, título e ano tem hash `md5('ext:brapci:100')`; dois registros com `strictIdentity`, mesmo título e ano e IDs diferentes têm hashes diferentes. Verificação: testes passam.
- [ ] 4.3 `DocumentImportService::importAll()`: contador `read`, posição automática quando `$dto->position` é nulo, índice `titleYearIndex` carregado no início e a regra entre bases (design D5), nos dois sentidos. Teste de banco `tests/Service/Import/StrictIdentityDbTest.php` (estender `tests/Service/Authority/LocalDbTestCase.php`): (a) dois registros da mesma base com identidade própria, mesmo título e ano → 2 importados; (b) Scopus sem DOI já no projeto + registro com identidade própria de mesmo título e ano → duplicado `hash` apontando para o documento do Scopus; (c) ordem inversa → o do Scopus é o duplicado; (d) mesmo arquivo duas vezes → 0 importados na segunda; (e) documento da mesma base identificado só por título + ano já no projeto + registro com identidade própria de mesmo título e ano → duplicado `hash`, e a ordem inversa também. Verificação: teste passa.
- [ ] 4.4 `DocumentImportService`: gravar em `dataset_skip` os motivos `error_parse`, `error_invalid` e `error_db` (design D6) e preencher `external_id`, `position` e `detail` também nos motivos antigos; tratar `UnreadableRecord` como `null` com detalhe. Estender o teste de 4.3: gerador que entrega `null`, um `UnreadableRecord` e um registro sem título/DOI/identificador → 3 erros listados com posição e motivo; `read = imported + skipped + errors`. Verificação: teste passa.
- [ ] 4.5 `ImportDatasetCommand::doImportDataset()`: gravar `read_count`; a mensagem final do comando passa a "N lidos: X importados, Y duplicados, Z erros". Verificação: rodar `bin/console app:import:dataset <id>` num dataset local e ver `read_count` preenchido.
- [ ] 4.6 Medir no banco local, no maior projeto disponível, tempo e pico de memória de uma importação de 2 mil registros antes e depois do índice (`/usr/bin/time -l bin/console app:import:dataset <id> --skip-enrichment`). Registrar os números nesta tarefa. Verificação: pico abaixo de 400 MB e aumento de tempo abaixo de 20%; senão, voltar ao design antes de seguir.
- [ ] 4.7 Regressão: reimportar os cinco arquivos de 0.1 num projeto novo e comparar com `var/exemplos/retrato-antes.json` (mesmas contagens e mesma lista de `hash`). Verificação: nenhuma diferença.
- [ ] 4.8 `vendor/bin/phpunit` e commit "Importação: identidade pelo identificador da base e conferência dos registros lidos".

## 5. Leitor de RIS

- [ ] 5.1 Criar `src/Service/Import/Ris/RisReader.php` (design D3). Fixtures escritas à mão em `tests/fixtures/import/ris/`: `lf.ris`, `crlf.ris`, `cr-only.ris`, `bom.ris`, `continuacao.ris` (AB em três linhas), `sem-er-final.ris`, `ty-sem-er.ris` (dois TY seguidos), `byte-invalido.ris`. Teste `tests/Service/Import/RisReaderTest.php`: contagem e conteúdo em cada caso; `count()` igual ao número de registros entregues. Verificação: teste passa.
- [ ] 5.2 Criar `src/Service/Import/Ris/AbstractRisImporter.php` e `src/Service/Import/GenericRisImporter.php` (`getSourceKey() = generic`, `detect()` 0,55, sem `strictIdentity`). Registrar em `config/services.yaml` com a tag `app.bibliographic_importer`. Teste `GenericRisImporterTest`: 3 registros com TI/T1, AU/A1, PY/Y1 (`2019/09/30/`), JO/JF/T2, DO com `https://doi.org/`, páginas, KW repetido; URL com esquema inválido descartada. Verificação: teste passa.
- [ ] 5.3 Teste local (pulado sem a pasta): `RisReader::count()` em `var/exemplos/bdtb/…ris` (dentro do ZIP extraído) dá 7.193 e em `var/exemplos/pesquisa/brapci-todos-formatos/brapci_export_ris_73reg_bibliometria.ris` dá 73. Verificação: teste passa na máquina do Jonas.
- [ ] 5.4 Ajuda `import.source.other`: dizer que RIS de base sem leitor próprio é importado como "Outra". Verificação: `HelpCatalogTest`.
- [ ] 5.5 `vendor/bin/phpunit` e commit "Importação: leitor de RIS comum e RIS genérico".

## 6. Telas

- [ ] 6.1 `src/Config/DataSources.php`: campos `hasReader` e `guide` por base (verdadeiro para scopus, wos, lens e pubmed; falso para openalex e crossref por enquanto). Mover os painéis de guia de `templates/import/index.html.twig` (linhas 220-634) para `templates/import/guides/_<chave>.html.twig` sem mudar o texto, e montar abas e painéis pelo catálogo (design D9). Verificação: `bin/console lint:twig templates` e comparação visual com `var/exemplos/telas-antes/`.
- [ ] 6.2 `templates/import/preview.html.twig` (linhas 211-215) e `templates/dataset/edit.html.twig` (linhas 97-101): cartões de base pelo catálogo. Campo "Total de resultados mostrado pela base" na prévia e na edição; `ImportController::confirm()` e `DatasetController` (edição) validam inteiro de 1 a 10.000.000 e gravam `expected_count`. Ajuda nova `import.preview.expected_count`. Teste funcional em `tests/Controller/`: confirmar com valor válido grava; com "abc" ignora. Verificação: teste passa e `HelpCatalogTest`.
- [ ] 6.3 `templates/dataset/show.html.twig` e `DatasetController::show()`: bloco "Conferência" com a conta, os avisos (soma, prévia, total informado) e o `import_notice`; lista de não importados com posição, identificador, título, motivo legível (`doi` → "DOI já existe no projeto", `hash` → "mesmo documento já existe", `db_constraint`, `error_parse` → "registro ilegível", `error_invalid`, `error_db`) e detalhe; bloco "Possíveis repetições" (consulta do design D6, com `EXPLAIN` conferido). Ajuda nova `dataset.show.reconciliation` e `dataset.show.possible_repeats` (no arquivo de ajuda onde estão as chaves de dataset). Verificação: abrir um dataset local com duplicados e erros e ver os três blocos; `HelpCatalogTest`.
- [ ] 6.4 `templates/import/processing.html.twig` e `ImportController::status()`: incluir `readCount` no JSON e mostrar "lidos" ao lado de importados, duplicados e erros. Verificação: importar um arquivo e ver os quatro números ao final.
- [ ] 6.5 Conferência no navegador (local, `symfony serve`): cada aba de `/projects/{id}/import` com o mesmo conteúdo de antes; prévia e edição com os mesmos cartões. Verificação: capturas em `var/exemplos/telas-depois/` sem diferença de conteúdo.
- [ ] 6.6 `vendor/bin/phpunit`, `lint:twig templates`, `lint:yaml config` e commit "Importação: conferência na tela do dataset e bases da tela pelo catálogo".

## 7. Produção

- [ ] 7.1 (leitura) Conferir espaço em disco e que não há importação rodando (`SELECT IS_USED_LOCK('bibliomap_import')`). Verificação: trava livre e disco com folga.
- [ ] 7.2 (escrita, com aprovação do Jonas) Backup: `mysqldump` do banco na pasta de backups do servidor. Verificação: arquivo com tamanho coerente com os anteriores.
- [ ] 7.3 (escrita) Deploy como o usuário dono do webapp: `git pull --ff-only origin main`; `./build.sh --prod`; `<php do pool> bin/console doctrine:migrations:migrate --no-interaction --env=prod`. Verificação: `doctrine:migrations:status` sem pendência.
- [ ] 7.4 (leitura) Conferência depois: `/projects/22/import` abre com as mesmas abas; um dataset antigo abre com a conferência calculada pela soma; `SELECT COUNT(*) FROM document` igual ao de antes do deploy. Verificação: os três pontos conferidos e anotados aqui.

## Workflow follow-up

- Arquivar a mudança (com sincronização da spec `importacao-de-datasets`) só depois da tarefa 7.4.
- As mudanças das bases dependem desta estar em produção para o teste no navegador em `/projects/22/import`.
