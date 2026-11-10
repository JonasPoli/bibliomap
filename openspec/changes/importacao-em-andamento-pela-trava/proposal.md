# Proposal

## Why

A mudança `cron-do-painel-corrigido` impede o tratamento de palavras-chave em modo Real enquanto algum dataset estiver com status `importing`, porque a importação guarda os IDs das palavras-chave em memória e uma fusão no meio faria o vínculo novo falhar. A regra olha só o status. Em 10/11/2026, o dataset #11 estava `importing` desde 02/06/2026: a importação morreu no meio (48.200 de 50.281 registros), não havia processo nem trava, e o status nunca saiu de `importing`. Com a regra atual, esse dataset bloquearia todo tratamento Real, pela tela e pela rotina da madrugada, para sempre. O comando também termina com código 0 nesse caso, então o bloqueio passaria despercebido. O status foi corrigido à mão para `error` (backup da linha e SQL de volta em `~/backups`).

Decisão do Jonas em 10/11/2026: considerar a importação "em andamento" só quando a trava da importação daquele dataset (`bibliomap_import_dataset_{id}`) estiver ocupada, para que uma importação interrompida não volte a travar o tratamento.

## What Changes

- No modo Real, o tratamento só considera uma importação em andamento quando um dataset com status `importing` tem a trava `bibliomap_import_dataset_{id}` ocupada por outra sessão do MySQL. A trava já é pega pelo comando de importação durante toda a importação de cada dataset.
- Um dataset com status `importing` e trava livre (importação interrompida) não bloqueia o tratamento. O comando e a tela avisam quais datasets estão nessa situação, pelo ID, para alguém corrigir o status. O tratamento não altera o status do dataset.
- A simulação continua sem verificar importação.
- Ajuda `admin.kwtreat.run` e README de `app:keywords:treat` atualizados.
- Sem migration e sem mudança na importação.

## Capabilities

### New Capabilities
<!-- Nenhuma. -->

### Modified Capabilities
- `tesauro-de-palavras-chave`: muda o requisito "Um tratamento por vez", que entra com `cron-do-painel-corrigido` (importação em andamento passa a depender da trava, e a importação interrompida gera aviso).
- `rotinas-agendadas`: muda o cenário "Importação em andamento à noite" do requisito "Tratamento noturno de palavras-chave", que também entra com `cron-do-painel-corrigido`.

As duas specs só existem depois de arquivar `cron-do-painel-corrigido`: esta mudança é arquivada depois dela.

## Impact

- Código: `src/Service/KeywordTreatment/KeywordTreatmentService.php` (verificação da importação), `src/Command/KeywordTreatmentCommand.php` e `src/Controller/Admin/SuperAdminKeywordTreatmentController.php` (aviso dos datasets interrompidos), `config/help/admin_general.yaml`, `README.md` e testes em `tests/Service/`.
- Dados: nenhum. A verificação só lê `dataset` e consulta travas (`IS_USED_LOCK`).
- Produção: deploy com backup antes e conferência depois. Nenhuma tabela global muda.
