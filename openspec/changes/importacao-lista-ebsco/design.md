# Design

## Context

Formato verificado nos arquivos reais exportados pelo Jonas em 19/11/2026 (`var/exemplos/lisa/`):

| Arquivo | Registros | Bases |
|---|---|---|
| `EBSCO-Metadata-11_19_2026.csv` | 10 | LISTA (`lih`) |
| `EBSCO-Metadata-11_19_2026 (1).csv` | 50 (contém os 10 acima, na mesma ordem) | LISTA (`lih`) |
| `EBSCO-Metadata-11_19_2026 (2).csv` | 50 (6 em comum com o anterior) | 8 bases: Academic Search Premier `aph` 26, LISTA `lih` 6, MEDLINE Complete `mdc` 4, e outras |
| `EBSCO-Metadata-11_19_2026.bibtex` | 50 `@article` | |
| `EBSCO-Metadata-11_19_2026.xml` | 50 `<record>` | |

**CSV:**

- UTF-8 **com BOM**, quebra `\n`, delimitador vírgula, aspas só quando o campo precisa, aspas internas duplicadas (78 ocorrências nos três arquivos), nenhuma célula com quebra de linha, todas as linhas com 39 campos.
- Cabeçalho literal (39 colunas, fixo): `title,publicationDate,authors,publisher,plink,longDbName,shortDbName,accessionNumber,abstract,isiType,docType,pubType,coverDate,peerReviewed,source,subjects,doids,issn,isbn,publisherLocation,recordType,isOpenAccess,bookEdition,language,pageStart,pageEnd,pageCount,volume,issue,doi,mid,pmid,keywords,lccn,lexileRank,mla,oclc,pubMedCentralId,seriesTitle`.
- Valores múltiplos: ` ; ` (espaço, ponto e vírgula, espaço) em `authors`, `subjects`, `issn`, `isbn`, `docType`, `pubType`.
- `title`: termina em ponto em 106 de 110 linhas (as demais terminam em `?` ou `]`).
- `publicationDate`: sempre 8 dígitos `AAAAMMDD` (o dia costuma ser 01). `coverDate`: texto livre (`2026 Special Issue`, `out2026`, `jul-sep2026`).
- `authors`: "Sobrenome, Nome"; 5 linhas em maiúsculas (`XAVIER MUNIZ, ALINE BARBOSA`); nenhuma linha sem autor nas amostras.
- `plink`: `https://research.ebsco.com/plink/<uuid>`, único por linha.
- `longDbName` / `shortDbName`: nome e sigla da base da EBSCO de onde veio o registro.
- `accessionNumber`: numérico (`193831067`) ou com prefixo (`EPTOC198526235`); único nos arquivos.
- `abstract`: até 2.696 caracteres; vazio em 8 de 50 e em 11 de 50.
- `isiType`: `JOUR` (109), `CHAP` (1). `docType`: `Article` na maioria; `Journal Article`, `Book Article`, `Scoping Review ; Journal Article`; vazio em 2. `pubType`: `Academic Journal`, `Journal Article ; Academic Journal`, `Book Article`.
- `source`: nome do periódico ou do livro.
- `subjects`: termos de assunto da EBSCO, inclusive nomes de pessoas com datas (`Freire, Paulo, 1921-1997`); vazio em 11 a 13 de 50. `keywords`: vazia em todas as linhas das amostras.
- `issn`: `1368-1613`, com X, sem hífen (`16460758`), ou com valor estranho (`1678-4561 ; Electronic`, `2628-3875 ; 2628-3883`).
- `language`: `por`, `eng`, `spa` (3 letras) ou por extenso (`Portuguese`).
- `pageStart`: número ou código (`e23892025`); `pageEnd` e `pageCount` às vezes vazios.
- `doi`: sem prefixo, bem formado em todas as linhas preenchidas (41 e 44 de 50), 5 com maiúsculas; nenhum repetido.
- `pmid`: PMID de verdade (`42385079`) ou código da EBSCO (`EPTOC198526235`).
- `isOpenAccess`: `false` em todas as 110 linhas, inclusive em periódicos de acesso aberto: não é confiável.
- Não há afiliação, país, citações nem referências.

**BibTeX:** `@article{<númerodeacesso><data>, …}` com `abstract` cortado em 999 caracteres, `number = "null"` literal quando falta, `keywords` separadas por `; `, sem sigla da base. **XML:** `<records><record>` com exatamente os 39 campos do CSV.

**Não verificado:** quantos registros a EBSCO deixa exportar por arquivo (as amostras têm 10 e 50), se a nova interface oferece RIS, e onde aparece o total de resultados. O Jonas responde na tarefa 1.1.

## Goals / Non-Goals

**Goals:**
- Importar 10, 50 e 50 registros dos três arquivos reais, com a conta fechada, e 94 documentos distintos quando os três vão para o mesmo projeto (110 linhas menos 10 e 6 repetidas).
- Reconhecer o mesmo artigo vindo do Scopus ou da BRAPCI apesar do ponto final da EBSCO.

**Non-Goals:**
- Ler o BibTeX e o XML da EBSCO.
- Ler o formato antigo do EBSCOhost (RIS e CSV da interface clássica): sem amostra.
- Guardar revisão por pares, tipo de publicação, contagem de páginas e os identificadores `mid`, `lccn`, `oclc`: não há coluna em `document`.
- Gravar a situação de acesso aberto a partir de `isOpenAccess`.
- Catálogo separado para outras bases da EBSCO: todo arquivo da EBSCO entra como base `lista`, com aviso na prévia.

## Decisions

### D1. Leitor "Ebsco", base "lista"

A classe fica em `src/Service/Import/Ebsco/EbscoCsvImporter.php` porque o formato é da plataforma; `getSourceKey() = 'lista'`, `getSourceName() = 'LISTA (EBSCO)'`, `supports('csv','lista')`, `strictIdentity = true`. Lê com `League\Csv\Reader`, como os leitores de CSV atuais (o arquivo é CSV padrão; o BOM é tratado pela biblioteca, e o nome da primeira coluna é comparado sem ele).

`detect()`: fração das 8 colunas da assinatura da spec presentes no cabeçalho; as 8 dão 1,0. Nenhum outro leitor de CSV usa esses nomes (`title` em minúscula existe no CSV antigo do OpenAlex, mas as outras 7 não).

### D2. Identidade

`externalId` = `shortDbName . ':' . accessionNumber` (`lih:193831067`). Sem `accessionNumber`: `'plink:' .` o UUID final do `plink`. Sem os dois: `UnreadableRecord` se também faltar título e DOI; senão o registro segue sem `strictIdentity` e deduplica como as bases atuais.

A sigla entra na chave porque o número de acesso só é garantido único dentro de cada base da EBSCO. Consequência: o mesmo artigo presente em duas bases da EBSCO tem duas chaves; com DOI (mais de 80% das linhas) a regra geral de DOI os une; sem DOI entram os dois e aparecem em "Possíveis repetições".

### D3. Mapeamento

| Coluna | Destino | Regra |
|---|---|---|
| `title` | `title` | D4 |
| `publicationDate` | `year`, `publicationDate` | 4 primeiros dígitos; data como `AAAA-MM-DD` quando são 8 dígitos |
| `authors` | `authorNames` | divide em `;`, `trim`, descarta vazios |
| `source` | `sourceTitle` | |
| `publisher` | `publisher` | |
| `volume`, `issue` | `volume`, `issue` | |
| `pageStart`, `pageEnd` | `pageStart`, `pageEnd` | como vêm |
| `issn` | `issn` | divide em `;`; primeiro item que casa `^\d{4}-?\d{3}[\dXx]$`, gravado com hífen |
| `isbn` | `isbn` | primeiro item |
| `language` | `language` | `Portuguese` → `por`, `English` → `eng`, `Spanish` → `spa`; outros como vêm |
| `abstract` | `abstractText` | `strip_tags`, entidades decodificadas |
| `docType` (senão `pubType`, senão `isiType`) | `documentType` | primeiro item da lista |
| `doi` | `doi` | minúsculas; validado com `^10\.\d{4,9}/\S+$` |
| `pmid` | `pmid` | só se `ctype_digit` |
| `subjects` | `indexedKeywords` | divide em ` ; `, `trim`, sem repetição |
| `keywords` | `authorKeywords` | idem |
| `plink` | `url` | |
| `longDbName`, `shortDbName` | (contagem da prévia) | D5 |

`subjects` como indexadas: são os termos atribuídos pela EBSCO, não pelo autor.

`isOpenAccess` não é gravada: veio `false` nas 110 linhas, inclusive para periódicos de acesso aberto, e gravar "Closed" para todos distorceria o relatório de acesso aberto.

### D4. Ponto final do título

Regra: `rtrim` de espaços; se o título termina em `.` e não termina em `..`, remove um ponto. Títulos terminados em `?`, `!`, `]` ou `...` ficam como vieram.

Motivo: o duplicado entre bases é reconhecido por título normalizado + ano (mudança de base, D5), e a normalização só baixa a caixa e junta espaços. Com o ponto da EBSCO, "Título." e "Título" seriam documentos diferentes e o mesmo artigo entraria duas vezes ao combinar LISTA com Scopus, Web of Science ou BRAPCI. A remoção é feita no leitor da EBSCO, e não na normalização geral, para não mudar o identificador de documentos já gravados das outras bases.

Risco aceito: um título que legitimamente termina em ponto (por exemplo, em abreviatura) perde o ponto.

### D5. Outras bases da EBSCO

`countRows()` percorre o arquivo e, de passagem, conta as linhas por `longDbName`. `previewNotice()` devolve, quando há linha cuja sigla não é `lih` nem `lxh` (LISTA sem texto completo): "N dos M registros não são da LISTA" seguido das bases e quantidades. Todas as linhas são importadas com `source = 'lista'`.

As siglas aceitas como LISTA ficam numa constante do leitor (`lih`, `lxh`, `lls`); a resposta do Jonas na tarefa 1.1 confirma a sigla da assinatura dele.

### D6. Catálogo e tabela global

`DataSources::SOURCES['lista']`: `label` "LISTA (EBSCO)", `vendor` "EBSCO", `url` `https://research.ebsco.com`, `formats` `['csv']`, `limit` conforme a resposta da tarefa 1.1 (50, se confirmado), `hasReader` true, `guide` `import/guides/_lista.html.twig`, `logo` nulo.

Migration de dados: linha `('LISTA', 'lista', 'https://research.ebsco.com', '["csv"]', '["title","publicationDate","plink","shortDbName","accessionNumber"]')` em `academic_database`, com `WHERE NOT EXISTS`. Efeito em todos os projetos: a base aparece no cadastro; como os registros trazem ISSN, o enriquecimento passa a vincular à LISTA os periódicos dos documentos importados dela (`qualis_journal_academic_database`), inclusive os de registros que vieram de outras bases da EBSCO no mesmo arquivo, e periódico sem cadastro entra em `qualis_journal` sem estrato. Nenhum Qualis existente muda.

## Risks / Trade-offs

- [Arquivo de busca em várias bases entra todo como LISTA e vincula à LISTA periódicos que não são dela] → aviso na prévia com a contagem; o guia orienta a limitar a busca à LISTA.
- [Exportação em partes de 50 depende de o usuário não pular nenhuma] → o campo "Total de resultados mostrado pela base" e a soma dos lidos mostram a falta; identidade pelo número de acesso evita duplicar partes sobrepostas.
- [A EBSCO mudar as colunas do exportador novo] → leitura por nome; assinatura de 8 colunas centrais; coluna nova é ignorada.
- [Remover o ponto final de um título que o tem de verdade] → efeito só no texto do título; documentado na ajuda.
- [Idioma por extenso em outros idiomas além dos três mapeados] → gravado como vem, cortado em 10 caracteres pelo serviço.

## Migration Plan

Local com os três arquivos reais; produção com backup, deploy, migration de uma linha e teste no navegador em `/projects/22/import`.

## Open Questions

- Limite de registros por exportação, existência de RIS na interface nova e onde aparece o total de resultados (tarefa 1.1). Afetam o texto do guia e o campo `limit` do catálogo, não o leitor.
