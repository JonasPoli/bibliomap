# Tasks

Regras para quem executa: `vendor/bin/phpunit` verde antes de cada commit; commit por grupo, direto na `main`; nada de arquivo real da BRAPCI no commit (repositório público): as fixtures de `tests/fixtures/import/brapci/` são escritas à mão, com dados inventados, reproduzindo cada particularidade. Os arquivos reais estão em `var/exemplos/brapci/` e `var/exemplos/pesquisa/brapci-todos-formatos/` (fora do git); os testes que os usam são pulados quando a pasta não existe. Em produção, dizer se a ação é leitura ou escrita.

Pré-requisito: mudança `importacao-base-novos-formatos` implementada (extensões, `DeclaresEncodingInterface`, `AbstractRisImporter`, `strictIdentity`, `UnreadableRecord`, conferência, catálogo com `hasReader`).

Números de referência dos arquivos reais (medidos em 19/11/2026):

| Arquivo | Registros | Tipos | DOI aceitos pela regra |
|---|---|---|---|
| `var/exemplos/brapci/brapci_20261119-121526.csv` | 884 | 663 Article, 211 Proceeding, 7 Book, 3 BookChapter | medir na tarefa 2.6 |
| `var/exemplos/brapci/brapci_20261119-121529.xls` | 884 | os mesmos | os mesmos |
| `…/brapci_export_csv_1482reg_bibliometria_completo.csv` | 1.482 | 1.124 Article, 349 Proceeding, 6 Book, 3 BookChapter | medir na tarefa 2.6 |
| `…/brapci_export_ris_73reg_bibliometria.ris` | 73 | | 0 |

## 1. Mapeador e fixtures

- [ ] 1.1 Criar as fixtures em `tests/fixtures/import/brapci/`: `cesta.csv` (10 registros inventados, quebras só `\r`, vírgula final, `\r\r` no fim) cobrindo: Article com `v.` e `n.`; `n. esp.`; `n. ,` (vazio); sem `v.`; Proceeding com `SESSION` composta; Book com `PUBLICATION` = `LIVRO`; BookChapter; autores com ` (Org.)`; título `:: Sem titulo ::`; `AUTHORS` vazio; `KEYWORDS` vazio; `&nbsp;`, `&quot;` e `&#39;` no texto; dois IDs com o mesmo título e ano; dois IDs com o mesmo DOI; DOI malformado (`https://doi.org/10.18617/`); DOI válido e único num Article; DOI válido e único num Book. `cesta-crlf.csv` (o mesmo, com `\r\n` e sem vírgula final). `cesta.xls` (os mesmos 10, tabela HTML em Windows-1252, um resumo com `p < 0,01`). `cesta.ris` (4 registros, só `\r`, sem acentos, um sem `UR`). Verificação: `file` mostra as codificações e `grep -c` confirma que `cesta.csv` não tem `\n`.
- [ ] 1.2 Criar `src/Service/Import/Brapci/BrapciRecordMapper.php` com o mapeamento do design D4 e a regra do DOI do D3. Teste `tests/Service/Import/Brapci/BrapciRecordMapperTest.php`, um caso por linha da tabela do design D4 e por cenário da spec ("Campos do CSV", "DOI só quando confiável", "Valores padrão da BRAPCI"), incluindo as variantes de `LEGEND`: `v. 31, n. 1, 2017`; `n. esp., 2019`; `v. 7, n. , 2026`; `v. 12, n. no 12, 2010`; `Título do livro, 2015`. Verificação: teste passa.

## 2. Leitor do CSV

- [ ] 2.1 Criar `src/Service/Import/Brapci/BrapciLineEndingFilter.php` (filtro de stream que troca `\r\n` e `\r` por `\n`, guardando o último byte entre blocos). Teste com um stream de blocos de 3 bytes: `a\rb`, `a\r\nb` partido entre blocos, `a\r\r`. Verificação: teste passa.
- [ ] 2.2 Criar `src/Service/Import/Brapci/BrapciCsvImporter.php` (design D2): `supports('csv','brapci')`, `detect()`, `parse()`, `parseStream()`, `countRows()`, `countByType()`, `getSourceKey()`, `getSourceName() = 'BRAPCI'`; passada prévia dos DOIs compartilhados (D3); `strictIdentity = true`; `position` preenchida; linha malformada vira `UnreadableRecord`. Registrar em `config/services.yaml` com a tag `app.bibliographic_importer`. Verificação: `bin/console lint:container`.
- [ ] 2.3 Teste `tests/Service/Import/Brapci/BrapciCsvImporterTest.php`: `detect()` = 1,0 em `cesta.csv` e `cesta-crlf.csv`, e 0 nos cabeçalhos de Scopus, Lens e PubMed (usar os do `CsvImporterCountTest`); os leitores de Scopus, Lens e PubMed devolvem 0 para `cesta.csv`; `countRows()` = 10 e `parseStream()` entrega 10 nos dois arquivos; `countByType()` confere; linha com 5 campos vira `UnreadableRecord` com a posição certa. Verificação: teste passa.
- [ ] 2.4 Acrescentar a BRAPCI ao `CsvImporterCountTest::multilineFiles()` (campo entre aspas com quebra de linha interna num arquivo com `\n`). Verificação: teste passa.
- [ ] 2.5 Teste de banco `tests/Service/Import/Brapci/BrapciImportDbTest.php` (base `LocalDbTestCase`): importar `cesta.csv` num projeto vazio → 10 lidos, 10 importados, 0 duplicados; os dois IDs de mesmo título e ano viram 2 documentos; os dois de mesmo DOI viram 2 documentos sem DOI; reimportar → 10 duplicados; importar `cesta.xls` depois → 10 duplicados. Verificação: teste passa.
- [ ] 2.6 Teste local com os arquivos reais (pulado sem a pasta): 884 e 1.482 registros lidos; 0 `UnreadableRecord`; 884 e 1.482 `externalId` distintos; contagem por tipo igual à da tabela acima; registrar aqui quantos DOIs a regra aceitou em cada arquivo e conferir à mão 5 deles em doi.org (leitura). Verificação: teste passa e os 5 DOIs levam ao artigo certo.
- [ ] 2.7 `vendor/bin/phpunit` e commit "Importação: leitor do CSV da BRAPCI".

## 3. Leitor do XLS

- [ ] 3.1 Criar `src/Service/Import/Brapci/BrapciXlsImporter.php` (design D5), implementando `DeclaresEncodingInterface` (`Windows-1252`), com `supports('xls','brapci')`, `detect()`, `parseStream()`, `countRows()`, `countByType()`. Registrar em `config/services.yaml`. Teste `BrapciXlsImporterTest`: `detect()` = 1,0 em `cesta.xls` e 0 para um arquivo que começa com a assinatura OLE2; 10 registros; acentos corretos; resumo com `p < 0,01` inteiro e registro seguinte intacto; linha com 9 células vira `UnreadableRecord`. Verificação: teste passa.
- [ ] 3.2 Teste local com o arquivo real: 884 registros; os mesmos 884 `externalId` do CSV; título do primeiro registro com acento igual ao do CSV. Verificação: teste passa.
- [ ] 3.3 Conferir no envio que o `.xls` da BRAPCI passa pelo teste de codificação (o leitor declara Windows-1252) e chega à prévia. Verificação: enviar `var/exemplos/brapci/brapci_20261119-121529.xls` no ambiente local mostra prévia BRAPCI com 884.
- [ ] 3.4 `vendor/bin/phpunit` e commit "Importação: leitor do XLS da BRAPCI".

## 4. Leitor do RIS

- [ ] 4.1 Criar `src/Service/Import/Brapci/BrapciRisImporter.php` (design D6) estendendo `AbstractRisImporter`, com `previewNotice()`. Registrar em `config/services.yaml`. Teste `BrapciRisImporterTest`: `detect()` = 1,0 em `cesta.ris` e o RIS genérico fica abaixo; 4 registros, 3 válidos e 1 `UnreadableRecord` (sem `UR`); `externalId` tirado do handle; tipo de `M3`. Verificação: teste passa.
- [ ] 4.2 `ImportController::upload()` e `templates/import/preview.html.twig`: mostrar `previewNotice()` do leitor detectado, quando houver. Verificação: prévia do `cesta.ris` mostra o aviso da spec.
- [ ] 4.3 Teste local com o RIS real de 73 registros: 73 lidos, 73 `externalId` distintos, todos contidos nos IDs do CSV de 77. Verificação: teste passa.
- [ ] 4.4 `vendor/bin/phpunit` e commit "Importação: leitor do RIS da BRAPCI".

## 5. Catálogo, guia, tela e ajuda

- [ ] 5.1 `src/Config/DataSources.php`: base `brapci` (design D8). `templates/import/guides/_brapci.html.twig`: passo a passo (buscar; marcar itens ou "Selecionar os N itens"; "Ver selecionados"; botão CSV), recomendação do CSV, aviso de esvaziar a cesta antes, limites de 1.000 (busca sem login) e 2.000 (exportação), lista do que não é aceito (DOC, Citações, TT, .NET, BibTeX) e a observação de que o ano 2000 pode significar "sem ano". Verificação: aba BRAPCI aparece em `/projects/{id}/import` e `lint:twig`.
- [ ] 5.2 `DatasetController::show()` e `templates/dataset/show.html.twig`: bloco de contagem por tipo para datasets da BRAPCI (design D7). Verificação: dataset do `cesta.csv` mostra as quatro contagens.
- [ ] 5.3 Ajuda em `config/help/import.yaml`: `import.source.brapci` e `dataset.show.brapci_types` (na seção de dataset); atualizar `import.index.page` e `import.index.supported_formats` com a BRAPCI. `README.md`: BRAPCI na lista de formatos lidos. Verificação: `vendor/bin/phpunit tests/Twig/HelpCatalogTest.php`.
- [ ] 5.4 Migration de dados: linha da BRAPCI em `academic_database` com `WHERE NOT EXISTS`; `down()` apaga a linha. Verificação: migrar no banco local duas vezes seguidas (subir, descer, subir) sem erro e com uma linha só.
- [ ] 5.5 `vendor/bin/phpunit`, `lint:twig templates`, `lint:yaml config` e commit "Importação: BRAPCI no catálogo, guia de exportação e contagem por tipo".

## 6. Teste no navegador, ambiente local

Quem executa assume o Chrome (Claude in Chrome), com `symfony serve` no ar e um projeto de teste novo no banco local.

- [ ] 6.1 Abrir `/projects/{id}/import`, aba BRAPCI: conferir o guia. Enviar `var/exemplos/brapci/brapci_20261119-121526.csv`. Verificação: prévia mostra BRAPCI, confiança 100%, 884 registros e os 10 primeiros com título, autores e ano coerentes com o arquivo.
- [ ] 6.2 Informar 884 em "Total de resultados mostrado pela base", confirmar e acompanhar a tela de processamento até o fim. Verificação: 884 lidos, 884 importados, 0 duplicados, 0 erros; conta fechada sem aviso; contagem por tipo 663/211/7/3; "Possíveis repetições" lista os grupos de título + ano repetidos (14 grupos no arquivo).
- [ ] 6.3 Conferir 5 documentos escolhidos ao acaso contra o arquivo (título, autores, ano, fonte, volume, número, palavras-chave, endereço): abrir cada um na lista de documentos do projeto. Verificação: os 5 conferem; anotar os IDs conferidos aqui.
- [ ] 6.4 Enviar o mesmo CSV de novo. Verificação: 884 lidos, 0 importados, 884 duplicados; o projeto continua com 884 documentos.
- [ ] 6.5 Enviar `var/exemplos/brapci/brapci_20261119-121529.xls`. Verificação: prévia BRAPCI com 884; depois da importação, 884 duplicados.
- [ ] 6.6 Num segundo projeto de teste, enviar o CSV de 1.482 registros informando 1.482. Verificação: 1.482 lidos e importados, 0 erros.
- [ ] 6.7 Num terceiro projeto, enviar o RIS de 73 registros. Verificação: aviso na prévia; 73 lidos e importados.
- [ ] 6.8 Registrar o resultado de 6.1 a 6.7 (números e capturas de tela) em `var/exemplos/testes/brapci-local.md`. Verificação: arquivo existe com os sete resultados.

## 7. Produção e teste no navegador em produção

- [ ] 7.1 (escrita, com aprovação do Jonas) Backup: `mysqldump` na pasta de backups do servidor. Verificação: arquivo com tamanho coerente.
- [ ] 7.2 (escrita) Deploy: `git pull --ff-only origin main`; `./build.sh --prod`; `<php do pool> bin/console doctrine:migrations:migrate --no-interaction --env=prod`. Verificação: `doctrine:migrations:status` sem pendência e uma linha `brapci` em `academic_database`.
- [ ] 7.3 (leitura) Antes do teste, anotar o número de documentos e de datasets do projeto 22 e conferir que não há importação rodando. Verificação: números anotados aqui.
- [ ] 7.4 (escrita em produção, com aprovação do Jonas: cria documentos no projeto 22 e autores e palavras-chave nas tabelas globais) No Chrome, em `https://bibliomap.wab.com.br/projects/22/import`: exportar da BRAPCI uma cesta nova pela tela (busca pequena, até 200 itens, anotando o total mostrado e os quatro cartões da cesta), enviar o CSV, informar o total, confirmar e acompanhar até o fim. Verificação: lidos = total da cesta; contagem por tipo = cartões da cesta; conta fechada; 5 documentos conferidos contra a tela da BRAPCI.
- [ ] 7.5 (escrita) Reenviar o mesmo arquivo. Verificação: todos duplicados, nenhum documento novo.
- [ ] 7.6 (leitura) Conferência depois: log `var/log/import_{dataset}.log` sem erro; projeto 22 com o número de documentos de 7.3 mais os importados; outro projeto qualquer com o mesmo número de documentos de antes. Verificação: os três pontos anotados aqui.
- [ ] 7.7 Perguntar ao Jonas se os datasets de teste ficam no projeto 22 ou devem ser excluídos (a exclusão é dele ou feita com a confirmação dele). Verificação: decisão anotada aqui e cumprida.

## Workflow follow-up

- Arquivar a mudança (com sincronização da spec `importacao-brapci`) depois da tarefa 7.7.
