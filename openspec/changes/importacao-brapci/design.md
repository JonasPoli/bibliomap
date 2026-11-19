# Design

## Context

Formato verificado em arquivo real (19/11/2026). Amostras: `var/exemplos/brapci/brapci_20261119-121526.csv` e `…121529.xls` (884 registros, exportados pelo Jonas) e `var/exemplos/pesquisa/brapci-todos-formatos/` (77, 73 e 1.482 registros, todos os formatos).

**CSV** (`text/csv;charset=UTF-8`):

- UTF-8 sem BOM; quebra de linha **só `\r`** (886 `\r`, nenhum `\n`); termina em `\r\r`.
- Cabeçalho literal: `ID,CLASS,YEAR,PUBLICATION,AUTHORS,TITLE,KEYWORDS,ABSTRACTS,SESSION,LEGEND,URL,` (vírgula final: 12 campos, o último sem nome e vazio).
- Aspas em todo campo não numérico; nenhuma aspa interna nos 884 nem nos 1.482 (a base troca `"` por `´`), mas o exportador não escapa; quebras dentro de célula viram espaço na origem.
- Linhas em ordem crescente de `ID`. `ID` único em todos os arquivos.
- `CLASS`: `Article` (663), `Proceeding` (211), `Book` (7), `BookChapter` (3) no arquivo de 884.
- `YEAR`: sempre 4 dígitos; a origem grava `2000` quando o ano falta (5 registros com 2000; indistinguível).
- `AUTHORS`: nomes por extenso em ordem direta, separados por `; `; organizadores com ` (Org.)`.
- `KEYWORDS`: separadas por `; `; vazia em 140 de 884.
- `ABSTRACTS`: resumos de todos os idiomas concatenados; até 10 mil caracteres; vazia em 13.
- `SESSION`: seção (`Artigo`, `GT-07 (ENANCIB); Poster`, `No Section`); vazia em 51.
- `LEGEND`: `Periódico, v. X, n. Y, AAAA` (artigo), `Título, AAAA` (demais). Número sujo: `esp.`, `no 12`, `nº 12`, `1-2`, vazio (`n. ,`).
- `URL`: `https://doi.org/…` (573) ou `https://hdl.handle.net/20.500.11959/brapci/{ID}` (311). DOIs: 22 repetidos em 45 registros; malformados (`10.18617/`, `10;hipertext.net`, `101`, `103-114`, `104`).
- Entidades HTML cruas: `&nbsp;` (111), `&quot;` (12), `&#8203;`, `&#39;`.
- 14 grupos de título + ano repetidos com IDs diferentes (28 registros).

**XLS**: fragmento `<table>\r<tr><td>ID</td>…</tr>\r<tr><td>4316</td>…`, sem `<html>`, em Windows-1252, uma `<tr>` por linha separada por `\r`, 11 células, sem escape de HTML (`<` cru em células). 885 `<tr>` = cabeçalho + 884. Caracteres fora de Windows-1252 já vêm como `?` da origem (perda na base, não no leitor).

**RIS**: ASCII sem acentos, só `\r`, `TY` sempre `JOUR`, marcações `AU` (iniciais), `TI`, `PY`, `PB`, `JO`, `UR` (handle com o ID), `VL`, `IS`, `AB`, `KW`, `DB  - BRAPCI`, `M3` (tipo real), `LA` (sempre `por`). A exportação RIS falha na origem (HTTP 500) quando a cesta tem certos registros: 14 de 30 blocos de 50 falharam na pesquisa.

O fluxo de cliques da interface (cesta, botões) vem da leitura do código do site e ainda não foi exercitado na tela; a exportação logada (sem o corte de 2.000) também não.

## Goals / Non-Goals

**Goals:**
- Importar 884 de 884 e 1.482 de 1.482 registros dos arquivos reais, pelo CSV e pelo XLS.
- Nunca unir dois IDs diferentes da BRAPCI.
- Não deixar um DOI errado da BRAPCI barrar, depois, o registro certo de outra base.

**Non-Goals:**
- Buscar DOI, ISSN, páginas e resumo por idioma na API `get/v1/{id}` (uma requisição por registro; fica para uma mudança futura).
- Guardar seção (`SESSION`) e separar resumo por idioma: não há coluna em `document`.
- Ler BibTeX, DOC, Citações, TT e .NET (recusados pela extensão no envio).
- Afiliação, país e instituição: a BRAPCI não exporta.

## Decisions

### D1. Três leitores finos sobre um mapeador

- `BrapciRecordMapper::map(array $row, int $position, array $sharedDois): BibliographicRecordDTO` recebe a linha já como `['ID'=>…, 'CLASS'=>…, …]` e contém todo o mapeamento. É o que o CSV e o XLS compartilham.
- `BrapciCsvImporter` e `BrapciXlsImporter` só sabem transformar o arquivo em linhas associativas.
- `BrapciRisImporter` estende `AbstractRisImporter` (mudança de base) e tem mapeamento próprio, porque as marcações são outras.

Todos com `getSourceKey() = 'brapci'`, `strictIdentity = true`, `externalId = ID`.

### D2. CSV com `\r` e vírgula final

`League\Csv\Reader` (usado pelos outros leitores) depende de `fgetcsv`, que não quebra em `\r` puro: leria o arquivo como uma linha. O leitor abre o arquivo por um `stream_filter` próprio (`BrapciLineEndingFilter`, registrado com `stream_filter_register`) que troca `\r\n` e `\r` por `\n` fora de aspas, e entrega esse stream ao `Reader::createFromStream()`. Como a origem já troca quebras internas por espaço, uma troca simples de todo `\r` por `\n` é segura; o filtro guarda o último byte do bloco para não partir um `\r\n` entre dois blocos.

Cabeçalho: `array_filter` tira a coluna de nome vazio antes de `setHeaderOffset`; como `League\Csv` recusa cabeçalho duplicado ou vazio, o leitor lê a primeira linha à mão, monta a lista de nomes e usa `getRecords($nomes)` a partir da segunda linha, descartando a 12ª posição. Linhas totalmente vazias (o `\r\r` final) são ignoradas com `skipEmptyRecords()`.

`detect()`: lê os primeiros 4 kB, normaliza quebras, separa a primeira linha por vírgula e devolve (colunas da assinatura encontradas) / 11; a assinatura completa dá 1,0. Scopus, Lens e PubMed devolvem 0 para esse cabeçalho (nenhuma coluna-assinatura em comum).

Linha com número de campos diferente de 11 ou 12, ou sem `ID` numérico: `UnreadableRecord` com a posição e os primeiros 120 caracteres da linha.

Alternativa descartada: regravar o arquivo enviado com `\n`. Mantém-se o arquivo original intacto para auditoria e retomada.

### D3. Passada prévia para os DOIs compartilhados

Antes de `parseStream()` entregar o primeiro registro, o leitor percorre o arquivo uma vez só com `ID` e `URL` e monta o conjunto de DOIs normalizados usados por mais de um ID. O arquivo de 1.482 registros tem 4 MB; a passada custa menos de um segundo. O conjunto vai para o mapeador.

Regra do mapeador para `URL`:

1. `url` do documento = `https://hdl.handle.net/20.500.11959/brapci/{ID}`, sempre.
2. Se `URL` começa com `http(s)://doi.org/` ou `dx.doi.org/`: tira o prefixo, põe em minúsculas, tira pontos e espaços finais.
3. Grava `doi` só se: casa `^10\.\d{4,9}/\S+$`; não está no conjunto de compartilhados; e `CLASS` é `Article`.

Por que tão restritivo: `document.doi` entra no mapa de DOIs do projeto e barra qualquer registro posterior com o mesmo DOI. Um DOI de obra citada gravado num livro da BRAPCI faria o artigo verdadeiro, importado depois do Scopus, contar como duplicado e sumir. Preferimos perder a ponte por DOI (o título + ano entre bases ainda une o mesmo artigo) a correr esse risco. Risco que sobra: DOI único, bem formado, de artigo, mas errado; não há como detectar pelo arquivo, e a conferência lista o caso se ele barrar alguém.

### D4. Mapeamento dos campos

| Coluna | Destino | Regra |
|---|---|---|
| `ID` | `externalId` | dígitos; obrigatório |
| `CLASS` | `documentType` | valor como vem (`Article`, `Proceeding`, `Book`, `BookChapter`) |
| `YEAR` | `year` | 4 dígitos; senão nulo |
| `PUBLICATION` | `sourceTitle` | nulo quando `LIVRO` ou `CAPITULO DE LIVRO` |
| `AUTHORS` | `authorNames` | `explode(';')`, `trim`, tira ` (Org.)` e ` (Coord.)` no fim, descarta vazios |
| `TITLE` | `title` | entidades decodificadas; nulo quando `:: Sem titulo ::` |
| `KEYWORDS` | `authorKeywords` | `explode(';')`, `trim`, descarta vazios |
| `ABSTRACTS` | `abstractText` | entidades decodificadas, `strip_tags` |
| `LEGEND` | `volume`, `issue` | regex abaixo |
| `URL` | `url`, `doi` | D3 |
| `SESSION` | (não gravado) | |

Valor `null` sem aspas ou vazio vira nulo em qualquer coluna. Entidades: `html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8')`, depois troca U+00A0 por espaço e remove U+200B.

`LEGEND`: `volume` = `/,\s*v\.\s*([^,]+?)\s*(?:,|$)/u`; `issue` = `/,\s*n\.\s*([^,]*?)\s*(?:,|$)/u`, removendo prefixos `no`, `nº`, `n°`, `número` do valor; valor vazio vira nulo. As colunas `volume` e `issue` de `document` têm 20 caracteres (o serviço corta).

O ano `2000` é gravado como vem: a ajuda avisa que a BRAPCI usa 2000 quando o ano falta.

### D5. XLS (HTML)

`detect()`: os primeiros 512 bytes, sem espaços iniciais, começam com `<table>` e contêm `<td>ID</td><td>CLASS</td>` → 1,0. Declara `sourceEncoding() = 'Windows-1252'` (interface da mudança de base).

Leitura sem DOM, porque o HTML não tem escape (`<` cru quebraria um analisador): o arquivo é lido em blocos, convertido para UTF-8 e dividido em `</tr>`; de cada pedaço tira-se o `<tr><td>` inicial e o `</td>` final e divide-se em `</td><td>`. Como a origem monta cada linha concatenando células com exatamente esse separador, a divisão é exata mesmo com `<` no texto; o único caso que quebraria é um resumo contendo literalmente `</td><td>`, tratado como `UnreadableRecord` quando o número de células não é 11. A primeira linha é o cabeçalho. Converter antes de dividir: fazer a conversão por linha (`mb_convert_encoding($linha, 'UTF-8', 'Windows-1252')`), com as linhas separadas por `\r`.

### D6. RIS

`detect()`: nas primeiras 200 linhas há `DB  - BRAPCI` → 1,0. Mapeamento: `externalId` = dígitos finais de `UR` (`/brapci\/(\d+)\s*$/`); `documentType` = `M3`; `title` = `TI`; `authorNames` = `AU` (ficam como "SOBRENOME, I. I."); `year` = `PY`; `sourceTitle` = `JO` (ou `PB`); `volume` = `VL`; `issue` = `IS`; `abstractText` = `AB`; `authorKeywords` = `KW`; `url` = `UR`; sem DOI. Sem `UR` com ID → `UnreadableRecord`.

Consequência aceita: o mesmo registro importado pelo CSV e pelo RIS é duplicado pelo ID (bom), mas o RIS cria variantes de autor abreviadas e sem acento nas tabelas globais. Por isso a prévia avisa e o guia recomenda o CSV. O aviso é devolvido por um método opcional novo `previewNotice(): ?string` que `ImportController::upload()` passa para `preview.html.twig`.

### D7. Contagem por tipo

A contagem tem de ser a do arquivo (lidos), e não a dos documentos importados, para bater com a cesta mesmo quando há duplicados. Os leitores de CSV e XLS expõem `countByType(string $path): array`, e `DatasetController::show()`, quando `dataset.source = 'brapci'`, chama esse método sobre o arquivo enviado (`dataset.file_path`): é uma passada de menos de um segundo. Se o arquivo já foi apagado do servidor, o bloco não aparece.

Alternativas descartadas: somar `document_type` dos documentos do dataset (não conta os duplicados) e guardar o tipo em `dataset_skip.detail` ou em `dataset.import_notice` (campos com outro significado).

Rótulos: `Article` → Artigos, `Proceeding` → Trabalhos de eventos, `Book` → Livros, `BookChapter` → Capítulos, outro valor → o próprio valor.

### D8. Catálogo e tabela global

`DataSources::SOURCES['brapci']`: `label` "BRAPCI", `vendor` "UFRGS", `url` `https://brapci.inf.br`, `formats` `['csv','xls','ris']`, `limit` 2000, `hasReader` true, `guide` `import/guides/_brapci.html.twig`, `logo` nulo (usa o emoji de reserva; logotipo só com autorização de uso).

Migration de dados: `INSERT INTO academic_database (name, acronym, url, file_formats, signature_columns) VALUES ('BRAPCI', 'brapci', 'https://brapci.inf.br', '["csv","xls","ris"]', '["ID","CLASS","YEAR","PUBLICATION","LEGEND"]')`, protegida por `WHERE NOT EXISTS`. Efeito em todos os projetos: a BRAPCI aparece no cadastro de bases e pode ser vinculada a periódicos do Qualis; como o CSV não traz ISSN, a importação da BRAPCI não cria vínculo com periódico. Nenhum número existente muda. Escrita em produção de uma linha, com backup antes.

## Risks / Trade-offs

- [A BRAPCI mudar o cabeçalho ou passar a escapar aspas] → o leitor lê por nome de coluna e `League\Csv` já trata aspas duplicadas; `detect()` aceita a assinatura parcial com pontuação menor e a prévia mostra a contagem.
- [Corte silencioso de 2.000 na exportação e de 1.000 na busca, na origem] → fora do alcance do leitor; guia de exportação avisa e o campo "Total de resultados mostrado pela base" mostra a diferença.
- [DOI correto descartado (repetido por engano da base)] → o documento continua importado, só sem DOI; título + ano entre bases ainda une.
- [Autores do RIS poluem as tabelas globais com iniciais sem acento] → aviso na prévia e recomendação do CSV; efeito global declarado.
- [A cesta mistura itens de buscas anteriores (fica no navegador)] → guia orienta a esvaziar a cesta antes; não há como detectar no arquivo.

## Migration Plan

Local primeiro, com os arquivos reais. Produção: backup, deploy, migration de uma linha e teste no navegador em `/projects/22/import` (tarefas 6 e 7). Volta: remover a linha de `academic_database` e reverter o commit; documentos importados no teste são apagados excluindo o dataset de teste.

## Open Questions

- A exportação feita por usuário logado tem o mesmo formato? O arquivo do Jonas (884 registros, exportado pela tela) tem o mesmo cabeçalho e as mesmas particularidades do arquivo baixado sem login, o que indica que sim; o teste no navegador (tarefa 7) confirma com uma cesta nova.
