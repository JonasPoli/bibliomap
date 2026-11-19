# Tasks

Tarefas que ficaram abertas nas mudanças de importação arquivadas em 19/11/2026. Cada uma indica a tarefa de origem. Em produção, dizer se a ação é leitura ou escrita; escrita só com aprovação do Jonas.

Estado de produção em 19/11/2026, depois do deploy e dos testes: 117.382 documentos; projeto 22 com 11.504 documentos e 31 datasets (57: BRAPCI 884 lidos, 883 importados; 58: o mesmo arquivo, 884 duplicados; 59: LISTA 50 lidos, 24 importados; 60: OpenAlex CSV 149 lidos, 141 importados). Backup anterior ao deploy: `~/backups/bibliomap-antes-importacao-bases-novas-20261119-1721.sql.gz`.

## 1. Arquivos maiores no projeto 22 (decisão do Jonas)

- [ ] 1.1 Perguntar ao Jonas se o ZIP da BDTD de 7.193 teses (`var/exemplos/bdtb/search_result--1705555757-csv.zip`) deve ser importado no projeto 22 ou se ele prefere exportar uma busca menor. Se sim (escrita, fora do horário de uso, por causa da CPU compartilhada): backup, importar com o total 7.193, conferir 7.193 lidos e a conta fechada, reenviar o ZIP e conferir todos duplicados, conferir que os outros projetos não mudaram. Origem: `importacao-bdtd` 6.4 a 6.8. Verificação: decisão e números anotados aqui.
- [ ] 1.2 Perguntar ao Jonas se o RIS do OpenAlex de 9.540 works (`var/exemplos/openalex/works-2026-04-18T01-00-07.ris`, busca de abril de 2026) deve entrar no projeto 22 e se as 4 páginas JSON da revista Em Questão servem ao projeto. Se sim (escrita, com backup antes): importar e conferir os totais e os duplicados contra o CSV já importado (dataset 60). O RIS tem 21 MB: o envio pelo navegador funciona, mas não pela ferramenta de envio do Claude in Chrome (limite de 10 MB). Origem: `importacao-openalex` 8.4 e 8.5. Verificação: decisão e números anotados aqui.
- [ ] 1.3 Perguntar ao Jonas se o CSV da EBSCO com várias bases (`EBSCO-Metadata-11_19_2026 (2).csv`, 44 de 50 registros fora da LISTA) deve entrar no projeto 22; o ideal é refazer a busca limitada à LISTA. Origem: `importacao-lista-ebsco` 6.4. Verificação: decisão anotada aqui.
- [ ] 1.4 (escrita, com aprovação) Reenviar em produção o CSV da LISTA já importado e conferir 50 duplicados. Origem: `importacao-lista-ebsco` 6.5. Verificação: dataset novo com 50 duplicados e nenhum documento novo.

## 2. Datasets de teste no projeto 22

- [ ] 2.1 Perguntar ao Jonas se o dataset 58 (reenvio do CSV da BRAPCI, 884 duplicados, nenhum documento) e os demais datasets criados pelos testes ficam ou devem ser excluídos. A exclusão é feita por ele ou com a confirmação dele, pela tela de importações. Origem: `importacao-brapci` 7.7, `importacao-lista-ebsco` 6.7, `importacao-openalex` 8.7. Verificação: decisão anotada aqui e cumprida.

## 3. TXT do OpenAlex com amostra real

- [ ] 3.1 O Jonas exporta em "Text (Web of Science plain text)" a busca dos 149 works do CSV (`works-2026-11-19T16-08-23.csv`) para `var/exemplos/openalex/`. Registrar aqui as duas primeiras linhas, as marcações, onde vêm o Work ID e as citações e o número de `ER`. Origem: `importacao-openalex` 5.1. Verificação: ficha registrada.
- [ ] 3.2 Ajustar `src/Service/Import/OpenAlex/OpenAlexWosImporter.php` ao arquivo real, se preciso, e acrescentar o teste local com ele: 149 lidos, base OpenAlex, citações preenchidas, 10 works comparados com o CSV real. Origem: `importacao-openalex` 5.5. Verificação: `vendor/bin/phpunit` verde.
- [ ] 3.3 Enviar o TXT real num projeto de teste local e conferir que a prévia indica OpenAlex e lidos = total da busca. Origem: `importacao-openalex` 7.7. Verificação: resultado anotado aqui.

## 4. Exportação da EBSCO

- [ ] 4.1 Perguntar ao Jonas e anotar: por onde ele chega à LISTA (Portal CAPES, biblioteca da UFSCar, outro); quantos registros a tela deixa exportar por vez; se a exportação oferece RIS; onde aparece o total de resultados. Atualizar `templates/import/guides/_lista.html.twig`, a ajuda `import.source.lista` e o campo `limit` da LISTA em `src/Config/DataSources.php` com as respostas. Origem: `importacao-lista-ebsco` 1.1. Verificação: `HelpCatalogTest` verde e guia atualizado em produção.

## 5. Escape por barra invertida nos leitores antigos

- [ ] 5.1 Apresentar ao Jonas o achado: os leitores de CSV do Scopus, do Lens e do PubMed usam o caractere de escape padrão da biblioteca (barra invertida); uma célula terminada em `\"` engole as colunas seguintes do registro (visto num título real da BDTD). As amostras reais de Scopus e Lens não têm o caso. Se ele aprovar, abrir uma mudança própria que leia esses CSV sem caractere de escape, com a regressão de `var/exemplos/regressao/` (mesmos DTOs e mesmos hashes). Verificação: decisão anotada aqui.
