# Proposal

## Why

A base internacional de Ciência da Informação a que o Jonas tem acesso é a **LISTA** (Library, Information Science & Technology Abstracts with Full Text), na plataforma EBSCO. Os arquivos que ele exportou em 19/11/2026 (`var/exemplos/lisa/EBSCO-Metadata-11_19_2026*.csv`, `.bibtex` e `.xml`) mostram isso nas colunas `longDbName` e `shortDbName` (`lih`). Existe outra base de nome parecido, a LISA do ProQuest, que o Jonas não usa (confirmado por ele em 19/11/2026) e que fica fora do plano.

O BiblioMap não lê o arquivo da EBSCO: o CSV não é reconhecido por nenhum leitor e a importação terminaria em erro, com 0 documentos. O formato é simples e está todo verificado em arquivo real (110 linhas em três CSV), com três pontos de atenção: o arquivo traz registros de qualquer base da EBSCO que a busca alcançou (um dos arquivos tem 8 bases); o identificador do registro só é único dentro de cada base; e a EBSCO acrescenta um ponto final ao título, o que impediria reconhecer o mesmo artigo vindo de outra base.

Depende de `importacao-base-novos-formatos` (identidade pelo identificador da base e conferência).

## What Changes

- Leitor do **CSV** exportado pela EBSCO (39 colunas): título, data, autores, editora, endereço permanente, base de origem, número de acesso, resumo, tipo, fonte, assuntos, palavras-chave, ISSN, ISBN, idioma, páginas, volume, número e DOI.
- Identidade pela sigla da base da EBSCO mais o número de acesso (`lih:193831067`).
- Título gravado sem o ponto final acrescentado pela EBSCO.
- Aviso na prévia de quantos registros do arquivo não são da LISTA; todos são importados.
- LISTA no catálogo de bases, com guia de exportação, e a linha na tabela global `academic_database`.
- BibTeX e XML da EBSCO não são lidos: o BibTeX corta o resumo em 1.000 caracteres e não traz o número de acesso; o XML repete o CSV.

## Capabilities

### New Capabilities
- `importacao-lista`: leitura do CSV exportado da LISTA na plataforma EBSCO, com a identidade pela base e pelo número de acesso e o aviso sobre registros de outras bases da EBSCO.

### Modified Capabilities
<!-- Nenhuma: as regras gerais mudam em importacao-base-novos-formatos. -->

## Impact

- Código novo: `src/Service/Import/Ebsco/EbscoCsvImporter.php`; registro em `config/services.yaml`.
- Código alterado: `src/Config/DataSources.php` (base `lista`), `templates/import/guides/_lista.html.twig` (novo), `config/help/import.yaml`, `README.md`.
- Banco: migration que insere a LISTA em `academic_database` (tabela global: aparece no cadastro de bases de todos os projetos e passa a receber o vínculo dos periódicos pelo ISSN; nenhum número existente muda).
- Tabelas de autoridade globais: autores, palavras-chave e periódicos novos, como em qualquer importação.
- Sem dependência nova.
