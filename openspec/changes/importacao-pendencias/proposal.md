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
<!-- Nenhuma: são tarefas de conferência e decisão. Se a correção do escape nos leitores antigos for aprovada, ela vira uma mudança própria com delta da spec importacao-de-datasets. -->

## Impact

- Produção: só escrita com aprovação do Jonas em cada tarefa (importações no projeto 22, exclusão de datasets).
- Código: nenhum, exceto se o leitor do TXT do OpenAlex precisar de ajuste à amostra real (tarefa 3.2).
