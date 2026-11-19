# Design

## Context

**O que existe hoje (lido em 19/11/2026):** `DataSources::SOURCES['openalex']` com `formats ['csv']`; linha `openalex` em `academic_database` sem colunas-assinatura; painel `panel-openalex` em `templates/import/index.html.twig` (linhas 535-565) com o aviso "Ainda sem leitor"; ajuda `import.source.openalex` dizendo que o CSV não é lido. Nenhuma classe em `src/Service/Import/` trata OpenAlex. `WosImporter::detect()` (linhas 62-93) devolve 0,9 para qualquer arquivo com `FN ` e `VR 1.0` e 0,6 para qualquer arquivo com uma linha que comece por `PT X`; `mapToInternalRecord()` grava `source = 'wos'`, lê citações só de `TC` e o identificador de `UT`.

**Verificado em arquivo real** (`var/exemplos/pesquisa/openalex-api/`, API pública, 19/11/2026): 700 works da busca `title.search:bibliometria` (de 801) em 7 arquivos e 340 de 340 da revista Em Questão em 4 arquivos.

- Arquivo: UTF-8 sem BOM, ASCII puro (acentos como `\uXXXX`), uma única linha de 1,1 a 1,9 MB por página de 100 works.
- Raiz `{"meta": {"count", "page", "per_page", …}, "results": [ … ]}`.
- Work: 59 chaves. As usadas: `id` (`https://openalex.org/W…`), `doi` (`https://doi.org/…`, minúsculo, ou nulo), `title`, `publication_year`, `publication_date`, `language` (`pt`, `en`, `es`; apareceu `spa`), `type`, `biblio {volume, issue, first_page, last_page}`, `open_access {is_oa, oa_status}`, `cited_by_count`, `primary_location.source {display_name, issn_l, issn[], host_organization_name, type}`, `authorships[] {author {id, display_name, orcid}, institutions[] {display_name, ror, country_code}, countries[], raw_affiliation_strings[]}`, `keywords[] {display_name, score}`, `referenced_works[]` (URLs de works), `abstract_inverted_index {palavra: [posições]}`, `is_authors_truncated`.
- Medidas nos 700: sem DOI 251; DOI repetido em works diferentes 2; títulos repetidos 21; sem autores 2 (25 de 340 na revista: pareceres e editoriais); `author.id` nulo em 40 works; um autor com `display_name` igual a `,`; sem fonte 81; sem resumo 133; sem referências 339; páginas não numéricas (`s167`, `e268`, ` 291` com espaço não separável); `raw_affiliation_strings` com `\r\n` em 9 works.
- A API sem chave só permite paginar por `page` (cursor exige chave) e limita a busca anônima; `format=csv` em lista devolve JSON.

**Verificado em arquivo real da interface** (exportados pelo Jonas em 19/11/2026, em `var/exemplos/openalex/`):

*CSV* `works-2026-11-19T16-08-23.csv` (149 works, todas as colunas marcadas):

- UTF-8 **sem BOM**, quebra `\r\n`, delimitador vírgula, aspas só quando o campo precisa, aspas internas duplicadas (53 ocorrências), nenhuma célula com quebra de linha, todas as linhas com 106 campos.
- 106 colunas. As que interessam: `Title`, `Author`, `Year`, `Date` (`AAAA-MM-DD`), `Type`, `Language` (2 letras), `DOI` (com `https://doi.org/`), `Work ID` (curto, `W…`), `Source`, `ISSN`, `Citation count`, `Open access` (`Open Access` / `NOT Open Access`), `Open Access status` (`diamond`, `green`, `hybrid`, `gold`, `closed`, `bronze`), `Keyword`, `Institution`, `Country`, `Cites` (Work IDs curtos), `Abstract` (até 19.379 caracteres), `PMID`.
- **Não existem colunas de volume, número, páginas nem editora.** Esses dados só vêm no JSON, no RIS e no BibTeX.
- Valores múltiplos: `|`, confirmado em `Author`, `Keyword`, `ISSN`, `Cites`, `Institution`, `Country`, `ORCID`.
- **Listas por autor têm posições vazias**: `Country` vem como `BR||BR|BR||||BR|` (uma posição por autor) e `Institution` como `Universidade Federal de Campina Grande||Instituto Federal do Rio Grande do Norte`. O número de itens de `Institution` nem sempre é o de `Author` (6 autores e 2 instituições num caso).
- `Author`: nome por extenso em ordem direta; 1 linha sem autor; há lixo da origem (instituição no lugar do autor: `Universidad Complutense de Madrid, España`).
- 149 Work IDs distintos; 110 com DOI; nenhum DOI nem título + ano repetido.
- `Type`: `article` 98, `dissertation` 34, `conference-paper` 5, e outros.

*RIS* `works-2026-04-18T01-00-07.ris` (9.540 works, exportação de abril de 2026, 21 MB):

- UTF-8 sem BOM, com 20 bytes inválidos; quebra `\n` (e `\r` soltos dentro de afiliações); registro termina em `ER  -` sem espaço final.
- Ordem das marcações: `TY, TI, PY, PB, SN, T2, DO, UR, DA, (AU, C1*)*, LA, KW*, VL, IS, SP, EP, AB, ER`.
- `TY`: `JOUR` 8.151, `CHAP` 1.013, `GEN`, `DATA`, `BOOK`, `THES`, `LETTER`, `RPRT`, `ERRT`.
- `AU`: "Sobrenome, Nome" (o nome vem reduzido: `Patrício, Diego`). `C1`: afiliações brutas do autor, logo depois de cada `AU`, uma ou mais; **266 linhas de continuação sem marcação** (endereços em várias linhas).
- `DO` sem prefixo (ausente em 305 registros); `UR` sempre o endereço do DOI; `DA` `AAAA-MM-DD`; `LA` em 2 ou 3 letras; `SN` com hífen.
- `KW`: são os **conceitos** do OpenAlex (`Computer science`, `Context (archaeology)`), 89 mil linhas, e não as palavras-chave.
- Sem Work ID, sem citações, sem referências, sem país.

*BibTeX* `works-2026-11-19T16-08-08.bib` (149 entradas, os mesmos works do CSV): `title, author, year, journal, volume, number, pages, issn, doi, url, language, keywords, abstract`, sem Work ID.

**Ainda não verificado em arquivo:** o TXT "Web of Science plain text" e a variante "CSV (Excel)" (com BOM e células cortadas em 30.000 caracteres, segundo o código público `ourresearch/openalex-gui`). Do exportador antigo sabe-se que o TXT começa com `FN OpenAlex` / `VR 1.0`, traz as citações em `TC` ou `CT` e o Work ID em `UT` (ou não o traz), sem `CR`, `AB` e `DE`. Gerações antigas do CSV, também só pelo código: v1 (`id, display_name, author_names, cited_by_count, doi, publication_year, biblio_volume, …`) e v2 (caminhos com ponto: `authorships.author.display_name`, `primary_location.source.display_name`, `biblio.volume`, …), as duas com `|`.

## Goals / Non-Goals

**Goals:**
- Importar 700 de 700 e 340 de 340 works dos arquivos JSON reais, com a conta fechada.
- Nenhum arquivo do OpenAlex identificado como outra base.
- Importar 149 de 149 works do CSV real e 9.540 de 9.540 do RIS real, com a conta fechada.
- Leitor do TXT escrito contra a documentação e **fechado só com arquivo real** exportado pelo Jonas (a tarefa 5 começa por obter a amostra).

**Non-Goals:**
- O sistema buscar na API do OpenAlex a partir de um filtro ou endereço (exigiria chave de API da aplicação; fica para uma mudança futura, se o Jonas quiser).
- Ler BibTeX (traz volume e páginas, mas não o Work ID; o JSON traz tudo).
- Guardar ORCID, ROR, tópicos, conceitos, financiadores e ODS: não há coluna em `document`.
- Vincular as referências (Work IDs) aos documentos do projeto para citação local: as referências são gravadas, o cálculo não muda.
- Corrigir datasets antigos importados de TXT do OpenAlex como Web of Science.

## Decisions

### D1. Um mapeador por formato de origem, mesma saída

- `OpenAlexWorkMapper::fromApiWork(array $work, int $position): BibliographicRecordDTO` para o JSON.
- `OpenAlexCsvImporter` tem mapa de sinônimos próprio e monta o DTO direto das colunas.
- `OpenAlexWosImporter` e `OpenAlexRisImporter` reaproveitam os leitores de marcações.

Todos com `source = 'openalex'` e `getSourceKey() = 'openalex'`. `strictIdentity = true` só quando o registro tem Work ID (JSON sempre; CSV e TXT quando a coluna ou a marcação existem); RIS nunca.

`externalId` = Work ID curto: `preg_match('/(W\d+)$/', $id)`.

### D2. JSON em fluxo

Dependência `halaxa/json-machine` (PHP puro). Motivo: a página da API é uma linha única de até 2 MB, e um usuário pode juntar páginas num arquivo de centenas de MB; `json_decode` do arquivo inteiro multiplicaria o tamanho em memória por 5 a 10, acima dos 768 MB do processo.

Formas aceitas, decididas pelos primeiros bytes não brancos:

| Início do arquivo | Forma | Leitura |
|---|---|---|
| `{` e a chave `results` nos primeiros 64 kB | resposta da API | `Items::fromFile($path, ['pointer' => '/results'])` |
| `[` | lista de works | `Items::fromFile($path)` |
| `{` com `"id":"https://openalex.org/W` na primeira linha e mais de uma linha | um work por linha (JSON Lines) | `fgets` + `json_decode` por linha |

`detect()`: 1,0 quando a forma é uma das três e os primeiros 64 kB contêm `https://openalex.org/W`; 0 caso contrário (inclusive para o JSON da API da BDTD ou da BRAPCI, que não têm essa cadeia).

`meta.count`: lido com uma segunda passada curta `Items::fromFile($path, ['pointer' => '/meta/count'])` só na prévia; exposto por `previewNotice()` ("a busca tem N resultados; este arquivo traz M") e usado para pré-preencher o campo "Total de resultados mostrado pela base" quando o arquivo é único. No envio de várias páginas cada dataset guarda o mesmo `meta.count` em `expected_count`; a tela do dataset já explica a diferença, e o guia diz para somar os lidos dos datasets da busca.

`countRows()`: percorre os itens sem mapear. Work que não é objeto ou não tem `id` → `UnreadableRecord`.

Alternativa descartada: limitar o JSON a 50 MB e usar `json_decode`. Mais simples, mas recusaria arquivos que o usuário legitimamente juntou e trocaria perda de registro por erro de tamanho.

### D3. Mapeamento do work (JSON)

| Origem | Destino | Regra |
|---|---|---|
| `id` | `externalId` | Work ID curto |
| `title` (ou `display_name`) | `title` | `strip_tags`, entidades decodificadas |
| `publication_year`, `publication_date` | `year`, `publicationDate` | |
| `type` | `documentType` | como vem (`article`, `review`, `book-chapter`, `dissertation`, …) |
| `language` | `language` | como vem (2 ou 3 letras) |
| `doi` | `doi` | sem `https://doi.org/`, minúsculas; nulo se não casar `^10\.\d{4,9}/\S+$` |
| `ids.pmid` | `pmid` | só os dígitos finais |
| `primary_location.source.display_name` | `sourceTitle` | |
| `primary_location.source.issn_l` | `issn` | senão o primeiro de `issn[]` |
| `primary_location.source.host_organization_name` | `publisher` | |
| `biblio.volume`, `issue`, `first_page`, `last_page` | `volume`, `issue`, `pageStart`, `pageEnd` | `trim` incluindo U+00A0 |
| `cited_by_count` | `citedBy` | inteiro; 0 é 0 |
| `open_access.oa_status` | `openAccessStatus` | `gold` → "Gold", `green` → "Green", `hybrid`, `bronze`, `diamond` idem; `closed` → "Closed" |
| `authorships[].author.display_name` | `authorNames` | na ordem; descarta nome sem letra (o caso `,`); se `display_name` é nulo usa `raw_author_name` |
| `authorships[].institutions[].display_name` | `institutions` | sem repetição, na ordem |
| `authorships[].countries[]` (senão `institutions[].country_code`) | `countries` | códigos de 2 letras, sem repetição |
| `authorships[].raw_affiliation_strings[]` | `affiliations` | quebras viram espaço |
| `keywords[].display_name` | `indexedKeywords` | |
| `referenced_works[]` | `references` | Work IDs curtos |
| `abstract_inverted_index` | `abstractText` | D4 |
| `primary_location.landing_page_url` | `url` | senão `https://openalex.org/{Work ID}`; passa por `TextNormalizer::safeUrl` |

Países como código de 2 letras: o enriquecimento já procura sigla de 2 letras no cadastro de países antes de qualquer outra regra (requisito "Sigla de duas letras no país").

Palavras-chave: as `keywords` do OpenAlex são atribuídas por modelo, não pelo autor; por isso entram como indexadas. Tópicos e conceitos não entram (são categorias amplas e poluiriam as análises de palavras-chave).

`is_authors_truncated = true`: o work é importado com os 100 autores que vieram, e o leitor soma o caso num contador exposto no log da importação ("N works com lista de autores cortada pela API").

Dois works com o mesmo DOI: vale a regra geral de DOI (o segundo é duplicado, motivo `doi`, listado com o Work ID). No OpenAlex isso costuma ser o mesmo trabalho indexado duas vezes.

### D4. Resumo

`abstract_inverted_index` é `{palavra: [posições]}`. Reconstrução: vetor por posição, `ksort`, `implode(' ')`. Limite de segurança: ignora posição acima de 100.000 e para em 20.000 palavras (um índice malformado não pode estourar a memória). Texto final com `strip_tags` e entidades decodificadas.

### D5. CSV da interface

Leitura com `League\Csv\Reader` (trata o BOM da variante "CSV (Excel)", aspas e quebras dentro de células). O arquivo real vem sem BOM e com `\r\n`.

Mapa de sinônimos, com cabeçalhos comparados sem maiúsculas e sem espaços nas pontas:

| Campo | Rótulo atual | Caminho (v2) | Nome antigo (v1) |
|---|---|---|---|
| Work ID | `Work ID` | `id` | `id` |
| título | `Title` | `title`, `display_name` | `display_name` |
| DOI | `DOI` | `doi` | `doi` |
| ano | `Year` | `publication_year` | `publication_year` |
| data | `Date` | `publication_date` | `publication_date` |
| tipo | `Type` | `type` | `type` |
| idioma | `Language` | `language` | |
| autores | `Author` | `authorships.author.display_name` | `author_names` |
| instituições | `Institution` | `authorships.institutions.display_name` | `author_institution_names` |
| países | `Country` | `authorships.countries` | |
| fonte | `Source` | `primary_location.source.display_name` | `primary_location_display_name` |
| ISSN | `ISSN` | `primary_location.source.issn_l`, `primary_location.source.issn` | `primary_location_issn_l` |
| volume | | `biblio.volume` | `biblio_volume` |
| número | `Issue` | `biblio.issue` | `biblio_issue` |
| páginas | `First page`, `Last page` | `biblio.first_page`, `biblio.last_page` | `biblio_first_page`, `biblio_last_page` |
| citações | `Citation count` | `cited_by_count` | `cited_by_count` |
| acesso aberto | `Open Access status`, `Open access` | `open_access.oa_status`, `open_access.is_oa` | `oa_status`, `is_oa` |
| palavras-chave | `Keyword` | `keywords.display_name` | |
| referências | `Cites` | `referenced_works` | `referenced_works` |
| resumo | `Abstract` | `abstract` | |
| PMID | `PMID` | `ids.pmid` | `pmid` |

`detect()`: 1,0 quando o cabeçalho tem a coluna do Work ID **e** ao menos três outras do mapa de uma mesma geração; 0,9 quando tem `Citation count` e `Open access` (o padrão de cinco colunas) ou `cited_by_count` com `publication_year`. Scopus, Lens, PubMed, BRAPCI e BDTD não têm essas colunas.

Separador de valores múltiplos: `|` (confirmado no arquivo real e igual nas gerações antigas). Não há detecção de separador: uma célula sem `|` é um valor só, mesmo que tenha vírgula (há títulos, autores e instituições com vírgula).

Listas por autor (`Institution`, `Country`): a célula tem uma posição por autor, com posições vazias. O leitor divide em `|`, descarta as posições vazias e remove repetições, mantendo a ordem. Não tenta casar autor com instituição: `document` guarda instituições e países do documento, não por autor.

Colunas que o CSV atual não tem: volume, número, páginas e editora. Os campos ficam vazios; o guia avisa que quem precisa deles deve usar o JSON. Os sinônimos de `Issue`, `First page` e `Last page` do mapa acima continuam, para o caso de a coluna existir em outra exportação.

`Open Access status` (`diamond`, `gold`, `green`, `hybrid`, `bronze`, `closed`) tem preferência sobre `Open access` para `openAccessStatus`.

Valores: Work ID e referências aceitam a forma curta e a URL; DOI aceita com e sem prefixo; `Open access` aceita `Open Access`/`NOT Open Access`, `True`/`False` e os nomes de status.

CSV sem Work ID e sem DOI: `parse()` devolve os registros da prévia, e o leitor expõe `blockingNotice(): ?string` com a mensagem da spec; `ImportController::upload()` mostra a mensagem e não oferece o botão de confirmar (e, no envio de vários arquivos, ignora o arquivo com o aviso). Recusar em vez de importar por título + ano: sem identificador não há como garantir "sem perder nenhum registro" nem reimportação sem duplicar, e a correção pelo usuário é marcar duas caixas na exportação.

Coluna de referências cortada em 30.000 caracteres (variante "CSV (Excel)"): o último identificador pode vir pela metade; o leitor descarta o item final que não casa `^W\d+$` quando a célula tem 30.000 caracteres.

### D6. TXT no formato Web of Science

`WosImporter`: `parseRecordsFromFile()` e os auxiliares usados pelo mapeamento passam de `private` para `protected`; `detect()` devolve 0 quando os primeiros 200 bytes contêm `FN OpenAlex` (antes das regras atuais) e a regra de 0,6 (`PT X` no começo) continua só para arquivos sem linha `FN`.

`OpenAlexWosImporter extends WosImporter`: `supports('txt','openalex')`; `detect()` = 1,0 com `FN OpenAlex`; `mapToInternalRecord()` chama o do pai e ajusta: `source = 'openalex'`; `externalId` = Work ID curto tirado de `UT` (aceita `W123`, `OpenAlex:W123` e a URL), nulo sem `UT`; `strictIdentity` conforme haja `externalId`; `citedBy` de `TC`, senão `CT`; `language` como vem; marcações vazias (`VL` sozinho) viram nulo. Países e instituições saem de `C1` pelas funções do pai (`[Autor] Instituição, PAÍS`).

Datasets antigos gravados como `wos` a partir de um TXT do OpenAlex: no banco de produção, contar com `SELECT COUNT(*) FROM document WHERE source = 'wos' AND (external_id IS NULL OR external_id NOT LIKE 'WOS:%')` (leitura, tarefa 5.4) e informar o Jonas; nada é alterado.

### D7. RIS

`OpenAlexRisImporter extends AbstractRisImporter`: `supports('ris','openalex')`. O RIS do OpenAlex não tem marca própria (não há `DB`), então `detect()` usa a forma do arquivo, vista no arquivo real: devolve 0,7 quando, nos 5 primeiros registros, todo `UR` é `https://doi.org/…`, há `T2` ou `PB`, `DA` casa `^\d{4}-\d{2}-\d{2}$`, existe ao menos um `C1` logo depois de um `AU` e não existem `AN`, `DB`, `JF`, `JO` nem `M3`; 0 caso contrário. Fica acima do RIS genérico (0,55) e abaixo dos leitores de BRAPCI e BDTD (≥ 0,8). Escolhendo OpenAlex à mão o leitor é usado sempre.

Mapeamento: `TI` → título; `AU` → autores; `PY` → ano; `DA` → data; `T2` → fonte; `PB` → editora; `SN` → ISSN; `VL`, `IS`, `SP`, `EP`; `DO` → DOI (minúsculas); `UR` → endereço; `LA` → idioma; `AB` → resumo; `TY` → tipo (`JOUR` → "article", `CHAP` → "book-chapter", `BOOK` → "book", `THES` → "dissertation", `DATA` → "dataset", `RPRT` → "report", `LETTER` → "letter", `ERRT` → "erratum", `GEN` → "other"), para ficar igual ao tipo do JSON e do CSV; `C1` → afiliações, com as linhas de continuação unidas por espaço e os `\r` removidos, e daí instituições e países por uma classe nova `AffiliationParser`, para onde vai, sem mudar o comportamento, a extração de países e instituições que hoje está em `ScopusCsvImporter` (linhas 215-305).

`KW` traz os conceitos do OpenAlex, e não as palavras-chave: **não são importados**. É a mesma decisão do JSON (conceitos e tópicos ficam de fora), e evita que o mesmo work tenha palavras-chave diferentes conforme o formato. Consequência: documento vindo do RIS fica sem palavras-chave; a prévia avisa.

Sem `strictIdentity`: deduplica por DOI e por título + ano, como as bases atuais (o RIS não traz o Work ID). Os 20 bytes inválidos do arquivo real são tratados pelo `scrub()` do leitor de RIS comum. `previewNotice()` com o aviso da spec.

### D8. Catálogo, guia e tabela global

`DataSources::SOURCES['openalex']`: `formats ['json','csv','txt','ris']`, `limit` nulo, `hasReader` true, `guide` `import/guides/_openalex.html.twig`. O guia substitui o painel atual: entrar na conta; "Export results"; formato "CSV"; acrescentar as colunas Work ID, DOI, Source, ISSN, Type, Language, Institution, Country, Cites; marcar "Include abstracts"; baixar em Settings → Exports; total de resultados em "N works" acima da lista; alternativa do JSON da API (endereço de exemplo com `per-page=100&page=1`, uma página por arquivo, sem chave até 10.000 resultados); aviso de que exportar só as colunas padrão é recusado.

Migration de dados: `UPDATE academic_database SET file_formats = '["json","csv","txt","ris"]', signature_columns = '["Work ID","Title","Year","Citation count"]' WHERE acronym = 'openalex'`. Tabela global; nenhum número muda. O `ImporterResolver` usa essas colunas só como apoio à detecção de CSV.

Textos que citam o OpenAlex como não lido e precisam mudar: `templates/import/index.html.twig` (linhas 540, 545 e 711), `templates/import/preview.html.twig` (linha 53), `config/help/import.yaml` (linhas 16, 130-137, 218 e 284).

## Risks / Trade-offs

- [O TXT e a variante "CSV (Excel)" não foram vistos em arquivo real] → a tarefa 5 começa por uma amostra exportada pelo Jonas; o leitor de CSV já trata BOM e é testado com fixture das duas variantes.
- [O CSV não traz volume, número, páginas nem editora] → campos vazios; o guia recomenda o JSON para quem precisa deles.
- [O RIS não traz Work ID: o mesmo work importado por RIS e por CSV ou JSON só é unido por DOI ou por título + ano] → 305 dos 9.540 registros do RIS real não têm DOI; para esses vale o título + ano, que a mudança de base aplica nos dois sentidos entre um registro com identidade própria e um documento identificado só por título + ano, mesmo sendo da mesma base (design D5 de `importacao-base-novos-formatos`). Teste na tarefa 6.2.
- [O OpenAlex muda o exportador com frequência (três gerações de CSV em três anos)] → leitura por nome com sinônimos; coluna desconhecida é ignorada; a prévia mostra a contagem e os 10 primeiros registros.
- [Dependência nova] → `halaxa/json-machine` é PHP puro, sem extensão; conferir a licença (Apache 2.0) e travar a versão no `composer.lock`.
- [Idioma detectado errado pelo OpenAlex (título em português com `en`)] → gravado como vem; documentado na ajuda.
- [Palavras-chave do OpenAlex em inglês e minúsculas misturam-se às indexadas de outras bases] → entram como indexadas, separadas das do autor; tratamento posterior pelo tesauro.
- [Mudar `WosImporter::detect()` pode afetar a Web of Science] → a mudança só acrescenta a recusa de `FN OpenAlex`; teste de regressão com o cabeçalho da Clarivate e com arquivo sem `FN`.
- [Arquivo JSON enorme deixa a prévia lenta (duas passadas)] → `countRows()` em fluxo; medir com um arquivo de 100 MB gerado no teste (tarefa 2.6).

## Migration Plan

Local com os JSON reais; os outros três formatos entram conforme as amostras chegarem (cada um num commit, podendo ir a produção separadamente). Produção: backup, deploy (`composer install` do `build.sh` traz a dependência), migration de uma linha, teste no navegador em `/projects/22/import`.

## Open Questions

- Marcações exatas do TXT atual (onde vêm o Work ID e as citações). Respondida pela amostra da tarefa 5.1; não muda a spec.
