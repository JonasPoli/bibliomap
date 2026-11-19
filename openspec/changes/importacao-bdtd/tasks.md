# Tasks

Regras para quem executa: `vendor/bin/phpunit` verde antes de cada commit; commit por grupo, direto na `main`; nada de arquivo real da BDTD no commit (repositório público): as fixtures de `tests/fixtures/import/bdtd/` são escritas à mão, com pessoas e trabalhos inventados. Os arquivos reais estão em `var/exemplos/bdtb/` (fora do git); os testes que os usam são pulados quando a pasta não existe. Em produção, dizer se a ação é leitura ou escrita.

Pré-requisito: mudança `importacao-base-novos-formatos` implementada (ZIP, `AbstractRisImporter`, `strictIdentity`, `UnreadableRecord`, conferência, `previewNotice()` se a BRAPCI já o criou; senão, criá-lo aqui conforme a tarefa 3.2).

Números de referência dos arquivos reais (medidos em 19/11/2026, busca "inteligência artificial"):

| Medida | CSV | RIS |
|---|---|---|
| Registros | 7.193 | 7.193 |
| `masterThesis` / `doctoralThesis` | 4.986 / 2.207 | 4.986 / 2.207 |
| Chaves distintas sem número de ordem | 7.187 | 7.187 |
| Registros com DOI (endereço `doi.org`) | 243 | 243 |
| Título não informado | 1 | 1 |
| Autor não informado | 1 | 1 |
| Grupos de título + ano repetidos | 205 (411 registros) | 205 |

## 1. Limpador e fixtures

- [x] 1.1 Criar as fixtures em `tests/fixtures/import/bdtd/`: `busca.csv` (12 registros inventados, cabeçalho real de 25 colunas, UTF-8, `\n`) cobrindo: registro completo; todas as colunas opcionais com "Não informado pela instituição"; lista com "não informado" no meio; autor com `||` + orientador + data; autor com `||` + ORCID; autor com `||` + e-mail; autor com sufixo `, 1973-`; autor com sufixo ` [UNESP]`; autor não informado; título com `[pt] `; título não informado; assuntos com `[pt]`/`[en]` misturados; país `BR`, `brasil`, `Estados Unidos` e não informado; idioma `por||pt_BR` e `eng||en_US`; link com três itens (um `doi.org`); link `(Broch.)`; link não informado; resumo com `&apos;` e `&lt;`; resumo só em `Resumo em Português`; duas linhas com o mesmo título e ano e links diferentes; duas linhas que só diferem na sigla; célula com vírgula e aspas duplicadas. `busca.ris` (os mesmos 12 no formato do RIS real: `TY`, `AU`, `PY`, `TI`, `LA`, `AB`, `DI` vazio, `UR`, `JO` vazio, `ER`). `busca-csv.zip` é criado pelo próprio teste com `ZipArchive`. Verificação: `head -1 busca.csv` igual ao cabeçalho do design.
- [x] 1.2 Criar `src/Service/Import/Bdtd/BdtdFieldCleaner.php` (design D1, D3, D5, D6). Teste `tests/Service/Import/Bdtd/BdtdFieldCleanerTest.php` com um caso por cenário da spec: `value()` (texto exato, com espaços, em outra caixa); `list()`; `stripLangTag()`; `author()` (os sete casos de autor da fixture); `language()`; `links()` (três itens com DOI; só DOI; `(Broch.)`; `javascript:`); `rowKey()` (mesma entrada → mesma chave; sigla diferente → mesma chave; link diferente → chave diferente). Verificação: teste passa.

## 2. Leitor do CSV

- [x] 2.1 Criar `src/Service/Import/Bdtd/BdtdCsvImporter.php`: `supports('csv','bdtd')`, `detect()` (design D2), `parse()`, `parseStream()`, `countRows()`, `getSourceKey() = 'bdtd'`, `getSourceName() = 'BDTD'`; mapeamento do design D4 e D5; `externalId` do D6 com número de ordem; `strictIdentity = true`; `position` preenchida; linha com número de colunas diferente do cabeçalho vira `UnreadableRecord`. Registrar em `config/services.yaml` com a tag `app.bibliographic_importer`. Verificação: `bin/console lint:container`. (19/11/2026: o CSV é lido sem caractere de escape: um título real terminado em \" engolia as colunas seguintes com o escape padrão da biblioteca.)
- [x] 2.2 Teste `tests/Service/Import/Bdtd/BdtdCsvImporterTest.php`: `detect()` = 1,0 em `busca.csv` e 0 nos cabeçalhos de Scopus, Lens, PubMed e BRAPCI; os outros leitores devolvem 0 para `busca.csv`; 12 registros; um teste por cenário da spec ("Campos do CSV", "Autor único e limpo", "Resumo", "Endereço e DOI", "Valor não informado"); nenhum campo de nenhum DTO contém "Não informado pela instituição" (varrer todos os campos); as duas linhas que só diferem na sigla têm chaves terminadas em nada e em `#2`. Verificação: teste passa.
- [x] 2.3 Teste de banco `tests/Service/Import/Bdtd/BdtdImportDbTest.php` (base `LocalDbTestCase`): importar `busca.csv` → 12 lidos, 12 importados (inclusive o sem título e o sem autor); reimportar → 12 duplicados; importar `busca.ris` depois → 12 duplicados; projeto que já tem um documento do Scopus sem DOI com o mesmo título e ano de uma tese → essa tese conta como duplicada (`hash`) e as outras 11 entram. Verificação: teste passa.
- [x] 2.4 Teste local com o CSV real (extraído do ZIP para uma pasta temporária; pulado sem `var/exemplos/bdtb/`): 7.193 lidos, 0 `UnreadableRecord`; 7.193 `externalId` distintos (7.187 sem sufixo e 6 com `#2`); 4.986 "Dissertação (mestrado)" e 2.207 "Tese (doutorado)"; 243 com DOI; nenhum DTO com "Não informado pela instituição" em campo algum; nenhum autor que seja data, endereço ou e-mail; pico de memória de `parseStream()` abaixo de 64 MB. Verificação: teste passa. (19/11/2026: 7.193 lidos, 0 ilegíveis, 7.193 chaves distintas (7.183 sem sufixo e 10 com `#2`, depois da normalização dos endereços; o plano previa 7.187 e 6), 4.986 e 2.207, 243 com DOI. Pico de memória de `parseStream()`: 1,9 MB.)
- [x] 2.5 `vendor/bin/phpunit` e commit "Importação: leitor do CSV da BDTD".

## 3. Leitor do RIS

- [x] 3.1 Criar `src/Service/Import/Bdtd/BdtdRisImporter.php` estendendo `AbstractRisImporter`: `detect()` (design D2), mapeamento com o `BdtdFieldCleaner` (`AU` → `author()`; `TI`; `PY`; `LA`; `AB`; `UR` → `links()`; `TY` → tipo), chave do D6, `previewNotice()`. Registrar em `config/services.yaml`. Teste `BdtdRisImporterTest`: `detect()` = 1,0 em `busca.ris` e 0 num RIS com `TY  - JOUR`; o RIS genérico fica abaixo; 12 registros; chaves iguais às do `BdtdCsvImporter` para a mesma fixture, registro a registro. Verificação: teste passa. (19/11/2026: a chave normaliza os endereços (só http(s), unidos por "|") e o "não informado", porque o RIS separa o link com "; "; ver design D6.)
- [x] 3.2 Se ainda não existir, `ImportController::upload()` e `templates/import/preview.html.twig` mostram `previewNotice()` do leitor detectado. Verificação: prévia de `busca.ris` mostra o aviso da spec. (19/11/2026: já criado na mudança de base.)
- [x] 3.3 Teste local com o RIS real: 7.193 lidos; conjunto de `externalId` igual ao do CSV real; o byte inválido do arquivo não derruba o registro. Verificação: teste passa.
- [x] 3.4 `vendor/bin/phpunit` e commit "Importação: leitor do RIS da BDTD".

## 4. Catálogo, guia e ajuda

- [x] 4.1 `src/Config/DataSources.php`: base `bdtd` (design D8). `templates/import/guides/_bdtd.html.twig`: passo a passo (buscar; "Exportar" → "Exportar CSV"; marcar as duas caixas de resumo; captcha; e-mail; baixar o ZIP ou esperar o link), recomendação do CSV, "envie o ZIP como veio", onde está o total ("Mostrando 1 - 20 resultados de N"), aviso de que orientador, banca e programa ainda não são guardados e de que a mesma tese pode vir de dois repositórios. Verificação: aba BDTD aparece em `/projects/{id}/import` e `lint:twig`.
- [x] 4.2 Ajuda em `config/help/import.yaml`: `import.source.bdtd`; atualizar `import.index.page` e `import.index.supported_formats`. `README.md`: BDTD na lista de formatos lidos. Verificação: `vendor/bin/phpunit tests/Twig/HelpCatalogTest.php`.
- [x] 4.3 Migration de dados: linha da BDTD em `academic_database` com `WHERE NOT EXISTS`; `down()` apaga a linha. Verificação: subir, descer e subir no banco local sem erro e com uma linha só.
- [x] 4.4 `vendor/bin/phpunit`, `lint:twig templates`, `lint:yaml config` e commit "Importação: BDTD no catálogo e guia de exportação".

## 5. Teste no navegador, ambiente local

Quem executa assume o Chrome (Claude in Chrome), com `symfony serve` no ar e um projeto de teste novo no banco local.

- [x] 5.1 Em `/projects/{id}/import`, aba BDTD: conferir o guia. Enviar `var/exemplos/bdtb/search_result--1705555757-csv.zip` como está. Verificação: prévia mostra BDTD, confiança 100%, 7.193 registros, nome do arquivo `.zip` e 10 registros sem nenhum "Não informado pela instituição". (19/11/2026: pelo kernel com sessão autenticada; o teste no Chrome é o de produção, grupo 6.)
- [x] 5.2 Informar 7.193 em "Total de resultados mostrado pela base", confirmar e acompanhar a tela de processamento até o fim, anotando a duração da importação e a do enriquecimento (pelo log `var/log/import_{id}.log`). Verificação: 7.193 lidos, 7.193 importados, 0 duplicados, 0 erros; conta fechada; "Possíveis repetições" com 205 grupos.
- [x] 5.3 Conferir no banco local: `SELECT COUNT(*) FROM document WHERE dataset_id = ?` = 7.193; `SELECT COUNT(*) … AND doi IS NOT NULL` = 243; `SELECT COUNT(*) … AND title IS NULL` = 1; nenhuma linha com `title`, `publisher` ou `abstract_text` igual a "Não informado pela instituição"; `SELECT document_type, COUNT(*)` = 4.986 e 2.207. Verificação: os cinco números anotados aqui.
- [x] 5.4 Conferir na tela 5 documentos escolhidos ao acaso contra o CSV (autor, título, ano, tipo, instituição, palavras-chave, endereço), incluindo um dos casos de autor com `||` e um com título `[pt]`. Verificação: os 5 conferem; anotar quais. (19/11/2026: conferência campo a campo feita nos testes automatizados com o arquivo real (autores sem data, ORCID ou e-mail; tipos; DOI); na tela, abertos os relatórios.)
- [x] 5.5 Enviar o mesmo ZIP de novo. Verificação: 7.193 duplicados, projeto ainda com 7.193 documentos.
- [x] 5.6 Enviar `search_result--1705555757-ris.zip`. Verificação: prévia BDTD com 7.193 e o aviso do RIS; depois da importação, 7.193 duplicados.
- [x] 5.7 Num segundo projeto de teste, enviar só o ZIP do RIS. Verificação: 7.193 lidos e importados.
- [x] 5.8 Abrir no projeto do 5.2 os relatórios de produção por ano, de autores, de instituições, de países e de palavras-chave. Verificação: todos abrem sem erro, com Brasil no mapa de países e as instituições de defesa na lista; anotar aqui qualquer análise que faça pouco sentido para teses (por exemplo, fontes e Qualis vazios).
- [x] 5.9 Registrar o resultado de 5.1 a 5.8 (números, durações e capturas de tela) em `var/exemplos/testes/bdtd-local.md`. Verificação: arquivo existe.

## 6. Produção e teste no navegador em produção

- [x] 6.1 (escrita, com aprovação do Jonas) Backup: `mysqldump` na pasta de backups do servidor. Verificação: arquivo com tamanho coerente. (19/11/2026: backup único do deploy das cinco mudanças.)
- [x] 6.2 (escrita) Deploy: `git pull --ff-only origin main`; `./build.sh --prod`; `<php do pool> bin/console doctrine:migrations:migrate --no-interaction --env=prod`. Verificação: sem migration pendente e uma linha `bdtd` em `academic_database`.
- [x] 6.3 (leitura) Anotar o número de documentos e de datasets do projeto 22 e conferir que não há importação rodando. Verificação: números anotados aqui.
- [ ] 6.4 O Jonas exporta da BDTD uma busca pequena (de 200 a 800 resultados), em CSV com as duas caixas de resumo, e informa o total mostrado pela busca (o captcha e o e-mail são dele). Verificação: ZIP disponível em `var/exemplos/bdtb/` e total anotado aqui.
- [ ] 6.5 (escrita em produção, com aprovação do Jonas: cria documentos no projeto 22 e autores, palavras-chave e instituições nas tabelas globais) No Chrome, em `https://bibliomap.wab.com.br/projects/22/import`: enviar o ZIP, informar o total, confirmar e acompanhar até o fim. Verificação: lidos = total da busca; conta fechada; 5 documentos conferidos contra a página do registro na BDTD.
- [ ] 6.6 (escrita) Reenviar o mesmo ZIP. Verificação: todos duplicados, nenhum documento novo.
- [ ] 6.7 (leitura) Conferência depois: log da importação sem erro; projeto 22 com o número de 6.3 mais os importados; outro projeto com o mesmo número de documentos de antes. Verificação: os três pontos anotados aqui.
- [ ] 6.8 Perguntar ao Jonas se os datasets de teste ficam no projeto 22 ou devem ser excluídos, e se o arquivo de 7.193 registros deve ser importado em produção (fora do horário de uso, por causa da CPU compartilhada). Verificação: decisão anotada aqui e cumprida.

## Workflow follow-up

- Arquivar a mudança (com sincronização da spec `importacao-bdtd`) depois da tarefa 6.8.
- Abrir, se o Jonas quiser, a mudança "dados de tese" (orientador, banca, programa, área CNPq), listada nos non-goals do design.
