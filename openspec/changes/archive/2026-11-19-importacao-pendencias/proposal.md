# Proposal

## Why

As mudanças `importacao-base-novos-formatos`, `importacao-brapci`, `importacao-bdtd`, `importacao-lista-ebsco` e `importacao-openalex` foram implantadas em produção em 19/11/2026 e arquivadas a pedido do Jonas. Algumas tarefas delas dependem de uma decisão dele ou de um arquivo que só ele consegue exportar, e ficaram abertas. Esta mudança reúne essas tarefas para que não se percam no arquivo.

## What Changes

- Decidir e, se for o caso, importar em produção no projeto 22 os arquivos maiores que ficaram só na prévia (BDTD com 7.193 registros, RIS do OpenAlex com 9.540, CSV da EBSCO com várias bases).
- Decidir o destino dos datasets de teste criados no projeto 22.
- Fechar o leitor do TXT do OpenAlex com uma amostra real.
- Registrar as respostas sobre a exportação da EBSCO e ajustar o guia da LISTA.
- Avaliar a correção do escape por barra invertida nos leitores antigos de CSV (Scopus, Lens, PubMed).

## Capabilities

### New Capabilities
<!-- Nenhuma. -->

### Modified Capabilities
- `importacao-de-datasets`: novo requisito "CSV lido sem caractere de escape" (correção aprovada pelo Jonas em 19/11/2026).

## Impact

- Produção: só escrita com aprovação do Jonas em cada tarefa (importações no projeto 22, exclusão de datasets).
- Código: leitores de CSV do Scopus, do Lens e do PubMed sem caractere de escape; leitor do TXT do OpenAlex ajustado à amostra real (tipo em minúsculas e acesso aberto com inicial maiúscula, como no JSON e no CSV); guia, ajuda e limite da LISTA.
- Dados já gravados: em produção, 124 registros do CSV do Scopus de 20.000 e 3 do CSV do Lens de 50.000 foram lidos com colunas deslocadas antes da correção. A correção vale para importações novas; corrigir os documentos já gravados é decisão do Jonas (tarefa 5.2).
