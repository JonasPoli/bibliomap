# Proposal

## Why

O BiblioMap vai passar a ler quatro bases novas (OpenAlex, BRAPCI, BDTD e LISTA/EBSCO), e a exigência do Jonas é importar sem perder nenhum registro. A importação de hoje foi feita para Scopus, Web of Science, Lens e PubMed, e os arquivos reais das bases novas (analisados em 19/11/2026, em `var/exemplos/`) mostram quatro pontos em que ela perderia registros ou nem aceitaria o arquivo:

- **Extensões e codificação.** As bases novas entregam `.ris`, `.xls`, `.json` e `.zip`, que o envio recusa. O "XLS" da BRAPCI é HTML em Windows-1252 e seria recusado pelo teste de UTF-8, assim como qualquer planilha binária.
- **Deduplicação por título e ano.** Dois registros da mesma base com o mesmo título e o mesmo ano viram um só. No CSV real da BDTD (7.193 registros) isso descartaria 206; no da BRAPCI (884), 14.
- **Deduplicação por DOI não confiável.** No CSV da BRAPCI, 45 registros compartilham 22 DOIs (DOI de obra citada atribuído ao registro) e há DOIs malformados (`10.18617/`, `103-114`).
- **Registros que somem sem rastro.** Um registro que o leitor não interpreta, ou que o banco recusa, só aumenta o contador de erros: não fica gravado qual foi (a tabela `import_error` foi removida). Não há como provar que lidos = importados + duplicados + erros nem comparar com o total que a base mostrou.

Esta mudança prepara a base comum. Os leitores de cada base ficam nas mudanças `importacao-brapci`, `importacao-bdtd`, `importacao-openalex` e `importacao-lista-ebsco`, que dependem desta.

## What Changes

- O envio passa a aceitar `.ris`, `.xls`, `.json` e `.zip` (ZIP com um único arquivo de formato lido), além de `.csv`, `.txt` e `.nbib`.
- O teste de UTF-8 deixa de valer para arquivo binário (`.xls`, `.zip`) e o leitor pode declarar outra codificação (Windows-1252), que o sistema converte antes de ler.
- Leitor de RIS comum (marcações, linhas de continuação, quebras `\n`, `\r\n` e `\r`), usado pelas quatro bases, e um leitor de RIS genérico para arquivo RIS de base sem leitor próprio.
- **Identidade pelo identificador da base:** registro de base que fornece identificador próprio (ID da BRAPCI, Work ID do OpenAlex, número de acesso da EBSCO, chave da linha da BDTD) só é duplicado de outro registro da mesma base quando o identificador é o mesmo. Título e ano continuam valendo entre bases diferentes e para Scopus, Web of Science, Lens e PubMed, que não mudam.
- **Conferência da importação:** todo registro lido termina como importado, duplicado ou com erro, e todo duplicado ou erro fica listado com posição no arquivo, identificador, título e motivo. A tela do dataset mostra a conta "lidos = importados + duplicados + erros" e avisa quando ela não fecha.
- **Total informado pela base:** na prévia o usuário pode informar quantos resultados a base mostrou; o dataset guarda o número e mostra a diferença para o total lido.
- Aviso de possíveis repetições: registros do mesmo dataset com o mesmo título e ano são listados na tela do dataset (continuam importados).
- A base escolhida à mão deixa de prevalecer quando o arquivo é claramente de outra base.
- A base detectada passa a ser gravada pela chave (`lens`), e não pelo nome de exibição (`Lens.org`), e as telas de importação deixam de ter listas fixas de bases.
- Migration de esquema: colunas novas em `dataset` e `dataset_skip`. Nenhum dado existente muda.

## Capabilities

### New Capabilities
<!-- Nenhuma: a base comum pertence à capacidade de importação que já existe. -->

### Modified Capabilities
- `importacao-de-datasets`: mudam "Formatos e tamanho aceitos", "Arquivo fora de UTF-8", "Escolha do leitor", "Registro sem DOI e sem ano também é deduplicado" e "Resultado da importação"; entram os requisitos de arquivo compactado, codificação declarada pelo leitor, RIS genérico, identidade pelo identificador da base, conferência da importação, total informado pela base, possíveis repetições e bases da tela vindas do catálogo.

## Impact

- Código: `src/Controller/ImportController.php`, `src/Controller/DatasetController.php`, `src/Command/ImportDatasetCommand.php`, `src/Service/Import/DocumentImportService.php`, `ImporterResolver.php`, `BibliographicImporterInterface.php`, `TextNormalizer.php`, `WosImporter.php` (só o nome da base), `src/DTO/BibliographicRecordDTO.php`, `src/Entity/Dataset.php`, `src/Entity/DatasetSkip.php`, `src/Config/DataSources.php`, `config/services.yaml`; arquivos novos em `src/Service/Import/` (leitor de RIS, RIS genérico, abertura de ZIP, conversão de codificação).
- Telas: `templates/import/index.html.twig`, `templates/import/preview.html.twig`, `templates/import/processing.html.twig`, `templates/dataset/show.html.twig`, `templates/dataset/edit.html.twig`; ajuda em `config/help/import.yaml`.
- Banco: migration que acrescenta `dataset.expected_count` e `dataset.read_count` e, em `dataset_skip`, `external_id`, `position` e `detail`. Só esquema; produção exige backup antes.
- Dependências: nenhuma nova nesta mudança (`ext-zip`, `mbstring` e `iconv` já estão no PHP local; conferir no servidor).
- Comportamento existente: importações de Scopus, Web of Science, Lens e PubMed dão o mesmo resultado (teste de regressão com arquivo real de cada uma).
