Você está trabalhando no projeto Symfony BiblioMap.

Preciso reorganizar e consolidar toda a arquitetura de palavras-chave, agrupamentos, tesauro e associações com documentos.

Objetivo geral:
Criar uma estrutura clara, segura e sustentável para que o sistema trabalhe com palavras-chave importadas, normalização, tesauro, clusters/conceitos, fuzzy matching, revisão manual e relatórios bibliométricos sem perder o dado original importado.

Visão conceitual correta:
O sistema deve trabalhar com estas camadas:

1. Documento
   Representa o artigo, produção ou registro bibliográfico importado.

2. DocumentKeyword
   Representa a ocorrência de uma palavra-chave em um documento.
   Deve preservar o termo exatamente como veio da importação.

3. Keyword
   Representa a palavra-chave importada e limpa.
   Ela tem valor original, valor de exibição e valor normalizado.

4. ThesaurusConcept
   Representa o conceito oficial do tesauro, ou seja, o cluster/conceitual agrupador.

5. ThesaurusLabel
   Representa as variações, sinônimos, siglas, traduções, erros comuns e formas alternativas daquele conceito.

6. ThesaurusMatch
   Representa uma associação, sugestão ou correspondência entre uma Keyword importada e um ThesaurusConcept.

A relação ideal deve ser:

Document
→ DocumentKeyword
→ Keyword
→ ThesaurusConcept

Ou seja:
O documento continua associado à keyword importada.
A keyword importada é associada ao conceito oficial do tesauro.
As análises usam o conceito oficial quando existir.

Problema atual:
O sistema possui Keyword.keywordConcept, que aponta para outra Keyword como agrupamento interno. Essa estrutura ajuda, mas gera confusão porque também existe ThesaurusConcept, que deveria ser o agrupador oficial.

Decisão arquitetural:
Usar ThesaurusConcept como agrupador oficial das palavras-chave.
Manter Keyword.keywordConcept apenas como compatibilidade temporária, se necessário.
Criar uma ligação direta entre Keyword e ThesaurusConcept.

Criar em Keyword o campo:

* thesaurusConcept

No banco:

* keyword.thesaurus_concept_id

Esse campo deve apontar para thesaurus_concept.id.

Regras:

* keyword.thesaurus_concept_id será o agrupador oficial.
* keyword.keyword_concept_id poderá continuar existindo apenas para compatibilidade temporária.
* Novas funcionalidades devem usar thesaurus_concept_id.
* Relatórios, redes, mapas e filtros devem priorizar thesaurusConcept.
* keywordConcept deve ser usado somente como fallback enquanto houver código legado.

Regra de resolução da palavra-chave efetiva:

1. Se Keyword tiver thesaurusConcept:
   usar thesaurusConcept.preferredLabel.
2. Senão, se Keyword tiver keywordConcept:
   usar keywordConcept.keywordDisplay.
3. Senão:
   usar keyword.keywordDisplay.
4. Se keywordDisplay estiver vazio:
   usar keyword.keywordOriginal.

Pseudo-código:
$effectiveLabel = null;

if ($keyword->getThesaurusConcept()) {
$effectiveLabel = $keyword->getThesaurusConcept()->getPreferredLabel();
} elseif ($keyword->getKeywordConcept()) {
$effectiveLabel = $keyword->getKeywordConcept()->getKeywordDisplay() ?: $keyword->getKeywordConcept()->getKeywordOriginal();
} else {
$effectiveLabel = $keyword->getKeywordDisplay() ?: $keyword->getKeywordOriginal();
}

Essa regra deve ser centralizada em um serviço para evitar duplicação.

Criar serviço:
App\Service\Keyword\KeywordResolverService

Métodos sugeridos:

* getEffectiveKeywordLabel(Keyword $keyword): string
* getEffectiveKeywordId(Keyword $keyword): string
* getEffectiveKeywordKey(Keyword $keyword): string
* getEffectiveConcept(?Keyword $keyword): ?ThesaurusConcept
* getDisplayLabelForReports(Keyword $keyword): string

O método getEffectiveKeywordKey deve retornar uma chave única para agregação:

* se houver thesaurusConcept: "thesaurus:" . id
* se houver keywordConcept: "keyword:" . id
* senão: "keyword:" . keyword.id

Isso evita misturar conceitos diferentes com o mesmo nome.

Estado atual a respeitar:
A entidade Keyword já possui:

* keywordOriginal
* keywordDisplay
* keywordNormalized
* keywordType
* status
* reviewReasons
* keywordConcept

A entidade DocumentKeyword já possui:

* document
* keyword
* originalTerm

Nunca apagar:

* keyword.keywordOriginal
* document_keyword.original_term

Esses campos são auditoria da importação.

Parte 1 — Ajuste do banco de dados

Criar migration adicionando:
keyword.thesaurus_concept_id nullable

Relacionamento:
Keyword ManyToOne ThesaurusConcept
onDelete SET NULL

Adicionar índice:
IDX_keyword_thesaurus_concept_id

Índices recomendados:

* keyword.keyword_normalized
* keyword.keyword_type
* keyword.keyword_concept_id
* keyword.thesaurus_concept_id
* document_keyword.keyword_id
* thesaurus_label.normalized_label
* thesaurus_concept.normalized_label
* thesaurus_scheme.slug

Atualizar entidade Keyword:
Adicionar propriedade:
private ?ThesaurusConcept $thesaurusConcept = null;

Adicionar getter/setter:
getThesaurusConcept(): ?ThesaurusConcept
setThesaurusConcept(?ThesaurusConcept $concept): static

Não remover keywordConcept agora.
Marcar internamente como legado/compatibilidade.

Parte 2 — Normalização de palavras-chave

Usar o TextNormalizer existente como base, mas garantir que ele seja aplicado em todo fluxo de importação e tratamento.

Regras de normalização:

1. Nunca alterar keywordOriginal.
2. Gerar keywordDisplay limpo.
3. Gerar keywordNormalized para comparação.
4. Remover tags HTML.
5. Decodificar entidades HTML.
6. Converter espaços Unicode invisíveis para espaço normal.
7. Remover espaços duplicados.
8. Remover sujeira nas bordas:

   * aspas;
   * vírgulas;
   * ponto solto;
   * underline;
   * hífen solto;
   * travessão;
   * parênteses soltos;
   * colchetes soltos;
   * chaves soltas;
   * barras soltas;
   * sinal de mais solto.
9. Não remover pontuação interna importante.
10. Rejeitar termos que sejam apenas referência numérica:

* [67]
* (12)
* 2020
* 67

11. Marcar para revisão termos suspeitos:

* parecem autores;
* parecem instituições;
* são curtos demais;
* contêm muitos números;
* viram vazio após limpeza;
* têm caracteres estranhos.

Exemplos:
" Artificial intelligence"
→ display: Artificial intelligence
→ normalized: artificial intelligence

"_Summer Camp"
→ display: Summer Camp
→ normalized: summer camp

"'Art Beyond Mechanical Reproduction'"
→ display: Art Beyond Mechanical Reproduction
→ normalized: art beyond mechanical reproduction

"[67]"
→ inválido/rejeitado

"]+ catalyst"
→ display: catalyst
→ normalized: catalyst

Parte 3 — Tesauro como clusters/conceitos

O tesauro de palavras-chave deve usar:

ThesaurusScheme:

* slug: keyword
* type: keyword
* name: Tesauro de Palavras-chave

ThesaurusConcept:
É o cluster/conceito oficial.

Exemplo:

* Artificial Intelligence
* Machine Learning
* Deep Learning
* Precision Agriculture

ThesaurusLabel:
São as variações dentro de cada conceito.

Exemplo:
Concept: Artificial Intelligence
Labels:

* Artificial Intelligence, preferred, en
* AI, alternative, en
* A.I., alternative, en
* Inteligência Artificial, alternative, pt
* Artificial inteligence, alternative, en
* Artifical Intelligence, alternative, en

O importador de CSV do tesauro deve continuar aceitando:
Scheme;Concept;Label;Type;Language

Exemplo:
keyword;Artificial Intelligence;Artificial Intelligence;preferred;en
keyword;Artificial Intelligence;AI;alternative;en
keyword;Artificial Intelligence;A.I.;alternative;en
keyword;Artificial Intelligence;Inteligência Artificial;alternative;pt
keyword;Machine Learning;Machine Learning;preferred;en
keyword;Machine Learning;ML;alternative;en
keyword;Machine Learning;Aprendizado de Máquina;alternative;pt

Melhorar o importador de tesauro:

* Normalizar Concept usando TextNormalizer.
* Normalizar Label usando TextNormalizer.
* Evitar duplicidade de concepts pelo normalizedLabel.
* Evitar duplicidade de labels pelo normalizedLabel dentro do mesmo concept.
* Garantir que todo concept tenha um label preferred.
* Se o CSV trouxer um concept sem label preferred, criar automaticamente um label preferred igual ao Concept.
* Aceitar UTF-8 com BOM e sem BOM.
* Aceitar separador ponto e vírgula.
* Registrar erros de linhas inválidas.
* Gerar relatório de importação.

Parte 4 — Associação de Keyword com ThesaurusConcept

Criar serviço:
App\Service\Keyword\KeywordThesaurusMatcherService

Responsabilidades:
Associar keywords importadas a concepts do tesauro.

Fluxo:

1. Receber uma Keyword.
2. Garantir que keywordDisplay e keywordNormalized estejam preenchidos.
3. Procurar ThesaurusLabel no scheme keyword com normalizedLabel igual a keyword.keywordNormalized.
4. Se encontrar, associar Keyword.thesaurusConcept ao ThesaurusConcept correspondente.
5. Registrar ThesaurusMatch com status automatic, confidence 100 e método exact_label.
6. Se não encontrar label exato, procurar ThesaurusConcept.normalizedLabel igual.
7. Se encontrar, associar.
8. Se não encontrar, aplicar fuzzy matching.
9. Se fuzzy for alto, associar ou sugerir conforme regras.
10. Se não encontrar nada, opcionalmente criar novo ThesaurusConcept ou deixar como pendente conforme configuração.

Parâmetros configuráveis:

* autoCreateConcepts: bool
* dryRun: bool
* minAutoScore: default 95
* minReviewScore: default 75
* ignoreShortTermsBelow: default 4

Regras:

* Não usar fuzzy automático para termos com menos de 4 caracteres.
* Não usar fuzzy automático para siglas.
* Siglas devem ser tratadas por ThesaurusLabel manual.
* Não mesclar semanticamente conceitos apenas por semelhança parcial.
* Não juntar "deep learning" com "machine learning" automaticamente.
* Não juntar "land use" com "land" automaticamente.
* Não juntar termos que parecem autores/instituições sem revisão.

Parte 5 — Fuzzy matching

Criar serviço:
App\Service\Keyword\KeywordFuzzyMatcherService

Objetivo:
Sugerir correspondências entre Keyword e ThesaurusConcept/ThesaurusLabel quando não houver match exato.

Estratégia:

* Comparar keyword.keywordNormalized contra thesaurus_label.normalizedLabel.
* Comparar também contra thesaurus_concept.normalizedLabel.
* Usar score de similaridade.

Se não houver biblioteca externa:

* implementar com similar_text;
* ou levenshtein normalizado;
* ou Symfony String.
  Deixar código preparado para futura troca por biblioteca mais robusta.

Thresholds:

* 100: associação automática.
* > = 95: associação automática se não houver ambiguidade.
* 88 a 94: revisão manual.
* 75 a 87: sugestão fraca para revisão.
* abaixo de 75: ignorar.

Ambiguidade:
Se houver dois candidatos com diferença menor que 3 pontos, não associar automaticamente.
Enviar para revisão.

Exemplos:
Artificial inteligence → Artificial Intelligence: fuzzy alto.
Artifical Intelligence → Artificial Intelligence: fuzzy alto.
machne learning → Machine Learning: fuzzy alto.
AI → Artificial Intelligence: não fuzzy automático; usar tesauro.
A.I. → Artificial Intelligence: não fuzzy automático; usar tesauro.
IoT → Internet of Things: não fuzzy automático; usar tesauro.

Parte 6 — Revisão manual do super admin

Criar área exclusiva para ROLE_SUPER_ADMIN.

Rota principal:
/admin/super/keywords

Subrotas sugeridas:
/admin/super/keywords/dashboard
/admin/super/keywords/treatment
/admin/super/keywords/review
/admin/super/keywords/report/{jobId}
/admin/super/keywords/export/{jobId}
/admin/super/keywords/import-thesaurus

Controller:
App\Controller\SuperAdmin\KeywordTreatmentController

Adicionar:
#[IsGranted('ROLE_SUPER_ADMIN')]

Tela 1 — Dashboard
Exibir:

* total de keywords;
* total de document_keyword;
* total de documentos com keyword;
* total de keywords sem display;
* total de keywords sem normalized;
* total de keywords sem thesaurusConcept;
* total associadas a thesaurusConcept;
* total com keywordConcept legado;
* total suspeitas;
* total inválidas;
* total por tipo:

  * author_keyword
  * indexed_keyword
  * mesh
* total de concepts no scheme keyword;
* total de labels no scheme keyword;
* sugestões pendentes.

Tela 2 — Tratamento automático
Botões:

* Rodar diagnóstico.
* Simular tratamento.
* Executar tratamento.
* Gerar relatório.
* Exportar CSV.
* Ir para revisão.

Antes de executar de verdade, exigir confirmação:
“Esta ação atualizará keywordDisplay, keywordNormalized e associações com o tesauro, preservando keywordOriginal e DocumentKeyword.originalTerm. Deseja continuar?”

Tela 3 — Revisão manual
Listar ThesaurusMatch ou tabela própria de sugestões com:

* Keyword ID;
* keywordOriginal;
* keywordDisplay;
* keywordNormalized;
* keywordType;
* conceito sugerido;
* label que gerou match;
* score;
* método;
* motivo;
* quantidade de documentos associados;
* status.

Ações:

* aceitar sugestão;
* rejeitar sugestão;
* associar a outro conceito;
* criar novo conceito;
* editar display;
* editar normalized;
* marcar como inválida;
* manter separada;
* adicionar como label alternativa a um conceito existente.

Quando aceitar:

* setar keyword.thesaurusConcept.
* atualizar status do match para reviewed/accepted.
* registrar log.

Quando rejeitar:

* não alterar keyword.
* atualizar status do match para rejected.
* registrar log.

Parte 7 — Processo de tratamento

Criar serviço:
App\Service\Keyword\KeywordTreatmentService

Método principal:
run(KeywordTreatmentOptions $options): KeywordTreatmentResult

Options:

* dryRun: bool
* limit: ?int
* batchSize: int = 500
* minAutoScore: int = 95
* minReviewScore: int = 75
* autoCreateConcepts: bool = false
* processInvalids: bool = true
* processExact: bool = true
* processThesaurus: bool = true
* processFuzzy: bool = true

Etapas:

1. Criar KeywordTreatmentJob.
2. Buscar keywords em lote.
3. Limpar display/normalized.
4. Marcar inválidas/suspeitas.
5. Associar por label exato do tesauro.
6. Associar por concept exato.
7. Aplicar fuzzy.
8. Criar sugestões pendentes.
9. Atualizar logs.
10. Gerar relatório final.
11. Finalizar job.

Modo dry-run:

* Não gravar alterações definitivas em Keyword.
* Pode gravar um job de simulação e logs de simulação.
* Ou retornar relatório em memória.
* A interface deve deixar claro que nada foi alterado.

Modo execute:

* Gravar alterações.
* Registrar logs.
* Gerar relatório persistente.

Parte 8 — Logs e relatórios

Criar entidade:
KeywordTreatmentJob

Campos:

* id
* startedAt
* finishedAt
* status: pending, running, completed, failed
* mode: dry_run, execute
* startedBy
* totalKeywords
* totalDocumentKeywords
* cleanedCount
* invalidCount
* suspiciousCount
* exactMatchedCount
* thesaurusMatchedCount
* fuzzyAutoMatchedCount
* fuzzyReviewCount
* createdConceptCount
* skippedCount
* errorCount
* affectedDocumentKeywordCount
* affectedDocumentCount
* reportPath
* createdAt
* updatedAt

Criar entidade:
KeywordTreatmentLog

Campos:

* id
* job
* keyword
* action
* oldDisplay
* newDisplay
* oldNormalized
* newNormalized
* oldThesaurusConcept
* newThesaurusConcept
* oldKeywordConcept
* newKeywordConcept
* matchMethod
* score
* reason
* status
* createdAt

Ações possíveis:

* cleaned
* invalid
* suspicious
* exact_label_matched
* exact_concept_matched
* fuzzy_auto_matched
* fuzzy_review_required
* concept_created
* skipped
* error
* manual_accepted
* manual_rejected
* manual_changed_concept
* manual_created_concept
* manual_marked_invalid

Relatório final:
Gerar HTML e CSV.

O relatório deve conter:

1. Resumo geral.
2. Total de keywords processadas.
3. Total de documentos impactados.
4. Total de associações DocumentKeyword impactadas indiretamente.
5. Quantidade limpa.
6. Quantidade inválida.
7. Quantidade suspeita.
8. Quantidade associada por match exato.
9. Quantidade associada por tesauro.
10. Quantidade associada por fuzzy automático.
11. Quantidade enviada para revisão.
12. Quantidade ignorada.
13. Erros.

Seções:

* Top 100 conceitos com mais variações agrupadas.
* Top 100 keywords mais frequentes sem conceito.
* Keywords inválidas.
* Sugestões fuzzy pendentes.
* Associações automáticas realizadas.
* Labels de tesauro usadas.
* Erros de processamento.
* Antes/depois dos principais agrupamentos.

Exportações:

* relatório completo CSV;
* termos inválidos CSV;
* sugestões pendentes CSV;
* agrupamentos aplicados CSV;
* keywords sem conceito CSV;
* concepts com labels CSV.

Parte 9 — Atualizar relatórios, redes e análises

Revisar todos os pontos do sistema que agregam por keyword.

Toda agregação precisa usar KeywordResolverService.

Buscar no projeto serviços/controllers relacionados a:

* ReportService
* IndicatorService
* ThreeFieldsService
* NetworkService
* ThematicEvolutionService
* ThematicMapService
* ReportController
* ThematicEvolutionController
* consultas topKeywords
* filtros por keyword
* exportações CSV/PDF
* mapas temáticos
* redes de coocorrência

Substituir lógica antiga:
keyword.term
keyword.keywordDisplay
keyword.keywordNormalized
keyword.id

Por lógica efetiva:
KeywordResolverService::getEffectiveKeywordKey()
KeywordResolverService::getEffectiveKeywordLabel()

A contagem deve agrupar pelo conceito oficial.

Exemplo:
Document 1 → AI
Document 2 → Artificial Intelligence
Document 3 → Artificial inteligence

Se todos estiverem associados ao ThesaurusConcept Artificial Intelligence:
Relatórios devem mostrar:
Artificial Intelligence = 3

E não:
AI = 1
Artificial Intelligence = 1
Artificial inteligence = 1

Parte 10 — Compatibilidade com keywordConcept legado

Enquanto keyword.keyword_concept_id existir:

* Não remover.
* Não quebrar telas atuais.
* Usar como fallback.
* Criar comando para migrar associações antigas para thesaurusConcept, quando possível.

Criar comando:
php bin/console app:keywords:migrate-keyword-concepts-to-thesaurus

Esse comando deve:

1. Buscar keywords com keywordConcept e sem thesaurusConcept.
2. Criar ou localizar ThesaurusConcept correspondente à keyword canônica.
3. Criar ThesaurusLabel para variações.
4. Associar keyword.thesaurusConcept.
5. Gerar relatório.

Exemplo:
Keyword A: Artificial Intelligence
Keyword B: AI → keywordConcept A
Keyword C: Artificial inteligence → keywordConcept A

Criar:
ThesaurusConcept: Artificial Intelligence
Labels:

* Artificial Intelligence
* AI
* Artificial inteligence

Associar A, B e C ao ThesaurusConcept.

Parte 11 — CLI

Criar comandos Symfony:

1. Diagnóstico:
   php bin/console app:keywords:diagnose

2. Tratamento:
   php bin/console app:keywords:treat --dry-run
   php bin/console app:keywords:treat --execute
   php bin/console app:keywords:treat --limit=1000
   php bin/console app:keywords:treat --batch-size=500
   php bin/console app:keywords:treat --min-auto-score=95
   php bin/console app:keywords:treat --min-review-score=75
   php bin/console app:keywords:treat --auto-create-concepts

3. Migração legado:
   php bin/console app:keywords:migrate-keyword-concepts-to-thesaurus --dry-run
   php bin/console app:keywords:migrate-keyword-concepts-to-thesaurus --execute

4. Exportar relatório:
   php bin/console app:keywords:export-treatment-report {jobId}

Parte 12 — Importação CSV do tesauro

Manter e melhorar o CSV:
Scheme;Concept;Label;Type;Language

Regras:

* Scheme obrigatório.
* Concept obrigatório.
* Label obrigatório.
* Type default: alternative, exceto quando Label igual Concept, nesse caso preferred.
* Language default: und ou en.
* Scheme keyword deve ser usado para palavras-chave.
* Normalizar Concept e Label com TextNormalizer.
* Criar scheme se não existir.
* Criar concept se não existir.
* Criar label se não existir.
* Não duplicar labels.
* Gerar relatório de linhas importadas, ignoradas e com erro.

Após importar CSV:
Oferecer botão:
“Aplicar tesauro às keywords existentes”

Essa ação deve rodar KeywordThesaurusMatcherService para associar keywords ao tesauro.

Parte 13 — Estados e status

Para Keyword:

* status true: ativa.
* status false: inválida ou desativada.
* reviewReasons: motivos separados por vírgula ou JSON.

Sugestão futura:
Criar campos mais claros:

* reviewStatus: ok, needs_review, invalid, ignored
* reviewReasons: JSON

Mas não fazer isso agora se gerar refatoração excessiva.

Para ThesaurusMatch:
Usar status:

* automatic
* pending
* reviewed
* accepted
* rejected

Usar confidence:

* 100 para match exato.
* score fuzzy para fuzzy.
* null quando manual.

Parte 14 — Segurança

Todas as telas de tratamento devem exigir:
ROLE_SUPER_ADMIN

Não permitir que ROLE_ADMIN comum execute tratamento em massa.

Adicionar proteção CSRF nos formulários de execução, aceite e rejeição.

Antes de executar ações em massa:

* exigir confirmação.
* exibir aviso de impacto.
* recomendar backup do banco.

Parte 15 — Performance

Processar em lotes:

* 500 por padrão.

Evitar:

* carregar todos os documentos em memória;
* fazer flush a cada item;
* buscar labels do tesauro repetidamente.

Otimizações:

* carregar labels do scheme keyword em mapa por normalizedLabel;
* carregar concepts em mapa por normalizedLabel;
* usar índices;
* usar queries agregadas para contar documentos por keyword;
* flush/clear a cada lote.

Cuidado:
Se usar EntityManager clear, preservar referências necessárias ou recarregar mapas.

Parte 16 — Testes

Criar testes unitários e funcionais.

Testes de normalização:

1. " Artificial intelligence" → Artificial intelligence / artificial intelligence
2. "_Summer Camp" → Summer Camp / summer camp
3. "'Art Beyond Mechanical Reproduction'" → Art Beyond Mechanical Reproduction / art beyond mechanical reproduction
4. "[67]" → inválido
5. "]+ catalyst" → catalyst / catalyst
6. "<p>Lulu Zhao</p>" → Lulu Zhao, mas pode ser suspeito como autor

Testes de tesauro:

1. AI deve associar a Artificial Intelligence se existir ThesaurusLabel AI.
2. A.I. deve associar se existir label.
3. Inteligência Artificial deve associar se existir label.
4. Label duplicado não deve ser criado duas vezes.
5. Concept duplicado por normalizedLabel não deve ser criado duas vezes.

Testes de fuzzy:

1. Artificial inteligence → Artificial Intelligence com score alto.
2. Artifical Intelligence → Artificial Intelligence com score alto.
3. machne learning → Machine Learning com score alto.
4. AI não deve ser fuzzy automático.
5. deep learning não deve ser mesclado automaticamente com machine learning.
6. Quando houver candidatos ambíguos, enviar para revisão.

Testes de associação com documentos:

1. DocumentKeyword.originalTerm deve permanecer igual.
2. Keyword.keywordOriginal deve permanecer igual.
3. Relatório deve agregar por ThesaurusConcept quando existir.
4. Relatório deve usar Keyword como fallback quando não existir conceito.

Parte 17 — Critério de aceite

A implementação será considerada correta quando:

1. O banco tiver relação direta keyword.thesaurus_concept_id.
2. O sistema preservar keywordOriginal e DocumentKeyword.originalTerm.
3. O tesauro de palavras-chave funcionar como cluster oficial.
4. As variações ficarem em ThesaurusLabel.
5. Keywords importadas forem associadas a ThesaurusConcept por:

   * match exato;
   * labels do tesauro;
   * fuzzy controlado;
   * revisão manual.
6. Relatórios e redes agregarem por conceito oficial.
7. O super admin tiver tela para diagnóstico, simulação, execução, revisão e relatório.
8. O processo gerar relatório final claro.
9. O CSV de tesauro continuar funcionando.
10. O sistema não mesclar termos curtos, siglas ou termos ambíguos automaticamente.
11. Toda alteração em massa tiver log.
12. Houver comandos CLI para diagnóstico, tratamento e migração.
13. Houver testes cobrindo normalização, tesauro, fuzzy e preservação de dados originais.

Resumo final da arquitetura desejada:

document
→ document_keyword.original_term
→ keyword.keyword_original / keyword_display / keyword_normalized
→ keyword.thesaurus_concept_id
→ thesaurus_concept.preferred_label
→ thesaurus_label variações/sinônimos

As análises devem usar:
thesaurus_concept.preferred_label

A auditoria deve preservar:
document_keyword.original_term
keyword.keyword_original
