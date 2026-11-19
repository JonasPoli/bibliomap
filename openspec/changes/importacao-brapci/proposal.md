# Proposal

## Why

A BRAPCI (Base de Dados Referenciais de Artigos de Periódicos em Ciência da Informação, brapci.inf.br) é a principal base brasileira da área do mestrado do Jonas, e o BiblioMap não lê nenhum dos arquivos que ela exporta. Os arquivos reais (884 registros exportados pelo Jonas e 1.482 baixados na pesquisa de 19/11/2026, em `var/exemplos/brapci/` e `var/exemplos/pesquisa/brapci-todos-formatos/`) têm particularidades que nenhum leitor atual trata: o CSV só usa `\r` como quebra de linha e tem uma vírgula sobrando no fim de cada linha; o "XLS" é uma tabela HTML em Windows-1252; a coluna `URL` mistura DOI e handle, com DOIs repetidos e malformados; e o RIS vem sem acentos e sem o identificador em campo próprio.

Depende da mudança `importacao-base-novos-formatos` (extensões, codificação, leitor de RIS, identidade pelo identificador da base e conferência).

## What Changes

- Leitor do **CSV** da BRAPCI (formato recomendado): identifica cada registro pelo `ID`, lê tipo, ano, publicação, autores, título, palavras-chave, resumo, volume e número (da coluna `LEGEND`) e endereço.
- Leitor do **XLS** da BRAPCI (tabela HTML em Windows-1252), com o mesmo mapeamento do CSV.
- Leitor do **RIS** da BRAPCI, com o identificador tirado da marcação `UR` e o tipo de `M3`, e aviso de que esse formato vem sem acentos.
- Regra do DOI: só é gravado quando bem formado, usado por um único registro do arquivo e o registro é artigo; caso contrário o documento fica sem DOI e com o handle da BRAPCI como endereço.
- BRAPCI no catálogo de bases, com guia de exportação (cesta, botão CSV, limites de 1.000 na busca e de 2.000 na exportação sem login) e contagem por tipo para conferir com os cartões da cesta.
- Linha da BRAPCI na tabela global `academic_database` (migration de dados, uma linha).

## Capabilities

### New Capabilities
- `importacao-brapci`: leitura dos arquivos exportados da cesta da BRAPCI (CSV, XLS e RIS), com a identidade pelo ID da BRAPCI, a regra de aceitação do DOI e a conferência por tipo de registro.

### Modified Capabilities
<!-- Nenhuma: as regras gerais de importação mudam em importacao-base-novos-formatos. -->

## Impact

- Código novo: `src/Service/Import/Brapci/BrapciCsvImporter.php`, `BrapciXlsImporter.php`, `BrapciRisImporter.php`, `BrapciRecordMapper.php`; registro em `config/services.yaml`.
- Código alterado: `src/Config/DataSources.php` (base `brapci`), `templates/import/guides/_brapci.html.twig` (novo), `templates/dataset/show.html.twig` (contagem por tipo), `config/help/import.yaml`, `README.md` (formatos lidos).
- Banco: migration que insere a BRAPCI em `academic_database`. Tabela global: a base passa a aparecer no cadastro de bases e no vínculo de periódicos de todos os projetos; nenhum número de projeto existente muda.
- Tabelas de autoridade globais (`author_identity`, `keyword`): importar a BRAPCI cria autores e palavras-chave novos, como qualquer importação. Os nomes vêm em ordem direta e por extenso no CSV.
- Sem dependência nova.
