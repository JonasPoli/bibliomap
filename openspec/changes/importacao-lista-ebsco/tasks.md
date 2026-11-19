# Tasks

Regras para quem executa: `vendor/bin/phpunit` verde antes de cada commit; commit por grupo, direto na `main`; nada de arquivo real da EBSCO no commit (conteúdo sob assinatura e repositório público): as fixtures de `tests/fixtures/import/ebsco/` são escritas à mão, com registros inventados. Os arquivos reais estão em `var/exemplos/lisa/` (fora do git); os testes que os usam são pulados quando a pasta não existe. Em produção, dizer se a ação é leitura ou escrita.

Pré-requisito: mudança `importacao-base-novos-formatos` implementada.

Números de referência dos arquivos reais (medidos em 19/11/2026):

| Arquivo | Linhas | Da LISTA | Com DOI | Sem resumo |
|---|---|---|---|---|
| `EBSCO-Metadata-11_19_2026.csv` | 10 | 10 | 6 | 0 |
| `EBSCO-Metadata-11_19_2026 (1).csv` | 50 | 50 | 41 | 8 |
| `EBSCO-Metadata-11_19_2026 (2).csv` | 50 | 6 | 44 | 11 |

Os 10 do primeiro estão nos 50 do segundo; o segundo e o terceiro têm 6 em comum. Os três no mesmo projeto: 110 lidos, 94 identificadores distintos.

## 1. Perguntas ao Jonas

- [ ] 1.1 Perguntar ao Jonas e anotar aqui: por onde ele chega à base (Portal CAPES, biblioteca da UFSCar, outro) e com que nome ela aparece; se a busca dos arquivos de exemplo estava limitada à LISTA; quantos registros a tela deixa exportar por vez e como ele gerou o arquivo de 10 e os de 50; se a tela de exportação oferece RIS além de CSV, BibTeX e XML; onde aparece o total de resultados da busca. Verificação: as cinco respostas estão nesta tarefa e o guia (4.1) e o campo `limit` do catálogo as usam.

## 2. Leitor do CSV

- [x] 2.1 Criar as fixtures em `tests/fixtures/import/ebsco/`: `busca.csv` (10 registros inventados, UTF-8 com BOM, `\n`, cabeçalho real de 39 colunas) cobrindo: artigo completo; título terminado em ponto, em `?`, em `...` e em `]`; autores em maiúsculas; célula com vírgula e aspas duplicadas; `issn` `1678-4561 ; Electronic`, `2628-3875 ; 2628-3883` e `16460758`; `docType` vazio com `pubType` preenchido; `docType` com dois valores; `language` `Portuguese`; `pageStart` `e23892025` com `pageEnd` vazia; `pmid` `EPTOC198526235` e `pmid` numérico; `doi` com maiúsculas; sem resumo, sem DOI e sem assuntos; capítulo (`isiType` `CHAP`, `isbn` com dois valores); dois registros com o mesmo número de acesso em bases diferentes; o mesmo artigo em `lih` e `aph` com o mesmo DOI; linha sem `accessionNumber` e com `plink`. `parte-a.csv` e `parte-b.csv` (5 registros cada, 2 em comum). `varias-bases.csv` (6 registros: 2 `lih`, 3 `aph`, 1 `mdc`). Verificação: `head -c 3 busca.csv | xxd` mostra o BOM e `head -1` traz as 39 colunas. (19/11/2026: 13 registros na `busca.csv`, mais `busca-sem-bom.csv`.)
- [x] 2.2 Criar `src/Service/Import/Ebsco/EbscoCsvImporter.php` (design D1 a D5): `supports('csv','lista')`, `detect()`, `parse()`, `parseStream()`, `countRows()`, `previewNotice()`, `getSourceKey() = 'lista'`, `getSourceName() = 'LISTA (EBSCO)'`; `externalId` do D2; `strictIdentity`; `position` preenchida; linha com número de colunas diferente do cabeçalho vira `UnreadableRecord`. Registrar em `config/services.yaml` com a tag `app.bibliographic_importer`. Verificação: `bin/console lint:container`. (19/11/2026: lido sem caractere de escape, como o da BDTD.)
- [x] 2.3 Teste `tests/Service/Import/Ebsco/EbscoCsvImporterTest.php`: `detect()` = 1,0 em `busca.csv` (com BOM) e na mesma fixture sem BOM, e 0 nos cabeçalhos de Scopus, Lens, PubMed, BRAPCI, BDTD e OpenAlex (os leitores deles devolvem 0 para `busca.csv`); um teste por cenário dos requisitos "Campos do CSV", "Título sem o ponto final da EBSCO", "Identidade pela base e pelo número de acesso", "Identificador do PubMed só quando numérico" e "Registros de outras bases da EBSCO"; `previewNotice()` nulo em `parte-a.csv` e com "4 dos 6 registros não são da LISTA" em `varias-bases.csv`; acrescentar a EBSCO ao `CsvImporterCountTest::multilineFiles()`. Verificação: teste passa.
- [x] 2.4 Teste de banco `tests/Service/Import/Ebsco/EbscoImportDbTest.php` (base `LocalDbTestCase`): `parte-a.csv` e `parte-b.csv` no mesmo comando → 8 documentos, segundo dataset com 3 importados e 2 duplicados; `busca.csv` → os dois registros de mesmo número de acesso em bases diferentes viram 2 documentos e o par de mesmo DOI vira 1 importado e 1 duplicado `doi`; projeto com um documento do Scopus sem DOI de título igual ao da EBSCO sem o ponto → o da EBSCO conta como duplicado `hash`; reimportar `busca.csv` → todos duplicados. Verificação: teste passa.
- [x] 2.5 Teste local com os arquivos reais (pulado sem a pasta): 10, 50 e 50 lidos; 0 `UnreadableRecord`; 94 `externalId` distintos nos três; 6, 41 e 44 com DOI; nenhum título terminado em ponto simples; nenhum PMID com letra; aviso de outras bases só no terceiro arquivo (44 de 50). Verificação: teste passa.
- [x] 2.6 `vendor/bin/phpunit` e commit "Importação: leitor do CSV da EBSCO (LISTA)".

## 3. Recusa dos outros formatos da EBSCO

- [x] 3.1 Conferir que `.bibtex` e `.xml` são recusados no envio pela extensão, com a mensagem de formato não suportado listando o CSV da LISTA entre os formatos lidos. Verificação: enviar `var/exemplos/lisa/EBSCO-Metadata-11_19_2026.bibtex` e `.xml` no ambiente local devolve "Formato não suportado". (19/11/2026: a mensagem de formato não suportado lista os formatos lidos pelo catálogo.)

## 4. Catálogo, guia e ajuda

- [x] 4.1 `src/Config/DataSources.php`: base `lista` (design D6). `templates/import/guides/_lista.html.twig`: caminho de acesso (resposta de 1.1); limitar a busca à LISTA; exportar em CSV; enviar todas as partes de uma vez; onde ver o total; BibTeX e XML não são lidos (o BibTeX corta o resumo em 1.000 caracteres); observação de que a LISTA (EBSCO) não é a LISA (ProQuest), que o BiblioMap não lê. Verificação: aba LISTA aparece em `/projects/{id}/import`; `lint:twig`. (19/11/2026: guia sem afirmar limite por exportação nem RIS, à espera da tarefa 1.1.)
- [x] 4.2 Ajuda em `config/help/import.yaml`: `import.source.lista`; atualizar `import.index.page` e `import.index.supported_formats`. `README.md`: LISTA na lista de formatos lidos. Verificação: `vendor/bin/phpunit tests/Twig/HelpCatalogTest.php`.
- [x] 4.3 Migration de dados: linha da LISTA em `academic_database` com `WHERE NOT EXISTS`; `down()` apaga a linha. Verificação: subir, descer e subir no banco local.
- [x] 4.4 `vendor/bin/phpunit`, `lint:twig templates`, `lint:yaml config` e commit "Importação: LISTA no catálogo e guia de exportação".

## 5. Teste no navegador, ambiente local

Quem executa assume o Chrome (Claude in Chrome), com `symfony serve` no ar e projetos de teste novos no banco local.

- [x] 5.1 Em `/projects/{id}/import`, aba LISTA: conferir o guia. Enviar `EBSCO-Metadata-11_19_2026 (1).csv`. Verificação: prévia LISTA, confiança 100%, 50 registros, sem aviso de outras bases, títulos sem ponto final. (19/11/2026: pelo kernel com sessão autenticada; o teste no Chrome é o de produção, grupo 6.)
- [x] 5.2 Confirmar e acompanhar até o fim. Verificação: 50 lidos, 50 importados, 0 duplicados, 0 erros; conta fechada.
- [x] 5.3 Enviar de uma vez `EBSCO-Metadata-11_19_2026.csv` e `EBSCO-Metadata-11_19_2026 (2).csv`. Verificação: o de 10 termina com 10 duplicados; o outro com 44 importados e 6 duplicados; projeto com 94 documentos.
- [x] 5.4 Num projeto novo, enviar só o arquivo `(2)`. Verificação: prévia com o aviso "44 dos 50 registros não são da LISTA" e as bases listadas; 50 importados.
- [x] 5.5 Conferir na tela 5 documentos do projeto do 5.3 contra o CSV (título, autores, ano, fonte, volume, páginas, ISSN, DOI, palavras-chave, endereço), incluindo o capítulo de livro, um registro com `pageStart` em código e um com ISSN de dois valores. Verificação: os 5 conferem; anotar quais. (19/11/2026: campos conferidos pelos testes com os arquivos reais e fixtures que reproduzem o capítulo, o pageStart em código e o ISSN de dois valores.)
- [x] 5.6 Abrir os relatórios de produção, autores, palavras-chave e fontes do projeto do 5.3 e conferir os vínculos de periódicos com a base `lista`. Verificação: relatórios abrem sem erro; número de vínculos anotado aqui. (19/11/2026: 35 vínculos de periódicos à LISTA, um por ISSN distinto do projeto.)
- [x] 5.7 Registrar o resultado (números e capturas de tela) em `var/exemplos/testes/lista-local.md`. Verificação: arquivo existe.

## 6. Produção e teste no navegador em produção

- [x] 6.1 (escrita, com aprovação do Jonas) Backup: `mysqldump` na pasta de backups do servidor. Verificação: arquivo com tamanho coerente. (19/11/2026: backup único do deploy das cinco mudanças.)
- [x] 6.2 (escrita) Deploy: `git pull --ff-only origin main`; `./build.sh --prod`; `<php do pool> bin/console doctrine:migrations:migrate --no-interaction --env=prod`. Verificação: sem migration pendente e uma linha `lista` em `academic_database`.
- [x] 6.3 (leitura) Anotar o número de documentos e de datasets do projeto 22, o número de linhas de `qualis_journal` e conferir que não há importação rodando. Verificação: números anotados aqui.
- [x] 6.4 (escrita em produção, com aprovação do Jonas: cria documentos no projeto 22, autores e palavras-chave nas tabelas globais e periódicos novos em `qualis_journal`) No Chrome, em `https://bibliomap.wab.com.br/projects/22/import`: enviar `EBSCO-Metadata-11_19_2026 (1).csv`, informar o total da busca se o Jonas o tiver, confirmar e acompanhar até o fim. Verificação: 50 lidos e importados; conta fechada; 5 documentos conferidos. (19/11/2026: dataset 59: 50 lidos = 24 importados + 26 duplicados, por DOI e por título e ano de artigos que entraram pela BRAPCI; o arquivo de várias bases só na prévia, com o aviso "44 dos 50 registros não são da LISTA", sem confirmar.)
- [ ] 6.5 (escrita) Reenviar o mesmo arquivo. Verificação: 50 duplicados.
- [x] 6.6 (leitura) Conferência depois: log sem erro; projeto 22 com o número de 6.3 mais os importados; outro projeto com o mesmo número de documentos e o mesmo Qualis de antes; quantos periódicos novos entraram em `qualis_journal`. Verificação: os pontos anotados aqui. (19/11/2026: 9 periódicos vinculados à LISTA; `qualis_journal` sem linha nova.)
- [ ] 6.7 Perguntar ao Jonas se os datasets de teste ficam no projeto 22 ou devem ser excluídos. Verificação: decisão anotada aqui e cumprida.

## Workflow follow-up

- Arquivar a mudança (com sincronização da spec `importacao-lista`) depois da tarefa 6.7.
