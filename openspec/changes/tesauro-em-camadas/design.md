# Design

## Context

Proposta e motivação em proposal.md. Fonte técnica: análise de arquitetura de 12/11/2026 (Fable 5.2, somente leitura) com medições no banco local (projeto 13, 10.452 documentos, 98,5 mil vínculos de palavra-chave).

Decisões do Jonas (12/11/2026):
- Dados coletados são sagrados e nunca alterados; o tesauro é a camada de autoridade.
- Administradores criam o tesauro global (padrão); usuários criam o seu e ele vale só nos trabalhos deles, sobrescrevendo o global; usuários não editam o global.
- Excluir/Descartar de autor e palavra-chave: bloquear agora quando houver documentos; "inativar" de verdade vem com o id efetivo (fase 1).
- Projeto público: todos veem os números com o tesauro do dono.
- Fusões globais já feitas: administrador → global; usuário comum → só o tesauro dele. O banco não registra quem fez as fusões passadas: a regra vale daqui para frente (a fase 0 passa a registrar autoria) e o histórico fica para a fase 5.

- Dois vínculos do mesmo documento com a mesma identidade efetiva: manter as duas linhas, sem apagar, e contar o documento uma vez. Prioridade do Jonas: eficiência do sistema; os dados antigos são recalculados pela sincronia do projeto (`DocumentEnrichmentService`), que passa a recalcular o id efetivo de cada vínculo. Texto original já apagado por mesclagens passadas só volta por reimportação dos arquivos guardados em `var/uploads` (fase 5).
- Mesclagem entre tipos (autor ↔ indexada): aceito que o tipo de origem se mantenha e que os números desses casos mudem.
- Fase 1: seguir adiante e publicar na janela que o Jonas indicar.

## Decisions

1. **Id efetivo no vínculo** (`document_author.effective_author_id`, `document_keyword.effective_keyword_id`, `INT NOT NULL DEFAULT 0`, `0` = ainda não calculado; palavra-chave mantém a convenção de hoje, negativo = `-thesaurus_concept.id`), criado com `ALGORITHM=INSTANT` e preenchido em lotes. Escolhida sobre a junção na consulta e sobre o mapa efetivo por projeto: 1,5 a 3 vezes mais rápida que hoje, contra 2 a 2,7 vezes mais lenta da junção; custo de 50 a 70 MB em produção.
2. **`authority_mapping`** com `UNIQUE (entity_type, scope, scope_id, source_id)`; resolução projeto → usuário → global → bruto, um salto por camada, alvo final. O global de palavra-chave continua em `keyword.keyword_concept_id` e `thesaurus_concept_id` (mais de trinta pontos de escrita); o global de autor entra como linhas `scope='global'`.
3. **Diário** `authority_merge_log` e `authority_merge_log_link`, só inserção, gravados na mesma transação dos caminhos que ainda são destrutivos, até a fase 2 aposentá-los.
4. **Consistência**: o `DataVersionTracker` (já presente) marca gravação em `keyword`, `thesaurus_concept` e `authority_mapping`; o recálculo roda ao fim da requisição ou do comando; `app:authority:rebuild --check` roda no cron.
5. **Tipo da ocorrência** na mesclagem entre tipos: o tipo de origem se mantém; os números desses casos mudam (declarado na fase 2).
6. **Cache das análises**: a chave não muda enquanto todos veem o projeto com o tesauro do dono; cada mapeamento gravado sobe a versão global (versão por projeto fica para a fase 6).
7. **Instituições, países e revistas** não ganham exceção por projeto agora: já são derivados de `document` pelo enriquecimento; só deixam de ser apagados (fase 6).

## Fases 0 a 2 (detalhe em tasks.md)

- **Fase 0**: diário, bloqueio de Excluir/Descartar, nenhuma consulta muda. Os 49 casos do retrato saem idênticos.
- **Fase 1**: colunas efetivas e índices, preenchimento com o valor de hoje, relatórios lendo a coluna, conferência. Importação, cópia, fusão de documentos e enriquecimento passam a manter a coluna.
- **Fase 2**: mesclagem administrativa, tratamento noturno e enriquecimento gravam mapeamento e recalculam, sem apagar nem trocar vínculos; "desfazer" no administrador; `GroupComparisonService` e `ClassificationEngine` leem a palavra efetiva.

Fases 3 a 6 estão na tabela do proposal.md e ganham detalhe depois de publicadas as fases 0 a 2.

## Risks / Trade-offs

- Migração em tabela de 1,5 milhão de linhas e disco de produção já cheio uma vez: backup antes, disco conferido, preenchimento em lotes fora do horário de uso.
- Valor efetivo velho: mitigado pelo recálculo no fim da gravação e pela conferência no cron.
- A correção do cron (`cron-do-painel-corrigido`) não pode ir ao ar antes da fase 2, senão o tratamento noturno volta a fundir de forma destrutiva; a fase 0 só o registra no diário.
- Mapeamento global de autor sem evidência forte junta homônimos: restringir a ORCID, o resto por projeto.
