<?php

namespace App\Service\KeywordTreatment;

use App\Entity\Dataset;
use App\Entity\Keyword;
use App\Entity\KeywordTreatmentJob;
use App\Entity\KeywordTreatmentLog;
use App\Entity\ThesaurusConcept;
use App\Entity\ThesaurusScheme;
use App\Entity\ThesaurusLabel;
use App\Service\Authority\AuthorityMappingService;
use App\Service\Authority\AuthorityMergeLogger;
use App\Service\Authority\AuthorityResolver;
use App\Service\Cache\AnalyticsCache;
use App\Service\Import\TextNormalizer;
use App\Service\Keyword\KeywordThesaurusMatcherService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use DateTimeImmutable;

class KeywordTreatmentService
{
    private TextNormalizer $normalizer;

    /** @var KeywordTreatmentLog[] logs persistidos no lote atual (ainda sem flush) */
    private array $batchLogs = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly KeywordFuzzyMatcherService $fuzzyMatcher,
        private readonly KeywordThesaurusMatcherService $thesaurusMatcher,
        private readonly ?AnalyticsCache $cache = null,
        private ?AuthorityMergeLogger $mergeLogger = null,
        private ?AuthorityResolver $authority = null,
        private ?AuthorityMappingService $mapping = null,
    ) {
        $this->normalizer = new TextNormalizer();
    }

    /** @var array<int,true> palavras-chave cujo conceito (ou grupo) mudou e cujos vínculos precisam de novo efetivo */
    private array $effectiveDirty = [];

    private function markEffective(int ...$keywordIds): void
    {
        foreach ($keywordIds as $id) {
            $this->effectiveDirty[$id] = true;
        }
    }

    /** Recalcula o efetivo dos vínculos das palavras marcadas; chamado depois do flush e antes do commit do lote. */
    private function refreshEffective(): void
    {
        if (!$this->effectiveDirty) {
            return;
        }
        $authority = $this->authority ??= new AuthorityResolver($this->em->getConnection());
        foreach (array_chunk(array_keys($this->effectiveDirty), 1000) as $chunk) {
            $authority->refreshLinks(AuthorityResolver::ENTITY_KEYWORD, 'dk.keyword_id IN (' . implode(',', array_map('intval', $chunk)) . ')');
        }
        $this->effectiveDirty = [];
    }

    private function mapping(): AuthorityMappingService
    {
        $this->authority ??= new AuthorityResolver($this->em->getConnection());

        return $this->mapping ??= new AuthorityMappingService($this->em, $this->authority, $this->mergeLogger());
    }

    /** O termo já está no mesmo agrupamento global do existente (conceito agrupador, mapeamento ou conceito do tesauro). */
    private function isAlreadyGrouped(int $keywordId, int $existingId): bool
    {
        $m = $this->mapping();

        return $m->resolveFinalTarget(AuthorityMappingService::ENTITY_KEYWORD, $keywordId, AuthorityMappingService::SCOPE_GLOBAL, 0)
            === $m->resolveFinalTarget(AuthorityMappingService::ENTITY_KEYWORD, $existingId, AuthorityMappingService::SCOPE_GLOBAL, 0);
    }

    private function mergeLogger(): AuthorityMergeLogger
    {
        return $this->mergeLogger ??= new AuthorityMergeLogger($this->em);
    }

    /**
     * Stage 1: Diagnóstico completo da base de dados.
     */
    public function getDiagnosis(): array
    {
        $conn = $this->em->getConnection();

        $total = (int)$conn->fetchOne('SELECT COUNT(*) FROM keyword');
        $totalDk = (int)$conn->fetchOne('SELECT COUNT(*) FROM document_keyword');
        $totalDocsWithKw = (int)$conn->fetchOne('SELECT COUNT(DISTINCT document_id) FROM document_keyword');
        $noDisplay = (int)$conn->fetchOne('SELECT COUNT(*) FROM keyword WHERE keyword_display IS NULL OR keyword_display = ""');
        $noNormalized = (int)$conn->fetchOne('SELECT COUNT(*) FROM keyword WHERE keyword_normalized = ""');

        // Sujas, inválidas e suspeitas: percorre todas as palavras (antes só as 10.000 primeiras, o que
        // aparecia na tela como se fosse o total). Custa alguns segundos em 90 mil palavras; o resultado
        // fica no cache por versão dos dados (global, projeto 0) e some quando a tabela muda.
        $scan = function () use ($conn): array {
            $dirtyCount = 0;
            $invalidCount = 0;
            $suspiciousCount = 0;
            foreach ($conn->executeQuery('SELECT keyword_display, keyword_original FROM keyword')->iterateAssociative() as $row) {
                $display = $row['keyword_display'] ?? $row['keyword_original'];
                $cleaned = $this->normalizer->cleanDisplayValue($display);
                if ($cleaned !== $display) {
                    $dirtyCount++;
                }
                $normRes = $this->normalizer->normalizeKeyword($display);
                if (!$normRes['valid']) {
                    $invalidCount++;
                }
                if (!empty($normRes['needs_review'])) {
                    $suspiciousCount++;
                }
            }

            return [$dirtyCount, $invalidCount, $suspiciousCount];
        };
        [$dirtyCount, $invalidCount, $suspiciousCount] = $this->cache
            ? $this->cache->get(0, 'kwtreat_diagnosis_scan', [], $scan)
            : $scan();

        $noThesaurusConcept = (int)$conn->fetchOne('SELECT COUNT(*) FROM keyword WHERE thesaurus_concept_id IS NULL');
        $hasThesaurusConcept = (int)$conn->fetchOne('SELECT COUNT(*) FROM keyword WHERE thesaurus_concept_id IS NOT NULL');
        $hasKeywordConcept = (int)$conn->fetchOne('SELECT COUNT(*) FROM keyword WHERE keyword_concept_id IS NOT NULL AND thesaurus_concept_id IS NULL');
        $inactive = (int)$conn->fetchOne('SELECT COUNT(*) FROM keyword WHERE status = 0');

        // Duplicates count
        $duplicatesCount = (int)$conn->fetchOne('
            SELECT COUNT(*) FROM (
                SELECT keyword_normalized FROM keyword 
                WHERE keyword_normalized != "" 
                GROUP BY keyword_normalized 
                HAVING COUNT(*) > 1
            ) t
        ');

        // Types
        $types = $conn->fetchAllAssociative('SELECT keyword_type, COUNT(*) AS cnt FROM keyword GROUP BY keyword_type');
        $typeCounts = [];
        foreach ($types as $t) {
            $typeCounts[$t['keyword_type']] = (int)$t['cnt'];
        }

        // Thesaurus stats
        $kwScheme = $conn->fetchAssociative("SELECT id FROM thesaurus_scheme WHERE slug = 'keyword' LIMIT 1");
        $thesaurusConceptsCount = 0;
        $thesaurusLabelsCount = 0;
        if ($kwScheme) {
            $thesaurusConceptsCount = (int)$conn->fetchOne('SELECT COUNT(*) FROM thesaurus_concept WHERE scheme_id = ?', [$kwScheme['id']]);
            $thesaurusLabelsCount = (int)$conn->fetchOne(
                'SELECT COUNT(*) FROM thesaurus_label tl JOIN thesaurus_concept tc ON tl.concept_id = tc.id WHERE tc.scheme_id = ?',
                [$kwScheme['id']]
            );
        }

        $pendingSuggestions = (int)$conn->fetchOne("SELECT COUNT(*) FROM thesaurus_match WHERE status = 'pending' AND entity_type = 'keyword'");

        return [
            'total' => $total,
            'totalDocumentKeywords' => $totalDk,
            'totalDocsWithKeyword' => $totalDocsWithKw,
            'noDisplay' => $noDisplay,
            'noNormalized' => $noNormalized,
            'dirtyCount' => $dirtyCount,
            'invalidCount' => $invalidCount,
            'suspiciousCount' => $suspiciousCount,
            'duplicatesCount' => $duplicatesCount,
            'noThesaurusConcept' => $noThesaurusConcept,
            'hasThesaurusConcept' => $hasThesaurusConcept,
            'hasKeywordConceptLegacy' => $hasKeywordConcept,
            'inactive' => $inactive,
            'types' => $typeCounts,
            'thesaurusConceptsCount' => $thesaurusConceptsCount,
            'thesaurusLabelsCount' => $thesaurusLabelsCount,
            'pendingSuggestions' => $pendingSuggestions,
        ];
    }

    /**
     * Executes the keyword treatment pipeline.
     */
    public const LOCK_NAME = 'bibliomap_keywords_treat';

    /**
     * Um tratamento por vez (tela e comando) e, no modo Real, nunca durante uma importação.
     *
     * @throws KeywordTreatmentBlockedException quando não pode começar (nada é gravado)
     */
    public function executeJob(KeywordTreatmentOptions $options, string $startedBy = 'system'): KeywordTreatmentJob
    {
        $conn = $this->em->getConnection();
        if ((int) $conn->fetchOne('SELECT GET_LOCK(?, 0)', [self::LOCK_NAME]) !== 1) {
            throw KeywordTreatmentBlockedException::anotherRunning();
        }

        try {
            // A importação guarda os IDs das palavras-chave em memória: uma fusão no meio
            // faria o vínculo novo falhar. A simulação não altera palavras-chave e pode rodar.
            if (!$options->dryRun
                && (int) $conn->fetchOne('SELECT COUNT(*) FROM dataset WHERE status = ?', [Dataset::STATUS_IMPORTING]) > 0) {
                throw KeywordTreatmentBlockedException::importRunning();
            }

            // Com a trava em mãos nenhum outro tratamento está rodando: processamento 'running' que sobrou
            // (processo morto, servidor reiniciado) nunca vai terminar e passa a 'failed'.
            $conn->executeStatement(
                "UPDATE keyword_treatment_job SET status = 'failed', finished_at = COALESCE(finished_at, NOW()), updated_at = NOW() WHERE status = 'running'"
            );

            return $this->runJob($options, $startedBy);
        } finally {
            try {
                $conn->fetchOne('SELECT RELEASE_LOCK(?)', [self::LOCK_NAME]);
            } catch (\Throwable) {}
        }
    }

    private function runJob(KeywordTreatmentOptions $options, string $startedBy): KeywordTreatmentJob
    {
        $job = new KeywordTreatmentJob();
        $job->setMode($options->dryRun ? 'dry_run' : 'execute');
        $job->setStartedBy($startedBy);
        $job->setStatus('running');
        $this->em->persist($job);
        $this->em->flush();

        try {
            $conn = $this->em->getConnection();

            // Pre-load thesaurus maps
            $this->thesaurusMatcher->loadMaps();

            // Sugestões pendentes ou recusadas e alertas de suspeito já registrados
            $memory = KeywordTreatmentMemory::load($conn);

            // Load document frequencies for canonical tie-breakers
            $freqByKw = [];
            foreach ($conn->iterateKeyValue('SELECT keyword_id, COUNT(document_id) AS cnt FROM document_keyword GROUP BY keyword_id') as $kwId => $cnt) {
                $freqByKw[$kwId] = (int)$cnt;
            }

            $cleanedCount = 0;
            $invalidCount = 0;
            $suspiciousCount = 0;
            $exactMatchedCount = 0;
            $thesaurusMatchedCount = 0;
            $fuzzyAutoMatchedCount = 0;
            $fuzzyReviewCount = 0;
            $createdConceptCount = 0;
            $skippedCount = 0;
            $errorCount = 0;
            // Vínculos do termo fundido (movidos para o existente): contados antes da fusão
            $mergedDkCount = 0;
            $mergedDocIds = [];

            // Paginação por chave (id > último): no modo Real a fusão apaga linhas,
            // e um OFFSET pularia termos ainda não processados.
            $lastId = 0;
            $batchSize = $options->batchSize;
            $totalProcessed = 0;
            $limit = ($options->limit && $options->limit > 0) ? $options->limit : PHP_INT_MAX;

            while ($totalProcessed < $limit) {
                $batchLimit = min($batchSize, $limit - $totalProcessed);
                $keywords = $this->fetchBatch($lastId, $batchLimit);

                if (empty($keywords)) break;
                $lastId = end($keywords)->getId();

                $assignedInBatch = [];
                $deletedInBatch = [];
                $this->batchLogs = [];

                // Gravações do lote (etapas 1 a 3: limpeza, fusões, agrupamento e tesauro exato) numa transação
                // só: uma fusão tem vários comandos e não pode ficar pela metade. A comparação aproximada
                // (etapa 4, lenta) roda fora da transação.
                $conn->beginTransaction();
                try {

                // === Pass 1: Cleaning & Invalid marking ===
                if ($options->processInvalids) {
                    foreach ($keywords as $kw) {
                        $original = $kw->getKeywordOriginal();
                        $normRes = $this->normalizer->normalizeKeyword($original);

                        $oldDisplay = $kw->getKeywordDisplay();
                        $oldNorm = $kw->getKeywordNormalized();

                        if (!$normRes['valid']) {
                            // Mesmo motivo já gravado: invalidado antes (pendente) ou aceito depois
                            // por um administrador (ativo). Fica como está, sem log nem contagem.
                            if ($kw->getReviewReasons() === $normRes['reason']) {
                                continue;
                            }
                            $invalidCount++;
                            if (!$options->dryRun) {
                                $kw->setStatus(false);
                                $kw->setReviewReasons($normRes['reason']);
                            }
                            $this->logAction($job, $kw, 'invalid', $oldDisplay, $normRes['display'], $oldNorm, $normRes['normalized'], null, null, null, $normRes['reason'], $options->dryRun, 'normalizer');
                            continue;
                        }

                        // Termo que sobrevive ao passo (muda se for fundido em outro)
                        $survivor = $kw;

                        // If dirty
                        if ($normRes['display'] !== $oldDisplay || $normRes['normalized'] !== $oldNorm) {
                            $targetNorm = $normRes['normalized'];
                            $targetType = $kw->getKeywordType();
                            $mapKey = $targetNorm . '||' . $targetType;

                            // Check if this normalized term and type already exists in the database
                            $existingId = $conn->fetchOne(
                                'SELECT id FROM keyword WHERE keyword_normalized = ? AND keyword_type = ? AND id != ?',
                                [$targetNorm, $targetType, $kw->getId()]
                            );

                            // Or if it was already assigned to another keyword in the current batch in memory
                            if (!$existingId && isset($assignedInBatch[$mapKey])) {
                                $existingId = $assignedInBatch[$mapKey];
                            }

                            // O termo que colide não é mais apagado: fica agrupado no existente. Numa rodada seguinte ele
                            // continua com a forma normalizada antiga e colidindo; já agrupado, só o rótulo é limpo, sem
                            // contar nem registrar de novo (como acontecia quando o termo sumia depois da fusão).
                            if ($existingId && $this->isAlreadyGrouped((int)$kw->getId(), (int)$existingId)) {
                                if (!$options->dryRun && $normRes['display'] !== $oldDisplay) {
                                    $kw->setKeywordDisplay($normRes['display']);
                                }
                            } elseif ($existingId) {
                                $cleanedCount++;
                                $kwId = $kw->getId();
                                $canonical = $this->em->getReference(Keyword::class, (int)$existingId);

                                // Os logs do termo apagado passam ao que fica (passo 4 abaixo)
                                $memory->moveKeyword($kwId, (int)$existingId);

                                $kwDocIds = $conn->fetchFirstColumn('SELECT document_id FROM document_keyword WHERE keyword_id = ?', [$kwId]);
                                $mergedDkCount += count($kwDocIds);
                                foreach ($kwDocIds as $docId) {
                                    $mergedDocIds[(int)$docId] = true;
                                }

                                if (!$options->dryRun) {
                                    // Colisão de forma normalizada: o termo passa a ser AGRUPADO no existente (conceito agrupador,
                                    // ou mapeamento global quando há conceito de tesauro no caminho). Nada é apagado nem
                                    // repontuado: o termo, as variações, os vínculos e os logs dele ficam como estão, e o
                                    // serviço grava o diário (origem treatment, sem usuário) e recalcula o efetivo.

                                    // O conceito do tesauro do termo agrupado não se perde: o existente o herda se não tiver um
                                    if ($kw->getThesaurusConcept() !== null) {
                                        $conn->executeStatement(
                                            'UPDATE keyword SET thesaurus_concept_id = COALESCE(thesaurus_concept_id, ?) WHERE id = ?',
                                            [$kw->getThesaurusConcept()->getId(), (int)$existingId]
                                        );
                                        $this->markEffective((int)$existingId);
                                    }
                                    $this->em->flush();
                                    $result = $this->mapping()->merge(
                                        AuthorityMappingService::ENTITY_KEYWORD, (int)$kwId, (int)$existingId,
                                        AuthorityMappingService::SCOPE_GLOBAL, 0, 'treatment', null, 'job #' . $job->getId()
                                    );

                                    // Objetos em memória cujo conceito agrupador mudou por SQL (senão um flush ou o passo 2 usaria o antigo)
                                    $stale = array_flip(array_merge([$kwId], $result['dependents']));
                                    foreach ($keywords as $other) {
                                        if (isset($stale[$other->getId()])) {
                                            $this->em->refresh($other);
                                        }
                                    }
                                    // Limpeza do rótulo continua; a forma normalizada fica (é a chave que a importação usa para achar o termo)
                                    $kw->setKeywordDisplay($normRes['display']);
                                    $deletedInBatch[$kwId] = true; // já agrupado: fora das etapas seguintes deste lote
                                }

                                // Registrado no termo que sobrevive; o motivo guarda o ID apagado
                                $survivor = $canonical;
                                $this->logAction($job, $canonical, 'merged', $oldDisplay, $normRes['display'], $oldNorm, $targetNorm, null, null, null, sprintf('merged_duplicate: #%d "%s" → #%d', $kwId, $original, (int)$existingId), $options->dryRun, 'normalizer');
                            } else {
                                $cleanedCount++;
                                if (!$options->dryRun) {
                                    // Safely update display name and normalized name
                                    $kw->setKeywordDisplay($normRes['display']);
                                    $kw->setKeywordNormalized($targetNorm);
                                }

                                // Register in our batch tracking map
                                $assignedInBatch[$mapKey] = $kw->getId();

                                $this->logAction($job, $kw, 'cleaned', $oldDisplay, $normRes['display'], $oldNorm, $normRes['normalized'], null, null, null, 'string_cleanup', $options->dryRun, 'normalizer');
                            }
                        }

                        // Suspeito: o TextNormalizer devolve 'needs_review' + 'review_reasons'
                        // Registrado uma vez só: o alerta já aplicado com o mesmo motivo não se repete
                        if (!empty($normRes['needs_review'])) {
                            $suspiciousReason = implode(',', $normRes['review_reasons'] ?? []) ?: 'suspicious_term';
                            if (!$memory->hasSuspicious((int)$survivor->getId(), $suspiciousReason)) {
                                $suspiciousCount++;
                                $memory->rememberSuspicious((int)$survivor->getId(), $suspiciousReason);
                                $this->logAction($job, $survivor, 'suspicious', $oldDisplay, $normRes['display'], $oldNorm, $normRes['normalized'], null, null, null, $suspiciousReason, $options->dryRun, 'normalizer');
                            }
                        }
                    }
                }

                // === Pass 2: Exact grouping by normalized value ===
                if ($options->processExact) {
                    $groupedByNorm = [];
                    foreach ($keywords as $kw) {
                        if (isset($deletedInBatch[$kw->getId()])) continue;
                        if (!$kw->isStatus() || $kw->getKeywordNormalized() === '') continue;
                        $groupedByNorm[$kw->getKeywordNormalized()][] = $kw;
                    }

                    foreach ($groupedByNorm as $normalized => $group) {
                        if (count($group) <= 1) continue;

                        // Pick canonical: prefer one that already has thesaurusConcept, then keywordConcept, then highest frequency
                        $canonical = null;
                        foreach ($group as $candidate) {
                            if ($candidate->getThesaurusConcept() !== null) {
                                $canonical = $candidate;
                                break;
                            }
                        }
                        if (!$canonical) {
                            foreach ($group as $candidate) {
                                if ($candidate->getKeywordConcept() !== null) {
                                    $canonical = $candidate->getKeywordConcept();
                                    break;
                                }
                            }
                        }
                        if (!$canonical) {
                            usort($group, function ($a, $b) use ($freqByKw) {
                                $freqA = $freqByKw[$a->getId()] ?? 0;
                                $freqB = $freqByKw[$b->getId()] ?? 0;
                                return $freqB <=> $freqA;
                            });
                            $canonical = $group[0];
                        }

                        foreach ($group as $member) {
                            if ($member->getId() === ($canonical instanceof Keyword ? $canonical->getId() : null)) continue;
                            if ($member->getThesaurusConcept() !== null) continue; // already linked

                            // If canonical has a thesaurusConcept, link member to it
                            $tc = ($canonical instanceof Keyword) ? $canonical->getThesaurusConcept() : null;
                            if ($tc) {
                                $exactMatchedCount++;
                                if (!$options->dryRun) {
                                    $member->setThesaurusConcept($tc);
                                    $this->markEffective((int)$member->getId());
                                }
                                $this->logAction($job, $member, 'exact_label_matched', $member->getKeywordDisplay(), $member->getKeywordDisplay(), $member->getKeywordNormalized(), $member->getKeywordNormalized(), null, $tc, 100.0, 'exact_normalized_match', $options->dryRun, 'exact_label');
                            } else {
                                // Já agrupado neste termo por um processamento anterior: não conta de novo
                                if ($member->getKeywordConcept()?->getId() === $canonical->getId()) continue;

                                // Legacy: link via keywordConcept
                                $exactMatchedCount++;
                                if (!$options->dryRun && $canonical instanceof Keyword) {
                                    $member->setKeywordConcept($canonical);
                                    $this->markEffective((int)$member->getId());
                                }
                                $this->logAction($job, $member, 'exact_concept_matched', $member->getKeywordDisplay(), $member->getKeywordDisplay(), $member->getKeywordNormalized(), $member->getKeywordNormalized(), null, null, 100.0, 'exact_normalized_group_legacy', $options->dryRun, 'exact_concept', $canonical);
                            }
                        }
                    }
                }

                // === Pass 3: Thesaurus matching ===
                if ($options->processThesaurus) {
                    foreach ($keywords as $kw) {
                        if (isset($deletedInBatch[$kw->getId()])) continue;
                        if (!$kw->isStatus() || $kw->getThesaurusConcept() !== null) continue;

                        // Só a correspondência exata: a comparação aproximada é calculada uma vez, na etapa 4
                        $result = $this->thesaurusMatcher->matchExact($kw);

                        if ($result === null) continue;

                        if ($result['method'] === 'exact_label' || $result['method'] === 'exact_concept') {
                            $thesaurusMatchedCount++;
                            // Carregado também na simulação, para o relatório mostrar o conceito
                            $concept = $this->thesaurusMatcher->getConceptEntity($result['conceptId']);
                            if (!$options->dryRun && $concept) {
                                $kw->setThesaurusConcept($concept);
                                $this->markEffective((int)$kw->getId());
                                $this->thesaurusMatcher->recordMatch($kw, $concept, $result['method'], 100.0, 'automatic');
                            }
                            $this->logAction($job, $kw, 'exact_label_matched', $kw->getKeywordDisplay(), $kw->getKeywordDisplay(), $kw->getKeywordNormalized(), $kw->getKeywordNormalized(), null, $concept, 100.0, $result['method'] . ': ' . $result['conceptLabel'], $options->dryRun, $result['method']);
                        }
                    }
                }

                // Fim das etapas 1 a 3: grava e confirma antes da comparação aproximada
                $this->em->flush();
                $this->refreshEffective();
                $conn->commit();
                } catch (\Throwable $e) {
                    $this->effectiveDirty = [];
                    $this->rollbackQuietly($conn);
                    throw $e;
                }

                // === Pass 4: Fuzzy matching ===
                // 4a. Calcula (sem gravar nada, fora de transação) a melhor correspondência de cada termo.
                $fuzzyResults = [];
                if ($options->processFuzzy) {
                    foreach ($keywords as $kw) {
                        if (isset($deletedInBatch[$kw->getId()])) continue;
                        if (!$kw->isStatus() || $kw->getThesaurusConcept() !== null) continue;

                        $fuzzyResults[] = [$kw, $this->thesaurusMatcher->match($kw, $options->minAutoScore, $options->minReviewScore)];
                    }
                }

                // 4b. Grava o que a etapa 4 decidiu, numa transação curta
                $conn->beginTransaction();
                try {
                    foreach ($fuzzyResults as [$kw, $result]) {

                        if ($result['conceptId'] === null || $result['score'] < $options->minReviewScore) continue;
                        // Match exato é da etapa 3 (na simulação o termo não fica ligado e voltava aqui como "revisão 100%")
                        if ($result['method'] === 'exact_label' || $result['method'] === 'exact_concept') continue;

                        $concept =$this->thesaurusMatcher->getConceptEntity($result['conceptId']);

                        if ($result['method'] === 'fuzzy_auto' && !$result['ambiguous']) {
                            $fuzzyAutoMatchedCount++;
                            if (!$options->dryRun && $concept) {
                                $kw->setThesaurusConcept($concept);
                                $this->markEffective((int)$kw->getId());
                                $this->thesaurusMatcher->recordMatch($kw, $concept, 'fuzzy', $result['score'] / 100.0, 'automatic');
                            }
                            $this->logAction($job, $kw, 'fuzzy_auto_matched', $kw->getKeywordDisplay(), $kw->getKeywordDisplay(), $kw->getKeywordNormalized(), $kw->getKeywordNormalized(), null, $concept, $result['score'], 'fuzzy_score: ' . round($result['score'], 1) . '% → ' . $result['conceptLabel'], $options->dryRun, 'fuzzy');
                        } elseif ($result['score'] >= $options->minReviewScore) {
                            // Sugestão pendente ou recusada para o mesmo conceito: não registra nem conta de novo
                            if ($memory->hasSuggestion((int)$kw->getId(), (int)$result['conceptId'])) {
                                continue;
                            }
                            $memory->rememberSuggestion((int)$kw->getId(), (int)$result['conceptId']);
                            $fuzzyReviewCount++;
                            if (!$options->dryRun && $concept) {
                                $this->thesaurusMatcher->recordMatch($kw, $concept, 'fuzzy', $result['score'] / 100.0, 'pending');
                            }
                            $reason = $result['ambiguous']
                                ? 'ambiguous_fuzzy: ' . round($result['score'], 1) . '% → ' . $result['conceptLabel']
                                : 'fuzzy_review: ' . round($result['score'], 1) . '% → ' . $result['conceptLabel'];
                            // O conceito sugerido fica no log: é dele que a tela de revisão aprova a ligação
                            $this->logAction($job, $kw, 'fuzzy_review_required', $kw->getKeywordDisplay(), $kw->getKeywordDisplay(), $kw->getKeywordNormalized(), $kw->getKeywordNormalized(), null, $concept, $result['score'], $reason, $options->dryRun, 'fuzzy');
                        }
                    }

                    // Flush batch (both modes: logs need to be persisted too)
                    $this->em->flush();
                    $this->refreshEffective();
                    $conn->commit();
                } catch (\Throwable $e) {
                    $this->effectiveDirty = [];
                    $this->rollbackQuietly($conn);
                    throw $e;
                }

                $totalProcessed += count($keywords);

                // Always clear entity manager and free memory after each batch
                $jobId = $job->getId();
                unset($keywords, $assignedInBatch, $deletedInBatch, $fuzzyResults);
                $this->batchLogs = [];
                $this->em->clear();
                gc_collect_cycles();
                $job = $this->em->getRepository(KeywordTreatmentJob::class)->find($jobId);
            }

            // Vínculos e documentos cujas palavras-chave este job alterou (ou alteraria, na simulação).
            // Fusões entram pelos vínculos do termo apagado, medidos antes de movê-los.
            // Só contagens no SQL: a lista de document_id de um tratamento real chega a milhões de linhas.
            $affectedFrom = "FROM document_keyword dk
                 WHERE dk.keyword_id IN (
                     SELECT DISTINCT l.keyword_id FROM keyword_treatment_log l
                     WHERE l.job_id = ? AND l.action NOT IN ('suspicious', 'fuzzy_review_required', 'merged')
                 )";
            $affectedDkRows = (int)$conn->fetchOne("SELECT COUNT(*) $affectedFrom", [$job->getId()]);
            $affectedDocs = (int)$conn->fetchOne("SELECT COUNT(DISTINCT dk.document_id) $affectedFrom", [$job->getId()]);
            // Documentos dos termos fundidos que também estão entre os afetados (para não contar duas vezes)
            $mergedAlsoAffected = 0;
            foreach (array_chunk(array_keys($mergedDocIds), 1000) as $chunk) {
                $mergedAlsoAffected += (int)$conn->fetchOne(
                    "SELECT COUNT(DISTINCT dk.document_id) $affectedFrom AND dk.document_id IN (" . implode(',', array_map('intval', $chunk)) . ')',
                    [$job->getId()]
                );
            }
            $affectedDkCount = $affectedDkRows + $mergedDkCount;
            $affectedDocCount = $affectedDocs + count($mergedDocIds) - $mergedAlsoAffected;

            // Fill job counts
            $job->setTotalKeywords($totalProcessed);
            $job->setTotalDocumentKeywords((int)$conn->fetchOne('SELECT COUNT(*) FROM document_keyword'));
            $job->setCleanedCount($cleanedCount);
            $job->setInvalidCount($invalidCount);
            $job->setSuspiciousCount($suspiciousCount);
            $job->setExactMatchedCount($exactMatchedCount);
            $job->setExactGroupedCount($exactMatchedCount); // backward compat
            $job->setThesaurusMatchedCount($thesaurusMatchedCount);
            $job->setFuzzyAutoMatchedCount($fuzzyAutoMatchedCount);
            $job->setFuzzyReviewCount($fuzzyReviewCount);
            $job->setCreatedConceptCount($createdConceptCount);
            $job->setSkippedCount($skippedCount);
            $job->setErrorCount($errorCount);
            $job->setAffectedDocumentKeywordCount($affectedDkCount);
            $job->setAffectedDocumentCount($affectedDocCount);
            $job->setFinishedAt(new DateTimeImmutable());
            $job->setUpdatedAt(new DateTimeImmutable());
            $job->setStatus('completed');

            $this->em->flush();

        } catch (\Throwable $e) {
            // Use raw SQL to update job status since EM may be closed
            try {
                $this->em->getConnection()->executeStatement(
                    'UPDATE keyword_treatment_job SET status = ?, finished_at = NOW(), updated_at = NOW() WHERE id = ?',
                    ['failed', $job->getId()]
                );
            } catch (\Throwable $ignore) {}
            throw $e;
        }

        return $job;
    }

    private function rollbackQuietly(Connection $conn): void
    {
        try {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
        } catch (\Throwable) {
            // o erro original é o que importa
        }
    }

    /**
     * Próximo lote de palavras-chave por ID (id > último).
     *
     * @return Keyword[]
     */
    protected function fetchBatch(int $lastId, int $limit): array
    {
        return $this->em->getRepository(Keyword::class)->createQueryBuilder('k')
            ->where('k.id > :lastId')
            ->setParameter('lastId', $lastId)
            ->orderBy('k.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    private function logAction(
        KeywordTreatmentJob $job,
        Keyword $kw,
        string $action,
        ?string $oldDisplay,
        ?string $newDisplay,
        ?string $oldNorm,
        ?string $newNorm,
        ?ThesaurusConcept $oldThesaurusConcept,
        ?ThesaurusConcept $newThesaurusConcept,
        ?float $score,
        ?string $reason,
        bool $dryRun,
        ?string $matchMethod = null,
        ?Keyword $newKeywordConcept = null
    ): void {
        $log = new KeywordTreatmentLog();
        $log->setJob($job);
        $log->setKeyword($kw);
        $log->setAction($action);
        $log->setOldDisplay($oldDisplay);
        $log->setNewDisplay($newDisplay);
        $log->setOldNormalized($oldNorm);
        $log->setNewNormalized($newNorm);
        $log->setOldThesaurusConcept($oldThesaurusConcept);
        $log->setNewThesaurusConcept($newThesaurusConcept);
        $log->setNewConcept($newKeywordConcept);
        $log->setScore($score);
        $log->setReason($reason);
        $log->setMatchMethod($matchMethod);
        // Simulação não gera pendência: 'pending' é reservado às sugestões reais da fila de revisão
        if ($dryRun) {
            $status = 'simulated';
        } else {
            $status = $action === 'fuzzy_review_required' ? 'pending' : 'applied';
        }
        $log->setStatus($status);

        $this->em->persist($log);
        $this->batchLogs[] = $log;
    }
}
