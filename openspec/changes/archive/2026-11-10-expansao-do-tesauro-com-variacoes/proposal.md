# Proposal

## Why

Com "Utilizar Tesauro Global" ligado (o padrão de todo grupo novo), a classificação temática deveria ampliar cada termo com os sinônimos do tesauro e com as variações gravadas da palavra-chave correspondente, como a ajuda da tela promete. A consulta das variações aponta para uma tabela e uma coluna que não existem (`keyword_variation.raw_variation`; a tabela real é `palavra_chave_variacoes_nome`, coluna `variation_name`). O erro é engolido em silêncio e só os rótulos do tesauro global entram. O código foi escrito em 21/08/2026 (705fd5c), um mês depois de a tabela ter sido renomeada (34ca850, 14/07/2026). Por isso nenhuma classificação feita desde então usou as variações. Decisão do Jonas em 09/11/2026: corrigir.

## What Changes

- A expansão pelo tesauro passa a incluir as variações ativas das palavras-chave ativas cuja forma normalizada é igual à do termo do grupo, lidas de `palavra_chave_variacoes_nome`. **BREAKING** (metodológico): a mesma configuração de grupos pode dar outro resultado na próxima execução.
- Uma falha na consulta da expansão deixa de ser engolida: a execução para com mensagem de erro e os resultados anteriores do projeto ficam como estavam. Hoje eles seriam apagados antes da falha.
- Os resultados já gravados não mudam sozinhos. Reclassificar continua sob demanda, pelo botão "Executar Classificação" do dono, porque reexecutar apaga as movimentações manuais.
- A tela da classificação avisa quando os resultados do projeto foram calculados antes da correção e há grupo com o tesauro ligado, e informa quantas movimentações manuais seriam perdidas ao reexecutar.
- Novo comando de leitura `app:classification:compare-expansion` que, sem gravar nada, calcula a classificação de um projeto com a expansão antiga e com a nova e mostra, por grupo, quantos documentos entram e saem e quais variações causaram a diferença. Ele serve à comparação com um projeto real exigida para mudanças de método.
- A ajuda contextual da classificação (`config/help/classification.yaml`) é atualizada: expansão, termo correspondido, aviso de resultado antigo e reexecução.

## Capabilities

### New Capabilities
<!-- Nenhuma: tudo cabe na capacidade de classificação temática. -->

### Modified Capabilities
- `classificacao-tematica`: muda o requisito "Expansão pelo tesauro" (as variações entram e a falha não é engolida). Entram também os requisitos de aviso de resultado calculado antes da correção, de execução que preserva os resultados anteriores em caso de falha na expansão e de comparação da expansão sem gravar.

## Impact

- Código: `src/Service/Classification/ClassificationEngine.php`, com o cálculo separado da gravação e a expansão corrigida; `ClassificationController` e os templates `classification/index.html.twig` e `results.html.twig` para o aviso; um comando novo em `src/Command/`; `BibliometricProject`, com o campo da versão da expansão usada na última execução, e uma migration que só acrescenta coluna.
- Dados: só leitura de `palavra_chave_variacoes_nome` e `keyword`, que são tabelas globais e não mudam aqui. Os resultados de classificação (`document_classification`) de cada projeto só mudam quando o dono reexecuta.
- Projetos afetados: todos os que têm grupo com o tesauro ligado e cujos termos coincidem com uma palavra-chave que tem variações. O tamanho do efeito só se mede com os dados de produção (ver design.md).
- Ajuda: `config/help/classification.yaml`. O `HelpCatalogTest` cobre as chaves novas.
