# Proposal

## Why

A BDTD (Biblioteca Digital Brasileira de Teses e Dissertações, bdtd.ibict.br) reúne as teses e dissertações do país, e o BiblioMap não lê os arquivos que ela exporta. O botão "Exportar" da página de resultados entrega um ZIP com um CSV ou um RIS; os dois arquivos reais exportados pelo Jonas em 19/11/2026 (7.193 registros cada, em `var/exemplos/bdtb/`) mostram o que o leitor precisa tratar: o arquivo vem compactado; não existe coluna de identificador nem DOI próprio; campos sem informação trazem o texto "Não informado pela instituição"; valores múltiplos vêm separados por `||`; a coluna de autor traz datas, e-mails e ORCID misturados; e 411 registros repetem título e ano de outro (a mesma tese em dois repositórios), o que a deduplicação atual descartaria.

Depende da mudança `importacao-base-novos-formatos` (ZIP, leitor de RIS, identidade pelo identificador da base e conferência).

## What Changes

- Leitor do **CSV** do botão "Exportar" da BDTD (formato recomendado, 25 colunas): autor, título, ano de defesa, instituição, país, tipo (dissertação ou tese), assuntos em português e em inglês, idioma, resumo, endereço e DOI (quando o endereço é um DOI).
- Leitor do **RIS** do mesmo botão (9 marcações, sem instituição nem assuntos), com aviso de que o CSV é mais completo.
- Identidade do registro: como o arquivo não traz identificador, cada linha recebe uma chave calculada de autor, título, ano, sigla da instituição e endereço. Nenhuma linha é descartada por ter o mesmo título e ano de outra.
- "Não informado pela instituição" tratado como ausência em todas as colunas, inclusive dentro de listas.
- BDTD no catálogo de bases, com guia de exportação (captcha, e-mail, ZIP, as duas caixas de resumo) e a linha na tabela global `academic_database`.

## Capabilities

### New Capabilities
- `importacao-bdtd`: leitura dos arquivos do botão "Exportar" da BDTD (CSV e RIS, soltos ou dentro do ZIP), com a identidade calculada da linha e a limpeza dos valores "não informado".

### Modified Capabilities
<!-- Nenhuma: as regras gerais mudam em importacao-base-novos-formatos. -->

## Impact

- Código novo: `src/Service/Import/Bdtd/BdtdCsvImporter.php`, `BdtdRisImporter.php`, `BdtdFieldCleaner.php`; registro em `config/services.yaml`.
- Código alterado: `src/Config/DataSources.php` (base `bdtd`), `templates/import/guides/_bdtd.html.twig` (novo), `config/help/import.yaml`, `README.md`.
- Banco: migration que insere a BDTD em `academic_database` (tabela global: aparece no cadastro de bases de todos os projetos; nenhum número existente muda).
- Tabelas de autoridade globais: cada importação cria autores, palavras-chave e instituições de defesa novos, como qualquer importação. Um arquivo de 7.193 teses cria alguns milhares de autores e dezenas de milhares de palavras-chave.
- Relatórios: teses e dissertações não têm periódico nem ISSN; ficam fora do Qualis e das análises por fonte.
- Sem dependência nova.
