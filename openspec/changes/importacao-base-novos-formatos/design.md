# Design

## Context

Estado atual, lido em 19/11/2026:

- `ImportController::upload()` aceita só `csv`, `txt`, `nbib` (lista `$allowedExts`, linha 79), grava em `var/uploads/{projeto}/import_<uniqid>.<ext>`, chama `TextNormalizer::isUtf8File()` e depois `ImporterResolver::detectAll()`. Com um arquivo mostra a prévia; com vários cria os datasets direto, gravando em `source` o **nome de exibição** do leitor (`$detected['source']` vem de `getSourceName()`: "Lens.org", "Web of Science"). `supports()` do Lens não reconhece "lens.org", então no envio múltiplo o comando só acerta o leitor porque cai na detecção automática.
- Os leitores implementam `BibliographicImporterInterface` (`supports`, `detect`, `parse`, `mapToInternalRecord`, `getSourceName`, `getFormatName`) e, por convenção checada com `method_exists`, `parseStream()` (gerador de `BibliographicRecordDTO|null`) e `countRows()`. São registrados em `config/services.yaml` com a tag `app.bibliographic_importer`.
- `DocumentImportService::importAll()` deduplica por DOI e por `computeHash()` (DOI; senão título + ano; senão base + identificador; senão título). A tabela `document` tem `UNIQUE (project_id, hash)`. Erro de leitura (`null` do gerador) e erro de gravação só somam em `$stats['errors']`: nada é gravado sobre o registro.
- `dataset_skip` guarda `title`, `doi`, `hash`, `reason` (`doi`, `hash`, `db_constraint`) e o documento encontrado. `dataset` guarda `records_count` (contado na prévia), `imported_count`, `duplicated_count`, `error_count`.
- As telas têm listas fixas de bases: `templates/import/index.html.twig` (abas nas linhas 183-214 e painéis 220-634), `templates/import/preview.html.twig` (linhas 20-29 e 211-215) e `templates/dataset/edit.html.twig` (linhas 97-101). O catálogo é `App\Config\DataSources::SOURCES`; a tabela `academic_database` repete as bases para o vínculo com o Qualis.
- Medidas nos arquivos reais (`var/exemplos/`): CSV da BDTD com 7.193 registros e 205 grupos de título + ano repetidos (411 registros); CSV da BRAPCI com 884 registros, 14 grupos de título + ano repetidos e 22 DOIs compartilhados por 45 registros; RIS da BDTD com 1 byte UTF-8 inválido em 13,9 MB.

Restrições: servidor de 1 vCPU e 2 GB compartilhado; a importação roda com `memory_limit=768M`; o repositório é público (nada de arquivo de corpus no commit); `document.hash` gravado para as bases atuais não pode mudar.

## Goals / Non-Goals

**Goals:**
- Aceitar os formatos das bases novas sem enfraquecer as validações das atuais.
- Garantir, por construção, que lidos = importados + duplicados + erros e que cada duplicado ou erro é localizável no arquivo.
- Dar aos leitores novos as peças comuns (RIS, codificação, ZIP, identidade) para que cada mudança de base seja só o mapeamento.

**Non-Goals:**
- Buscar registros pela API das bases (o sistema continua lendo só arquivo enviado).
- Mesclar ou apagar repetições dentro da mesma base: elas são importadas e listadas.
- Guardar campos sem coluna em `document` (orientador, banca, programa, seção, classificação).
- Mudar o enriquecimento, o Qualis ou qualquer cálculo bibliométrico.
- Ler BibTeX, XLSX ou XML.

## Decisions

### D1. Extensões e ZIP

`$allowedExts` passa a `['csv','txt','nbib','ris','xls','json','zip']`, numa constante de `ImportController` usada nos dois ramos (um arquivo e vários) e no `accept` do `<input type="file">`.

ZIP: classe nova `App\Service\Import\ZipUploadExtractor` com `extract(string $zipPath, string $destDir): string` (devolve o caminho extraído) usando `ZipArchive`. Regras: ignora entradas que terminam em `/`, que começam com `__MACOSX/` ou cujo nome-base começa com `._`; exige exatamente uma entrada restante; extensão dela em `csv|txt|nbib|ris|xls|json`; `statIndex()['size']` ≤ 600 MB; recusa entrada cifrada (`encryption_method != 0`). Extrai por `getStream()` copiando para `import_<uniqid>.<ext interna>` (nunca usa o nome de dentro do ZIP como caminho), contando os bytes copiados e abortando acima de 600 MB. O `.zip` enviado é apagado depois da extração; `original_filename` continua sendo o nome do ZIP. Daí em diante o fluxo é o de um arquivo comum.

Alternativa descartada: pedir ao usuário para descompactar. A BDTD só entrega ZIP, e um passo manual a mais é fonte de erro.

### D2. Codificação

- `ImportController` e `ImportDatasetCommand` chamam um método novo `TextNormalizer::needsUtf8Check(string $path): bool`, falso quando o arquivo começa com a assinatura OLE2 (`D0 CF 11 E0 A1 B1 1A E1`) ou `PK\x03\x04`.
- Interface nova opcional `App\Service\Import\DeclaresEncodingInterface { public function sourceEncoding(string $filePath): ?string; }`. O único uso previsto é o leitor do XLS da BRAPCI (`Windows-1252`). Antes de recusar um arquivo fora de UTF-8, o controller pergunta aos leitores que o detectam (pontuação > 0,5); se algum declarar codificação, o arquivo é aceito.
- A conversão não regrava o arquivo enviado: o leitor lê por um `stream_filter` (`convert.iconv.WINDOWS-1252/UTF-8`) ou converte linha a linha com `mb_convert_encoding`. Assim o arquivo original fica intacto para auditoria.
- `TextNormalizer::scrub(string $s): string` (`mb_scrub`) é aplicado pelos leitores de texto a cada linha: troca byte inválido isolado por U+FFFD. `isUtf8File()` só olha os primeiros 256 kB, então um byte ruim no meio de um arquivo grande hoje estouraria no INSERT.

Alternativa descartada: adivinhar a codificação de qualquer arquivo fora de UTF-8. Para as bases atuais um arquivo fora de UTF-8 é sinal de exportação errada, e adivinhar gravaria acentos trocados sem ninguém perceber.

### D3. Leitor de RIS comum

`App\Service\Import\Ris\RisReader`:

- `records(string $path): \Generator` entrega, por registro, `['fields' => array<string, list<string>>, 'position' => int]` (posição contada a partir de 1).
- Lê com `fgets` depois de detectar a quebra de linha nos primeiros 64 kB: se há `\r` sem `\n`, lê o arquivo por blocos e divide em `\r` (o RIS da BRAPCI só tem `\r`); senão `fgets` normal com `rtrim("\r\n")`.
- Tira BOM. Linha de marcação: `/^([A-Z][A-Z0-9])  -(?: (.*))?$/`. Linha que não casa e não é vazia é continuação: concatena com `\n` ao último valor do registro em aberto. `TY` abre registro (se já havia um aberto sem `ER`, fecha o anterior); `ER` fecha. Registro aberto no fim do arquivo é entregue.
- `count(string $path): int` conta linhas `TY  -` com a mesma leitura (serve de `countRows`).
- Aplica `TextNormalizer::scrub()` em cada linha.

`AbstractRisImporter` (base dos leitores RIS de cada base): implementa `parse`, `parseStream`, `countRows` sobre o `RisReader` e deixa para a subclasse `detect()`, `sourceKey()` e `mapFields(array $fields, int $position): BibliographicRecordDTO`. Helpers: `first()`, `all()`, `doiFrom()` (tira `https?://(dx\.)?doi\.org/`, minúsculas, valida `^10\.\d{4,9}/\S+$`), `yearFrom()` (primeiros 4 dígitos de PY ou Y1), `pages()`.

`GenericRisImporter` (`source = generic`): `detect()` devolve 0,55 quando as primeiras 200 linhas não vazias têm ao menos um `TY  -` e 80% casam com a regex de marcação; mapeia as marcações usuais; não usa identidade pela base (D5), ou seja, deduplica como hoje. Fica abaixo dos leitores de base (que devolvem ≥ 0,8 quando reconhecem o dialeto).

Alternativa descartada: biblioteca de terceiros para RIS. O formato é simples, os dialetos das bases fogem do padrão (TY com `masterThesis`, só `\r`, continuação sem marcação) e uma dependência nova teria de ser adaptada do mesmo jeito.

### D4. Chave da base no leitor

`BibliographicImporterInterface` ganha `getSourceKey(): string` (chave de `DataSources`: `scopus`, `wos`, `lens`, `pubmed`, …). `ImporterResolver::detectAll()` passa a devolver também `sourceKey`; `ImportController` grava `sourceKey` no dataset e na sessão (`import_source`). `preview.html.twig` usa `detected.sourceKey` no lugar da cadeia de `if` com nomes. `supports()` de cada leitor continua aceitando os nomes antigos, porque há datasets gravados com "Lens.org" e "Web of Science".

`ImporterResolver::resolve()` ganha a regra do requisito "Escolha do leitor": depois de achar o leitor pela base declarada, calcula `detect()` dele e o melhor `detect()` geral; se o declarado < 0,3 e o melhor ≥ 0,8, devolve o melhor. O comando percebe a troca comparando `getSourceKey()` do leitor devolvido com `dataset.source`: quando diferem e a base declarada não era `generic`, `unknown` nem vazia, ele troca `dataset.source` para a chave detectada, escreve o aviso no log da importação e grava na coluna nova `dataset.import_notice` (texto curto, anulável; migration em D7), mostrada na tela do dataset.

Alternativas descartadas para guardar o aviso: `dataset.error_message` (é do erro e a tela o mostra em vermelho) e uma linha em `dataset_skip` (a tabela lista registros, e o aviso é do arquivo).

### D5. Identidade pelo identificador da base

`BibliographicRecordDTO` ganha:

- `public bool $strictIdentity = false;` verdadeiro quando o leitor garante que `externalId` identifica o registro na base.
- `public ?int $position = null;` posição do registro no arquivo (a partir de 1), preenchida por todos os leitores novos e, nos atuais, pelo próprio `importAll()` com um contador quando vier nulo.

`DocumentImportService::computeHash()`:

```
if ($dto->doi)                      → md5('doi:' . lower(doi))          (igual a hoje)
if ($dto->strictIdentity && $dto->externalId)
                                    → md5('ext:' . lower(source) . ':' . externalId)
if ($dto->title && $dto->year)      → md5(título normalizado . ':' . ano) (igual a hoje)
… resto igual a hoje
```

Como `strictIdentity` é falso em Scopus, WoS, Lens, PubMed e no RIS genérico, o hash deles não muda (teste `testHashComDoiOuTituloEAnoNaoMuda` continua valendo e ganha um caso com `strictIdentity`).

Duplicado entre bases por título + ano, sem coluna nova: no início de `importAll()` o serviço monta em memória `titleYearIndex[md5(título normalizado:ano)] = [docId, source, porTitulo]` com uma consulta `SELECT id, source, title, year, hash FROM document WHERE project_id = ? AND title IS NOT NULL AND year IS NOT NULL` percorrida com `iterateAssociative` (no maior projeto, 50 mil linhas; guarda só md5 + id + base + um booleano, cerca de 10 MB). `porTitulo` é verdadeiro quando o `hash` gravado do documento é a própria chave título + ano, ou seja, quando o documento foi identificado só por título e ano (sem DOI e sem identidade própria). Regra por registro, depois do teste de DOI e de hash:

- registro com `strictIdentity`: se a chave título + ano existe no índice com **outra base**, ou com a mesma base mas `porTitulo` verdadeiro (por exemplo, um RIS do OpenAlex, que não traz o Work ID, importado antes do CSV), é duplicado (motivo `hash`, documento encontrado = o do índice); se existe com a mesma base e `porTitulo` falso (dois registros com identificadores próprios diferentes), não é duplicado.
- registro sem `strictIdentity` (bases atuais, RIS genérico, RIS do OpenAlex): o hash dele já é título + ano e casa com documentos identificados do mesmo jeito, como hoje; além disso, se a chave existe no índice apontando para documento com identidade própria, de qualquer base, é duplicado (motivo `hash`). Sem isso, importar a BRAPCI antes do Scopus deixaria passar o que importar o Scopus antes da BRAPCI barraria.
- todo documento aceito entra no índice, para valer dentro do mesmo arquivo e entre arquivos da mesma execução.

A normalização do título no índice é a mesma de `computeHash()` (`preg_replace('/\s+/', ' ', strtolower(trim()))`), para os dois caminhos concordarem.

DOI: a regra de DOI não muda no serviço. Cabe a cada leitor só preencher `dto->doi` quando o DOI é confiável (o da BRAPCI tem regra própria na mudança `importacao-brapci`).

Alternativas descartadas:
- Coluna `title_year_hash` em `document` com preenchimento retroativo: exigiria migration de dados em 104 mil documentos de produção para ganhar o que uma consulta por importação resolve.
- Manter título + ano para todas as bases: descartaria 206 registros da BDTD e 14 da BRAPCI nos arquivos de exemplo, contra a exigência de não perder registro.
- Nunca deduplicar por título entre bases: duplicaria nos relatórios o mesmo artigo vindo de Scopus e BRAPCI sem DOI.

### D6. Conferência

`importAll()` passa a contar `read` (todo item recebido do gerador, inclusive `null`) e devolve `['read','imported','skipped','errors']`. Toda saída que não é "importado" grava uma linha em `dataset_skip`:

| Situação | `reason` | `detail` |
|---|---|---|
| DOI já existe | `doi` (como hoje) | |
| hash ou título + ano de outra base | `hash` (como hoje) | |
| recusado pelo banco por duplicidade | `db_constraint` (como hoje) | |
| gerador entregou `null` | `error_parse` | mensagem do leitor, se houver |
| sem título, DOI e identificador | `error_invalid` | "sem título, DOI e identificador" |
| exceção ao montar ou gravar | `error_db` | mensagem cortada em 500 caracteres |

Para `error_parse` ter mensagem e posição, os leitores novos entregam, no lugar de `null`, um objeto `App\DTO\UnreadableRecord { int $position; string $message; ?string $externalId; ?string $title; }`. `importAll()` trata `null` (leitores atuais) e `UnreadableRecord` do mesmo jeito, só com mais detalhe no segundo.

`dataset_skip` ganha `external_id` (100), `position` (inteiro) e `detail` (500), todos anuláveis, preenchidos também para os motivos antigos.

Em `flushBatchRowByRow()`, os dois pontos que hoje só fazem `$errors++` chamam um novo `recordErrorSkip($docRow, $dto, $message)`.

`ImportDatasetCommand::doImportDataset()` grava `dataset.read_count = $stats['read']`. `records_count` continua sendo o contado na prévia. `Dataset` ganha `getReconciliation(): array` com: `read`, `imported`, `duplicated`, `errors`, `closes` (read = soma), `previewDiff` (records_count − read, quando records_count > 0), `expectedDiff` (expected_count − read, quando expected_count não é nulo). `templates/dataset/show.html.twig` mostra a conta e os avisos; a lista de duplicados que já existe ganha as colunas posição, identificador e motivo legível, e inclui os erros.

Possíveis repetições: `DatasetController::show()` roda `SELECT normalized_title, year, COUNT(*) c, GROUP_CONCAT(id) FROM document WHERE dataset_id = ? AND normalized_title IS NOT NULL AND year IS NOT NULL GROUP BY normalized_title, year HAVING c > 1 ORDER BY c DESC LIMIT 200`. `normalized_title` é TEXT: conferir o plano com `EXPLAIN` no projeto de 50 mil documentos; se passar de 2 s, limitar a datasets de até 20 mil documentos e mostrar a contagem só sob clique.

### D7. Migration

Uma migration de esquema:

```sql
ALTER TABLE dataset ADD read_count INT DEFAULT NULL, ADD expected_count INT DEFAULT NULL, ADD import_notice VARCHAR(500) DEFAULT NULL;
ALTER TABLE dataset_skip ADD external_id VARCHAR(100) DEFAULT NULL, ADD position INT DEFAULT NULL, ADD detail VARCHAR(500) DEFAULT NULL;
```

`dataset_skip` em produção tem dezenas de milhares de linhas; `ADD COLUMN` anulável no MySQL 8.4 é instantâneo (`ALGORITHM=INSTANT`). Escrita em produção: só esquema, com `mysqldump` antes. Datasets antigos ficam com `read_count` nulo e a tela usa a soma dos três contadores para eles.

### D8. Total informado pela base

Campo numérico opcional `expected_count` no formulário de `preview.html.twig`, ao lado do período da busca; `ImportController::confirm()` valida inteiro entre 1 e 10.000.000 e grava. No envio de vários arquivos o campo não existe (não há prévia); pode ser preenchido depois em `dataset/edit.html.twig`. Entrada de ajuda nova `import.preview.expected_count`.

### D9. Telas sem lista fixa

`DataSources::SOURCES` ganha, por base, `hasReader` (bool) e `guide` (nome do parcial Twig com o guia de exportação, ex. `import/guides/_brapci.html.twig`). `index.html.twig` monta abas e painéis percorrendo `data_sources` onde `hasReader` é verdadeiro, incluindo o parcial; os painéis atuais de Scopus, WoS, Lens e PubMed são movidos sem mudar o texto para `templates/import/guides/`. `preview.html.twig` e `dataset/edit.html.twig` montam os cartões pelo mesmo catálogo. As bases cadastradas em `academic_database` sem leitor continuam aparecendo como hoje (laço que já existe), excluindo as chaves do catálogo.

O painel do OpenAlex hoje diz "Ainda sem leitor"; ele continua assim até a mudança `importacao-openalex` ligar `hasReader`.

### D10. Mensagens

A mensagem de formato não suportado e a de "Formato não reconhecido" passam a montar a lista de formatos a partir do catálogo (`DataSources` com `hasReader`), para não ficarem desatualizadas a cada base nova.

## Risks / Trade-offs

- [Importação um pouco mais lenta e com mais memória pelo índice de título + ano] → uma consulta por importação; medir no projeto de 50 mil documentos (tarefa 4.6) e registrar tempo e pico de memória.
- [Repetições reais dentro da mesma base passam a ser importadas e inflam contagens] → listadas em "Possíveis repetições" no dataset; é a consequência assumida de não perder registro.
- [Ordem de importação muda o resultado quando o mesmo título + ano vem de duas bases] → o índice vale nos dois sentidos (D5); teste cobre as duas ordens.
- [ZIP malicioso (caminho relativo, bomba de descompactação)] → nome gerado pelo sistema, uma entrada só, limite de 600 MB conferido no cabeçalho e durante a cópia.
- [Refatorar `index.html.twig` (800 linhas) pode quebrar o guia das bases atuais] → mover os painéis sem alterar texto e comparar a tela antes e depois no navegador (tarefa 6.5).
- [Datasets antigos com `source` gravado como nome de exibição] → `supports()` continua aceitando os nomes; nenhuma migration de dados.
- [`DatasetSkip` passa a guardar erros com mensagem de exceção] → mensagem cortada em 500 caracteres e só visível ao dono do projeto.

## Migration Plan

1. Local: migration, testes, importação de um arquivo real de cada base atual comparando contagens com as de antes.
2. Produção (escrita, com aprovação do Jonas): `mysqldump` → `git pull --ff-only` → `./build.sh --prod` → `doctrine:migrations:migrate` → conferência (tarefa 7).
3. Volta: a migration tem `down()` que remove as colunas; o código anterior ignora colunas a mais, então voltar o código sem voltar a migration também funciona.

## Open Questions

- O `EXPLAIN` da consulta de possíveis repetições no maior projeto decide se a lista aparece direto ou sob clique. Não muda a spec.
