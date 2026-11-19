# Proposal

## Why

O Jonas relatou que o envio de material do OpenAlex "parece não estar funcionando". A leitura do código em 19/11/2026 mostra que não é um defeito pontual: **o BiblioMap não tem leitor para o OpenAlex**. A base está no catálogo e tem uma aba na tela de importação, mas a própria aba diz "Ainda sem leitor no BiblioMap", e o que acontece com cada arquivo hoje é:

- **CSV do OpenAlex:** nenhum leitor o reconhece; a importação termina em erro com 0 documentos.
- **JSON da API:** recusado no envio pela extensão.
- **RIS:** recusado no envio pela extensão.
- **TXT "Web of Science plain text" do OpenAlex:** é o caso enganoso. O arquivo começa com `FN OpenAlex` e `VR 1.0`, e o leitor da Web of Science o aceita com 90% de confiança. Os registros entram como se fossem da Web of Science, sem o identificador do OpenAlex e, na variante que grava as citações em `CT`, sem citações.

Esta mudança cria os leitores do OpenAlex e corrige a detecção do TXT. Depende de `importacao-base-novos-formatos`.

O que está verificado em arquivo real é o JSON da API (1.040 registros baixados em 19/11/2026, em `var/exemplos/pesquisa/openalex-api/`). O CSV e o RIS da interface também estão verificados, nos arquivos que o Jonas exportou no mesmo dia (149 e 9.540 registros, em `var/exemplos/openalex/`). Só o TXT no formato Web of Science ainda não foi visto em arquivo real: o leitor dele só é fechado depois que o Jonas exportar uma amostra.

## What Changes

- Leitor do **JSON** do OpenAlex (resposta da API com `results`, uma lista de works ou um work por linha), lido em fluxo, com título, autores, instituições, países, periódico, ISSN, volume, número, páginas, DOI, tipo, idioma, acesso aberto, citações, palavras-chave, referências e o resumo reconstruído do índice invertido.
- Leitor do **CSV** exportado pela interface, por nome de coluna, aceitando as três gerações de cabeçalho conhecidas; recusa com mensagem clara o CSV sem `Work ID` nem `DOI`.
- Leitor do **TXT** no formato Web of Science gerado pelo OpenAlex (`FN OpenAlex`), gravando a base como OpenAlex, o identificador de `UT` e as citações de `TC` ou `CT`. O leitor da Web of Science deixa de aceitar esse arquivo.
- Leitor do **RIS** do OpenAlex, com aviso de que esse formato não traz citações nem identificador.
- Identidade pelo Work ID do OpenAlex.
- Aba do OpenAlex com guia de exportação de verdade (colunas a incluir, onde baixar, onde ver o total) no lugar do aviso "sem leitor".

## Capabilities

### New Capabilities
- `importacao-openalex`: leitura dos arquivos do OpenAlex (JSON da API, CSV, TXT no formato Web of Science e RIS da interface), com a identidade pelo Work ID e a reconstrução do resumo.

### Modified Capabilities
- `importacao-de-datasets`: o requisito "Prévia antes de importar um arquivo" ganha o cenário do TXT do OpenAlex, que deixa de ser identificado como Web of Science.

## Impact

- Código novo: `src/Service/Import/OpenAlex/OpenAlexJsonImporter.php`, `OpenAlexCsvImporter.php`, `OpenAlexWosImporter.php`, `OpenAlexRisImporter.php`, `OpenAlexWorkMapper.php`; registro em `config/services.yaml`.
- Código alterado: `src/Service/Import/WosImporter.php` (detecção e extração do leitor de marcações para reuso), `src/Config/DataSources.php` (`openalex` com `hasReader`, formatos e limite), `templates/import/guides/_openalex.html.twig`, `templates/import/preview.html.twig` (texto de formato não reconhecido), `config/help/import.yaml` (`import.source.openalex`, `import.index.supported_formats`, `import.preview.detected_source`, `import.preview.source`), `README.md`.
- Dependência nova: `halaxa/json-machine` (leitura de JSON em fluxo, PHP puro, sem extensão).
- Banco: `UPDATE` da linha `openalex` de `academic_database` (formatos e colunas-assinatura). Tabela global; nenhum número de projeto muda.
- Tabelas de autoridade globais: autores em ordem direta e por extenso, e palavras-chave atribuídas pelo OpenAlex (em inglês, em minúsculas) entram como palavras-chave indexadas.
- Datasets antigos importados de um TXT do OpenAlex como "Web of Science" não são alterados; a ajuda orienta a reimportar.
