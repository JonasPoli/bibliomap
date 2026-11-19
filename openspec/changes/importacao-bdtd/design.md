# Design

## Context

Formato verificado nos arquivos reais exportados pelo Jonas em 19/11/2026 (`var/exemplos/bdtb/search_result--1705555757-csv.zip` e `…-ris.zip`, busca por "inteligência artificial", 7.193 registros em cada).

**Entrega:** o botão "Exportar" da página de resultados (`/vufind/bulkexport/home?total=N&type=csv|ris`) pede as caixas "Incluir resumo em português" e "Incluir resumo em inglês", e-mail e reCAPTCHA. Entrega `search_result-<hash>-csv.zip` com um único `search_result-<hash>-csv.csv` (5,6 MB compactado, 19,7 MB aberto) ou o equivalente `-ris`. Não há como baixar por script.

**CSV:**

- UTF-8 sem BOM, quebra `\n`, delimitador vírgula, aspas só quando o campo precisa, aspas internas duplicadas. 7.194 linhas físicas = cabeçalho + 7.193 registros; nenhuma célula com quebra de linha; todas as linhas com 25 campos.
- Cabeçalho literal (25 colunas): `Autor(a),ID Lattes do(a) autor(a),Orientadores,ID Lattes dos orientadores,Membros da banca,ID Lattes dos membros da banca,Título,Ano de defesa,Instituição de defesa,Sigla da instituição de defesa,País da instituição de defesa,Departamento da instituição de defesa,Programa de Pós-Graduação da instituição de defesa,Área do conhecimento CNPq,Tipos de acesso,Tipo de documento,Assuntos em português,Assuntos em inglês,Idioma,Resumo,Link de acesso,Identificador persistente ARK,Resumo em Português,Resumo em Inglês,Referência Bibliográfica`.
- **Não há coluna de identificador do registro.**
- Valor ausente: texto `Não informado pela instituição` (nunca célula vazia). Presença do texto por coluna, em 7.193: orientadores 2.754; país 5.454; programa 5.251; CNPq 5.260; assuntos pt 947; assuntos en 5.566; resumo 245; link 839; ARK 7.127; resumo pt 7.174; resumo en 7.176; título 1; autor 1.
- Multivalor: `||`. O texto "não informado" aparece também como item de lista (`Cruz, Rogério Schietti Machado||Não informado pela instituição`): 4.368 linhas na coluna de orientadores.
- `Autor(a)`: "Sobrenome, Nome" em 6.729; maiúsculas em ordem direta em parte (`PEDRO ALBERTO SCHILLER DE FARIA`). 199 linhas com `||`, trazendo orientador, data (`2024-10-10`), ORCID (`https://orcid.org/…`) ou e-mail. Sufixos `, 1973-` e ` [UNESP]`.
- `Título`: 122 com marca `[pt] ` no início.
- `Ano de defesa`: sempre 4 dígitos.
- `Instituição de defesa`: `Nome (SIGLA)`, sempre presente; `Sigla da instituição de defesa` sempre presente (`PUC_RIO`, `UFSCAR`).
- `País`: `Brasil` 1.389, `BR` 261, `brasil` 78, `BRASIL` 8, `Estados Unidos` 2, não informado 5.454.
- `Tipo de documento`: `masterThesis` 4.986, `doctoralThesis` 2.207.
- `Tipos de acesso`: `openAccess` 7.108, `embargoedAccess` 83, `restrictedAccess` 2.
- `Assuntos em português`: `||`; 121 linhas com marcas `[pt]`/`[en]` misturando os dois idiomas na mesma coluna.
- `Idioma`: `por` 6.456, `eng` 550, combinações (`por||pt_BR`, `por||eng`, `eng||en_US`, `it||por||eng`); itens `pt_BR`, `pt`, `en_US`, `en`, `spa`, `it`.
- `Resumo`: uma string, às vezes com marca `[pt]`; pode estar em inglês mesmo com idioma `por`. Entidades HTML em 189 células (`&apos;`, `&lt;`, `&quot;`, `&nbsp;`, `&#8211;`, `&#8195;`).
- `Link de acesso`: 1.239 linhas com vários itens; 839 não informado; itens que não são endereço (`(Broch.)` 131, códigos como `20002017004P9`, trechos de citação). 243 registros têm um item `doi.org`; nenhum DOI repetido.
- `Área do conhecimento CNPq`: `CIENCIAS SOCIAIS APLICADAS::DIREITO`, às vezes com prefixo `CNPQ::`, às vezes várias por `||`.
- Repetições: 205 grupos de título + ano (411 registros); 201 com o mesmo autor; 101 com a mesma sigla. Nenhuma linha 100% idêntica a outra. Chave (autor, título, ano, link) tem 7.187 valores distintos; com a sigla, 7.193.

**RIS:**

- UTF-8 sem BOM (1 byte inválido em 13,9 MB), quebra `\n`, 7.193 blocos.
- Marcações por registro, nesta ordem: `TY` (`masterThesis`/`doctoralThesis`), `AU` (7.469: repete com os mesmos ruídos da coluna de autor), `PY`, `TI`, `LA`, `AB`, `DI` (sempre vazio), `UR`, `JO` (sempre vazio), `ER`. Nenhuma linha de continuação.
- Não traz instituição, orientador, assuntos nem tipo de acesso. `UR` traz a coluna `Link de acesso` inteira (com `||`).

A API pública do VuFind da BDTD (`/vufind/api/v1/search` com `rawData`) traz o identificador do registro e mais campos, mas tem um teto silencioso de 1.000 resultados por busca e não é o que o pesquisador exporta pela tela.

## Goals / Non-Goals

**Goals:**
- Importar 7.193 de 7.193 registros do CSV e do RIS reais.
- Nenhum texto "Não informado pela instituição" gravado como dado.
- Reimportação do mesmo arquivo sem criar documento.

**Non-Goals:**
- Guardar orientador, coorientador, banca, Lattes, programa, departamento, área CNPq, tipo de acesso e referência bibliográfica: não há coluna em `document`. Ficam para uma mudança própria ("dados de tese"), que decidirá onde guardar e que análises usam.
- Buscar registros pela API do VuFind.
- Unir a mesma tese vinda de dois repositórios: é importada duas vezes e listada em "Possíveis repetições".
- Ler os formatos de registro avulso do VuFind (EndNote, BibTeX).

## Decisions

### D1. Dois leitores e um limpador comum

- `BdtdFieldCleaner` (estático, sem estado): `value(?string): ?string` (nulo quando vazio ou "Não informado pela instituição", comparando sem diferenciar maiúsculas e com `trim`); `list(?string): array` (`explode('||')`, `value()` em cada item, sem repetidos); `stripLangTag(string): string` (`/^\[[a-z]{2}\]\s*/i`); `author(array $itens): ?string`; `language(array $itens): ?string`; `links(array $itens): array{url: ?string, doi: ?string}`; `rowKey(...)`.
- `BdtdCsvImporter` usa `League\Csv\Reader` como os leitores de CSV atuais (o arquivo é CSV padrão).
- `BdtdRisImporter` estende `AbstractRisImporter`.

Ambos com `getSourceKey() = 'bdtd'`, `strictIdentity = true`.

### D2. Detecção

- CSV: fração das 8 colunas da assinatura da spec presentes no cabeçalho; as 8 dão 1,0. Nenhuma coluna em comum com Scopus, Lens, PubMed ou BRAPCI.
- RIS: nas primeiras 200 linhas, todo `TY` é `masterThesis` ou `doctoralThesis` → 1,0. `previewNotice()` devolve o aviso da spec.

### D3. Autor

`author()` percorre os itens na ordem e devolve o primeiro que passa em todos os filtros:

- não é nulo depois de `value()`;
- não casa `^\d{4}(-\d{2}(-\d{2})?)?$` (data);
- não começa com `http://` ou `https://` (ORCID, Lattes);
- não contém `@` (e-mail);
- tem ao menos duas letras.

Depois remove `,\s*\d{4}-(\d{4})?\.?$` (ano de nascimento) e `\s*\[[^\]]+\]$` (instituição). Só o primeiro nome é usado porque tese tem um autor; os 199 casos com mais de um item trazem o orientador ou ruído no restante. Autor em maiúsculas e ordem direta é passado como vem: a normalização de autores do sistema (`TextNormalizer::normalizeAuthor`) já trata as duas formas.

### D4. Demais campos

| Coluna | Destino | Regra |
|---|---|---|
| `Título` | `title` | `value()`, `stripLangTag()`, entidades decodificadas |
| `Ano de defesa` | `year` | 4 dígitos |
| `Tipo de documento` | `documentType` | `masterThesis` → "Dissertação (mestrado)"; `doctoralThesis` → "Tese (doutorado)"; outro valor → como vem |
| `Instituição de defesa` | `institutions` (um item), `publisher` | sem o ` (SIGLA)` final: `/\s*\([^()]+\)\s*$/` |
| `País da instituição de defesa` | `countries` (um item) | `Brasil`, `BR`, `brasil`, `BRASIL` e não informado → "Brasil"; outro valor → como vem |
| `Assuntos em português` + `Assuntos em inglês` | `authorKeywords` | `list()` das duas, `stripLangTag()` em cada item, sem repetidos (comparação sem maiúsculas) |
| `Idioma` | `language` | primeiro item de `list()`; `pt_BR`, `pt` → `por`; `en_US`, `en` → `eng`; `es` → `spa` |
| `Resumo em Português`, `Resumo` | `abstractText` | a primeira informada, nessa ordem; `stripLangTag()`; entidades decodificadas |
| `Link de acesso` | `url`, `doi` | D5 |
| `Tipos de acesso` | `openAccessStatus` | `openAccess` → "Open Access"; `embargoedAccess` → "Embargoed"; `restrictedAccess` → "Closed" |

País padrão Brasil: a BDTD só reúne instituições brasileiras e 76% das linhas não informam o país; sem o padrão, o mapa de países do projeto ficaria quase vazio. As 2 linhas com "Estados Unidos" são gravadas como vêm.

`sourceTitle`, `issn`, `volume`, `issue` e páginas ficam nulos. A instituição vai em `institutions` para o enriquecimento ligar ao cadastro de instituições de ensino; as que não casarem aparecem na lista de instituições não encontradas, como em qualquer importação.

Entidades: mesma função usada na BRAPCI (`html_entity_decode` com `ENT_QUOTES | ENT_HTML5`, U+00A0 e U+2003 viram espaço).

### D5. Endereço e DOI

`links()` percorre os itens de `list()`:

- ignora o que não começa com `http://` ou `https://`;
- item cujo host é `doi.org` ou `dx.doi.org`: o caminho, em minúsculas, vira o `doi` se casar `^10\.\d{4,9}/\S+$` (só o primeiro);
- `url` = primeiro item `http(s)` que não é DOI; se não houver, o endereço do DOI.

O DOI da BDTD é do próprio trabalho (registrado pela instituição): nos 243 casos do arquivo real não há repetição, então é aceito sem a regra restritiva da BRAPCI.

### D6. Chave da linha

`externalId` = `'bdtd:' . substr(sha1(autor, título, ano, endereços), 0, 40)` (45 caracteres; a coluna tem 100), com os quatro valores separados por `\x1f`:

- autor e título como vieram na célula (com `trim`), e "Não informado pela instituição" contando como vazio;
- ano como veio;
- endereços: só os itens `http(s)` do link, unidos por `|`.

Quando a mesma combinação já apareceu no arquivo, acrescenta `#2`, `#3`… (contador por chave durante a leitura; 7.193 chaves de 45 bytes são desprezíveis em memória).

No RIS, os mesmos valores vêm de `AU` (as ocorrências unidas por `||`), `TI`, `PY` e `UR`. A normalização dos endereços e do "não informado" é necessária porque o RIS separa os itens do link com `; ` (o CSV usa `||`) e deixa vazio o que o CSV chama de "não informado". Conferido nos arquivos reais (19/11/2026): com a normalização, as 7.193 chaves do RIS e do CSV coincidem registro a registro (7.183 distintas e 10 com `#2`); sem ela, só 5.115 coincidiam.

A sigla da instituição ficou de fora da chave de propósito: o RIS não tem sigla e as chaves dos dois formatos deixariam de coincidir. Custo: se a ordem das linhas repetidas mudar entre duas exportações, as que têm `#2` trocam de chave entre si, o que não altera o resultado (as duas existem nos dois arquivos).

A chave depende do conteúdo: se a BDTD corrigir o título de um registro, uma exportação futura gera chave nova e o registro entra de novo. Aí vale a rede de segurança da mudança de base: como é a mesma base, título + ano não une; ficam os dois, listados em "Possíveis repetições". É o comportamento coerente com "não perder registro".

### D7. Registro sem título

A linha com título não informado tem autor, ano, instituição e link: `title` nulo, `externalId` presente, portanto passa na validação de `importAll()` (exige título, DOI ou identificador) e é importada. A lista de documentos do projeto mostra "(sem título)".

### D8. Catálogo e tabela global

`DataSources::SOURCES['bdtd']`: `label` "BDTD", `vendor` "IBICT", `url` `https://bdtd.ibict.br`, `formats` `['zip','csv','ris']`, `limit` nulo, `hasReader` true, `guide` `import/guides/_bdtd.html.twig`, `logo` nulo.

Migration de dados: linha `('BDTD', 'bdtd', 'https://bdtd.ibict.br', '["zip","csv","ris"]', '["Autor(a)","Ano de defesa","Instituição de defesa","Tipo de documento"]')` em `academic_database`, com `WHERE NOT EXISTS`. Efeito em todos os projetos: a base aparece no cadastro de bases; sem ISSN, nenhuma tese cria vínculo de periódico. Nenhum número existente muda.

## Risks / Trade-offs

- [7.193 teses criam dezenas de milhares de palavras-chave e milhares de autores nas tabelas globais] → é o efeito normal de uma importação grande; no servidor de 1 vCPU o enriquecimento de 7 mil documentos leva alguns minutos. Medir no teste local (tarefa 5.2) antes de produção.
- [A mesma tese duas vezes infla contagens de autores e instituições] → listada em "Possíveis repetições"; decisão assumida de não perder registro.
- [País padrão Brasil errado para instituição estrangeira sem país informado] → a BDTD só agrega instituições brasileiras; risco residual documentado na ajuda.
- [A BDTD mudar as colunas (o exportador já mudou desde a versão pública de 2021)] → leitura por nome de coluna; a assinatura usa 8 colunas centrais e a prévia mostra a contagem; coluna nova é ignorada, coluna ausente fica nula.
- [Limite de registros por exportação na origem não verificado] → o campo "Total de resultados mostrado pela base" mostra a diferença; o guia orienta a comparar.
- [Assuntos em maiúsculas (`INTELIGENCIA ARTIFICIAL`) viram variantes de palavra-chave] → tratado depois pelo tesauro e pelo tratamento de palavras-chave já existentes.

## Migration Plan

Local com os arquivos reais; produção com backup, deploy, migration de uma linha e teste no navegador em `/projects/22/import` (tarefas 5 e 6). O teste em produção usa uma busca pequena (centenas de registros), não o arquivo de 7.193, para não disputar a CPU do servidor compartilhado em horário de uso.

## Open Questions

- Qual o limite de registros por exportação do botão da BDTD e a partir de que tamanho o arquivo chega só por e-mail. Não muda o leitor.
