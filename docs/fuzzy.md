Você está trabalhando no projeto Symfony BiblioMap.

Objetivo:
Criar uma funcionalidade administrativa exclusiva para SUPER ADMIN tratar, revisar, normalizar, agrupar e corrigir palavras-chave importadas no sistema, preservando os dados originais e gerando um relatório final completo do processo.

Contexto do sistema:
O projeto já possui a entidade Keyword com campos como:

* keywordOriginal
* keywordDisplay
* keywordNormalized
* keywordType
* status
* reviewReasons
* keywordConcept

Também existe DocumentKeyword, que associa documentos a keywords e mantém o originalTerm. Portanto, nunca apagar ou sobrescrever o termo original importado do documento.

O sistema também possui TextNormalizer para limpar termos importados, remover HTML, espaços Unicode, sujeiras nas bordas, acentos e gerar valores normalizados para comparação.

A funcionalidade deve ser criada para o perfil ROLE_SUPER_ADMIN.

Nome sugerido da funcionalidade:
Tratamento de Palavras-chave

Rota principal sugerida:
/admin/super/keywords/treatment

Menu:
Adicionar no menu administrativo uma entrada visível apenas para ROLE_SUPER_ADMIN:
“Tratamento de Palavras-chave”

Objetivo funcional:
Permitir que o super admin execute um processo em etapas para:

1. Identificar palavras-chave sujas.
2. Limpar keywordDisplay e keywordNormalized.
3. Identificar termos inválidos.
4. Agrupar palavras-chave duplicadas.
5. Sugerir agrupamentos por fuzzy matching.
6. Associar keywords a conceitos existentes.
7. Criar novos conceitos quando necessário.
8. Atualizar as análises para usarem keywordConcept.
9. Gerar relatório final com tudo que foi alterado, sugerido, ignorado ou enviado para revisão.

Importante:
O processo deve ter modo de simulação antes de aplicar alterações.

Criar dois modos:

1. Dry-run / Simulação:

   * Não grava alterações.
   * Mostra o que seria feito.
   * Gera relatório prévio.

2. Executar correção:

   * Aplica alterações aprovadas.
   * Atualiza registros.
   * Gera relatório final.

Regras obrigatórias:

* Nunca apagar keywordOriginal.
* Nunca apagar DocumentKeyword.originalTerm.
* Nunca alterar o texto original importado.
* Toda alteração deve ser registrada em log.
* Toda ação automática deve ter motivo e score, quando aplicável.
* Toda decisão duvidosa deve ir para revisão manual.
* Termos curtos, ambíguos ou que pareçam autores/instituições não devem ser mesclados automaticamente.
* A análise bibliométrica deve usar keywordConcept quando existir; caso contrário, usar a própria Keyword.

Regra de uso nas análises:
effectiveKeyword = keyword.keywordConcept ?? keyword

Aplicar essa lógica em:

* contagem de palavras-chave;
* rede de coocorrência;
* mapa temático;
* evolução temática;
* filtros por palavra-chave;
* exportações;
* relatórios.

Criar um serviço principal:
App\Service\KeywordTreatment\KeywordTreatmentService

Responsabilidades:

* Executar o processo completo.
* Receber parâmetros de configuração.
* Rodar em dry-run ou modo real.
* Retornar um objeto/DTO com estatísticas e detalhes do processamento.

Criar também serviços auxiliares, se necessário:

* KeywordCleanerService
* KeywordFuzzyMatcherService
* KeywordConceptResolverService
* KeywordTreatmentReportService
* KeywordTreatmentLoggerService

Etapas do processo:

ETAPA 1 — Diagnóstico
Buscar todas as keywords cadastradas e calcular:

* total de keywords;
* total de keywords sem display;
* total de keywords sem normalized;
* total de keywords com sujeira;
* total de keywords inválidas;
* total de keywords duplicadas por keywordNormalized;
* total de keywords sem keywordConcept;
* total de keywords já associadas a conceito;
* total por tipo: author_keyword, indexed_keyword, mesh.

Detectar sujeiras como:

* tags HTML;
* aspas nas bordas;
* espaços Unicode invisíveis;
* underscores no início;
* hífens soltos no início;
* parênteses soltos;
* colchetes soltos;
* valores numéricos isolados;
* referências como [67], (12), 2020;
* termos vazios após limpeza;
* termos com caracteres estranhos nas bordas;
* palavras-chave que parecem nomes de autores;
* palavras-chave que parecem instituições.

Exemplos:

<p>Lulu Zhao</p> → parece autor ou sujeira HTML.
 Artificial intelligence → limpar espaço Unicode.
_Summer Camp → limpar underscore.
'Art Beyond Mechanical Reproduction' → remover aspas externas.
[67] → inválida/referência numérica.
]+ catalyst → catalyst.

ETAPA 2 — Limpeza
Para cada Keyword:

* usar TextNormalizer::normalizeKeyword;
* gerar display limpo;
* gerar normalized;
* se o termo for válido, atualizar keywordDisplay e keywordNormalized;
* se o termo for inválido, marcar status false ou reviewReasons;
* se o termo precisar revisão, preencher reviewReasons.

Não alterar keywordOriginal.

Exemplo:
keywordOriginal:  Artificial intelligence
keywordDisplay: Artificial intelligence
keywordNormalized: artificial intelligence

ETAPA 3 — Agrupamento exato
Agrupar automaticamente keywords com o mesmo keywordNormalized.

Regra:

* escolher uma keyword principal/conceito canônico;
* preferir a que já tem keywordConcept;
* se nenhuma tiver conceito, escolher a mais frequente em DocumentKeyword;
* se empate, escolher a com melhor keywordDisplay;
* vincular as demais keywords ao conceito principal via keywordConcept.

Exemplo:
Artificial intelligence
-Artificial Intelligence
'artificial intelligence'
Artificial intelligence.

Todas devem apontar para o mesmo conceito/canônica:
Artificial Intelligence

Não apagar as keywords duplicadas.
Apenas vincular keywordConcept.

ETAPA 4 — Tesauro
Usar ThesaurusConcept e ThesaurusLabel do scheme keyword.

Fluxo:

1. Procurar correspondência exata entre keywordNormalized e normalizedLabel do ThesaurusLabel.
2. Se encontrar, associar Keyword ao conceito correspondente.
3. Se não encontrar, seguir para fuzzy.

A associação pode ser feita de duas formas, conforme a estrutura atual do projeto:
A) se o projeto usa Keyword.keywordConcept como conceito interno, criar ou localizar uma Keyword canônica correspondente ao ThesaurusConcept e apontar keywordConcept para ela;
B) se o projeto já tiver associação direta com ThesaurusConcept, usar essa associação.

Escolher a abordagem que melhor se encaixa na estrutura existente sem quebrar o sistema.

ETAPA 5 — Fuzzy matching
Aplicar fuzzy matching apenas depois da limpeza e depois da busca exata.

Objetivo do fuzzy:
Detectar variações pequenas, erros de digitação e diferenças de escrita.

Exemplos bons para fuzzy:

* Artificial inteligence → Artificial Intelligence
* Artifical Intelligence → Artificial Intelligence
* machne learning → Machine Learning
* deep-learning → Deep Learning
* agricultural robotcs → Agricultural Robotics

Não usar fuzzy para sinônimos sem texto parecido.
Exemplos que devem ser tratados por tesauro/manual:

* AI → Artificial Intelligence
* A.I. → Artificial Intelligence
* IoT → Internet of Things
* Smart Farming → Precision Agriculture

Configuração de thresholds:

* score 100: associação automática.
* score >= 95: associação automática se não houver ambiguidade.
* score entre 88 e 94: sugestão para revisão manual.
* score entre 75 e 87: sugestão fraca para revisão.
* score abaixo de 75: não associar.

Regras de segurança:

* não aplicar fuzzy automático em termos com menos de 4 caracteres;
* não aplicar fuzzy automático em siglas;
* não aplicar fuzzy automático quando houver dois candidatos com scores próximos;
* não aplicar fuzzy automático entre conceitos diferentes, mas semanticamente próximos, como:

  * deep learning
  * machine learning
  * reinforcement learning

Esses devem ir para revisão manual.

Se o projeto PHP não tiver biblioteca de fuzzy, implementar uma primeira versão com similar_text, levenshtein normalizado ou Symfony String. Se possível, preparar a estrutura para uso futuro de uma biblioteca mais robusta.

ETAPA 6 — Revisão manual
Criar uma tela de revisão para ROLE_SUPER_ADMIN.

Rota sugerida:
/admin/super/keywords/treatment/review

A tela deve listar sugestões pendentes com:

* keyword id;
* keywordOriginal;
* keywordDisplay;
* keywordNormalized;
* tipo;
* conceito sugerido;
* score fuzzy;
* motivo da sugestão;
* quantidade de documentos associados;
* ação recomendada.

Ações disponíveis:

* aceitar associação;
* rejeitar associação;
* editar display;
* editar normalized;
* escolher outro conceito;
* criar novo conceito;
* marcar como inválida;
* manter separada;
* mover para revisão posterior.

ETAPA 7 — Processamento das associações com documentos
Não alterar DocumentKeyword.originalTerm.

Apenas garantir que:

* DocumentKeyword continua apontando para a Keyword importada;
* Keyword aponta para keywordConcept quando houver agrupamento;
* relatórios usam effectiveKeyword.

Isso preserva a rastreabilidade:
Documento → termo original importado → keyword limpa → conceito agrupado.

ETAPA 8 — Relatório final
Ao final do processo, gerar relatório em HTML na tela e permitir exportar CSV.

Rota sugerida:
/admin/super/keywords/treatment/report/{jobId}

Criar entidade de job/log:
KeywordTreatmentJob
Campos:

* id
* startedAt
* finishedAt
* status: pending, running, completed, failed
* mode: dry_run, execute
* startedBy
* totalKeywords
* cleanedCount
* invalidCount
* exactGroupedCount
* thesaurusMatchedCount
* fuzzyAutoMatchedCount
* fuzzyReviewCount
* skippedCount
* errorCount
* reportPath
* createdAt

Criar entidade de item de log:
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
* oldConcept
* newConcept
* score
* reason
* status
* createdAt

Ações possíveis:

* cleaned
* invalid
* exact_grouped
* thesaurus_matched
* fuzzy_auto_matched
* fuzzy_review_required
* skipped
* error
* manual_approved
* manual_rejected

O relatório final deve mostrar:

1. Resumo geral:

   * total processado;
   * total limpo;
   * total inválido;
   * total agrupado por igualdade exata;
   * total associado por tesauro;
   * total associado automaticamente por fuzzy;
   * total enviado para revisão;
   * total ignorado;
   * total de erros.

2. Top 50 agrupamentos realizados:

   * conceito final;
   * variações agrupadas;
   * quantidade de documentos impactados.

3. Lista de termos inválidos:

   * original;
   * motivo;
   * ação tomada.

4. Lista de sugestões fuzzy:

   * termo;
   * conceito sugerido;
   * score;
   * status.

5. Impacto nos documentos:

   * quantidade de DocumentKeyword afetados indiretamente;
   * quantidade de documentos únicos impactados;
   * top documentos com mais keywords corrigidas.

6. Exportações:

   * CSV completo do relatório;
   * CSV de termos inválidos;
   * CSV de sugestões pendentes;
   * CSV de agrupamentos aplicados.

ETAPA 9 — Interface
Criar tela principal com cards:

* Diagnóstico atual
* Simular tratamento
* Executar tratamento
* Sugestões pendentes
* Últimos relatórios

Botões:

* “Rodar diagnóstico”
* “Simular tratamento”
* “Executar tratamento”
* “Ver revisão manual”
* “Baixar relatório CSV”
* “Baixar relatório HTML”

Antes de executar em modo real, exigir confirmação:
“Esta ação irá atualizar associações de palavras-chave e conceitos, preservando os termos originais. Deseja continuar?”

ETAPA 10 — Segurança
Apenas ROLE_SUPER_ADMIN pode acessar.
Usar #[IsGranted('ROLE_SUPER_ADMIN')] no controller.
Não permitir execução por usuários admin comuns.

ETAPA 11 — Performance
Processar em lotes.
Sugestão:

* batch de 500 keywords;
* flush/clear do Doctrine a cada lote;
* evitar carregar todos os documentos em memória;
* usar queries agregadas para contagem de DocumentKeyword;
* criar índices se necessário.

Índices recomendados:

* keyword.keyword_normalized
* keyword.keyword_concept_id
* keyword.keyword_type
* document_keyword.keyword_id

ETAPA 12 — Comando Symfony
Além da interface, criar comando CLI:

php bin/console app:keywords:treat --dry-run
php bin/console app:keywords:treat --execute
php bin/console app:keywords:treat --min-score=95
php bin/console app:keywords:treat --limit=1000

O comando deve gerar o mesmo relatório da interface.

ETAPA 13 — Testes
Criar testes unitários para:

* limpeza de keyword;
* rejeição de [67];
* limpeza de _Summer Camp;
* limpeza de espaços Unicode;
* agrupamento exato;
* fuzzy alto;
* fuzzy médio para revisão;
* não aplicar fuzzy em siglas curtas;
* preservar keywordOriginal;
* preservar DocumentKeyword.originalTerm.

Casos de teste:

1. " Artificial intelligence" → Artificial intelligence / artificial intelligence
2. "_Summer Camp" → Summer Camp / summer camp
3. "'Art Beyond Mechanical Reproduction'" → Art Beyond Mechanical Reproduction
4. "[67]" → inválido
5. "]+ catalyst" → catalyst
6. "Artificial inteligence" deve sugerir Artificial Intelligence com fuzzy alto
7. "AI" não deve ser fuzzy automático; deve depender de tesauro
8. "deep learning" não deve ser mesclado automaticamente com "machine learning"

Entregáveis esperados:

1. Controller SuperAdminKeywordTreatmentController.
2. Service KeywordTreatmentService.
3. Service KeywordFuzzyMatcherService.
4. Entidades KeywordTreatmentJob e KeywordTreatmentLog.
5. Migrations necessárias.
6. Templates Twig para:

   * index;
   * revisão;
   * relatório.
7. Comando CLI app:keywords:treat.
8. Exportação CSV do relatório.
9. Testes unitários.
10. Ajustes nos relatórios para usar effectiveKeyword.

Critério final de aceite:
Depois de executar a funcionalidade, o sistema deve conseguir transformar keywords sujas e duplicadas em conceitos agrupados, preservar os dados originais da importação, reduzir a poluição nos relatórios bibliométricos e entregar um relatório final claro com tudo que foi limpo, agrupado, sugerido, ignorado ou enviado para revisão.
