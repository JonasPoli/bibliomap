<?php

namespace App\Service\KeywordTreatment;

use App\Entity\Keyword;
use App\Entity\KeywordTreatmentJob;
use App\Entity\KeywordTreatmentLog;
use App\Entity\ThesaurusConcept;
use App\Entity\ThesaurusScheme;
use App\Entity\ThesaurusLabel;
use App\Service\Import\TextNormalizer;
use App\Service\Keyword\KeywordThesaurusMatcherService;
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
        private readonly KeywordThesaurusMatcherService $thesaurusMatcher
    ) {
        $this->normalizer = new TextNormalizer();
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

        // Count dirty and invalid keywords
        $stmt = $conn->executeQuery('SELECT id, keyword_display, keyword_original FROM keyword LIMIT 10000');
        $dirtyCount = 0;
        $invalidCount = 0;
        $suspiciousCount = 0;
        while ($row = $stmt->fetchAssociative()) {
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
    public function executeJob(KeywordTreatmentOptions $options, string $startedBy = 'system'): KeywordTreatmentJob
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

            // Load document frequencies for canonical tie-breakers
            $freqs = $conn->fetchAllAssociative('SELECT keyword_id, COUNT(document_id) AS cnt FROM document_keyword GROUP BY keyword_id');
            $freqByKw = [];
            foreach ($freqs as $f) {
                $freqByKw[$f['keyword_id']] = (int)$f['cnt'];
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
                $keywords = $this->em->getRepository(Keyword::class)->createQueryBuilder('k')
                    ->where('k.id > :lastId')
                    ->setParameter('lastId', $lastId)
                    ->orderBy('k.id', 'ASC')
                    ->setMaxResults($batchLimit)
                    ->getQuery()
                    ->getResult();

                if (empty($keywords)) break;
                $lastId = end($keywords)->getId();

                $assignedInBatch = [];
                $deletedInBatch = [];
                $this->batchLogs = [];

                // === Pass 1: Cleaning & Invalid marking ===
                if ($options->processInvalids) {
                    foreach ($keywords as $kw) {
                        $original = $kw->getKeywordOriginal();
                        $normRes = $this->normalizer->normalizeKeyword($original);

                        $oldDisplay = $kw->getKeywordDisplay();
                        $oldNorm = $kw->getKeywordNormalized();

                        if (!$normRes['valid']) {
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
                            $cleanedCount++;
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

                            if ($existingId) {
                                $kwId = $kw->getId();
                                $canonical = $this->em->getReference(Keyword::class, (int)$existingId);

                                $kwDocIds = $conn->fetchFirstColumn('SELECT document_id FROM document_keyword WHERE keyword_id = ?', [$kwId]);
                                $mergedDkCount += count($kwDocIds);
                                foreach ($kwDocIds as $docId) {
                                    $mergedDocIds[(int)$docId] = true;
                                }

                                if (!$options->dryRun) {
                                    // Duplicate collision! Merge via raw SQL and detach from Doctrine.

                                    // 1. Delete matching pairs in document_keyword to avoid unique key constraint violation
                                    $dupDkIds = $conn->fetchFirstColumn('
                                        SELECT dk1.id
                                        FROM document_keyword dk1
                                        JOIN document_keyword dk2 ON dk1.document_id = dk2.document_id AND dk2.keyword_id = ?
                                        WHERE dk1.keyword_id = ?
                                    ', [(int)$existingId, $kwId]);

                                    if (!empty($dupDkIds)) {
                                        $conn->executeStatement('DELETE FROM document_keyword WHERE id IN (' . implode(',', $dupDkIds) . ')');
                                    }

                                    // 2. Re-point remaining document_keyword rows to the canonical keyword
                                    $conn->executeStatement('UPDATE document_keyword SET keyword_id = ? WHERE keyword_id = ?', [(int)$existingId, $kwId]);

                                    // 3. Re-point keyword variations e guarda a grafia original do termo apagado como variação
                                    $conn->executeStatement('UPDATE palavra_chave_variacoes_nome SET keyword_id = ? WHERE keyword_id = ?', [(int)$existingId, $kwId]);
                                    $conn->executeStatement('
                                        INSERT INTO palavra_chave_variacoes_nome (keyword_id, variation_name, normalized_name, variation_type, status)
                                        SELECT ?, ?, ?, \'alternative\', 1 FROM DUAL
                                        WHERE NOT EXISTS (SELECT 1 FROM palavra_chave_variacoes_nome WHERE keyword_id = ? AND variation_name = BINARY ?)
                                          AND NOT EXISTS (SELECT 1 FROM keyword WHERE id = ? AND keyword_original = BINARY ?)
                                    ', [(int)$existingId, $original, $targetNorm, (int)$existingId, $original, (int)$existingId, $original]);

                                    // 4. Re-point keyword treatment logs to preserve logs history
                                    $conn->executeStatement('UPDATE keyword_treatment_log SET keyword_id = ? WHERE keyword_id = ?', [(int)$existingId, $kwId]);
                                    $conn->executeStatement('UPDATE thesaurus_match SET keyword_id = ? WHERE keyword_id = ?', [(int)$existingId, $kwId]);

                                    // 5. Agrupamentos legados e conceito do tesauro não se perdem com a exclusão
                                    $conn->executeStatement('UPDATE keyword SET keyword_concept_id = ? WHERE keyword_concept_id = ? AND id != ?', [(int)$existingId, $kwId, (int)$existingId]);
                                    if ($kw->getThesaurusConcept() !== null) {
                                        $conn->executeStatement(
                                            'UPDATE keyword SET thesaurus_concept_id = COALESCE(thesaurus_concept_id, ?) WHERE id = ?',
                                            [$kw->getThesaurusConcept()->getId(), (int)$existingId]
                                        );
                                    }
                                    // Objetos em memória que apontam para o termo apagado (senão o flush falha)
                                    foreach ($keywords as $other) {
                                        if ($other !== $kw && $other->getKeywordConcept() === $kw) {
                                            $other->setKeywordConcept($canonical);
                                        }
                                    }
                                    foreach ($this->batchLogs as $pendingLog) {
                                        if ($pendingLog->getKeyword() === $kw) {
                                            $pendingLog->setKeyword($canonical);
                                        }
                                        if ($pendingLog->getNewConcept() === $kw) {
                                            $pendingLog->setNewConcept($canonical);
                                        }
                                    }

                                    // 6. Delete the duplicate keyword via raw SQL and detach from Doctrine
                                    $deletedInBatch[$kwId] = true;
                                    $this->em->detach($kw);
                                    $conn->executeStatement('DELETE FROM keyword WHERE id = ?', [$kwId]);
                                }

                                // Registrado no termo que sobrevive; o motivo guarda o ID apagado
                                $survivor = $canonical;
                                $this->logAction($job, $canonical, 'merged', $oldDisplay, $normRes['display'], $oldNorm, $targetNorm, null, null, null, sprintf('merged_duplicate: #%d "%s" → #%d', $kwId, $original, (int)$existingId), $options->dryRun, 'normalizer');
                            } else {
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
                        if (!empty($normRes['needs_review'])) {
                            $suspiciousCount++;
                            $this->logAction($job, $survivor, 'suspicious', $oldDisplay, $normRes['display'], $oldNorm, $normRes['normalized'], null, null, null, implode(',', $normRes['review_reasons'] ?? []) ?: 'suspicious_term', $options->dryRun, 'normalizer');
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
                                }
                                $this->logAction($job, $member, 'exact_label_matched', $member->getKeywordDisplay(), $member->getKeywordDisplay(), $member->getKeywordNormalized(), $member->getKeywordNormalized(), null, $tc, 100.0, 'exact_normalized_match', $options->dryRun, 'exact_label');
                            } else {
                                // Já agrupado neste termo por um processamento anterior: não conta de novo
                                if ($member->getKeywordConcept()?->getId() === $canonical->getId()) continue;

                                // Legacy: link via keywordConcept
                                $exactMatchedCount++;
                                if (!$options->dryRun && $canonical instanceof Keyword) {
                                    $member->setKeywordConcept($canonical);
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

                        $result = $this->thesaurusMatcher->match($kw, $options->minAutoScore, $options->minReviewScore);

                        if ($result['conceptId'] === null) continue;

                        if ($result['method'] === 'exact_label' || $result['method'] === 'exact_concept') {
                            $thesaurusMatchedCount++;
                            // Carregado também na simulação, para o relatório mostrar o conceito
                            $concept = $this->thesaurusMatcher->getConceptEntity($result['conceptId']);
                            if (!$options->dryRun && $concept) {
                                $kw->setThesaurusConcept($concept);
                                $this->thesaurusMatcher->recordMatch($kw, $concept, $result['method'], 100.0, 'automatic');
                            }
                            $this->logAction($job, $kw, 'exact_label_matched', $kw->getKeywordDisplay(), $kw->getKeywordDisplay(), $kw->getKeywordNormalized(), $kw->getKeywordNormalized(), null, $concept, 100.0, $result['method'] . ': ' . $result['conceptLabel'], $options->dryRun, $result['method']);
                        }
                    }
                }

                // === Pass 4: Fuzzy matching ===
                if ($options->processFuzzy) {
                    foreach ($keywords as $kw) {
                        if (isset($deletedInBatch[$kw->getId()])) continue;
                        if (!$kw->isStatus() || $kw->getThesaurusConcept() !== null) continue;

                        $result = $this->thesaurusMatcher->match($kw, $options->minAutoScore, $options->minReviewScore);

                        if ($result['conceptId'] === null || $result['score'] < $options->minReviewScore) continue;
                        // Match exato é da etapa 3 (na simulação o termo não fica ligado e voltava aqui como "revisão 100%")
                        if ($result['method'] === 'exact_label' || $result['method'] === 'exact_concept') continue;

                        $concept =$this->thesaurusMatcher->getConceptEntity($result['conceptId']);

                        if ($result['method'] === 'fuzzy_auto' && !$result['ambiguous']) {
                            $fuzzyAutoMatchedCount++;
                            if (!$options->dryRun && $concept) {
                                $kw->setThesaurusConcept($concept);
                                $this->thesaurusMatcher->recordMatch($kw, $concept, 'fuzzy', $result['score'] / 100.0, 'automatic');
                            }
                            $this->logAction($job, $kw, 'fuzzy_auto_matched', $kw->getKeywordDisplay(), $kw->getKeywordDisplay(), $kw->getKeywordNormalized(), $kw->getKeywordNormalized(), null, $concept, $result['score'], 'fuzzy_score: ' . round($result['score'], 1) . '% → ' . $result['conceptLabel'], $options->dryRun, 'fuzzy');
                        } elseif ($result['score'] >= $options->minReviewScore) {
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
                }

                // Flush batch (both modes: logs need to be persisted too)
                $this->em->flush();

                $totalProcessed += count($keywords);

                // Always clear entity manager and free memory after each batch
                $jobId = $job->getId();
                unset($keywords, $assignedInBatch, $deletedInBatch);
                $this->batchLogs = [];
                $this->em->clear();
                gc_collect_cycles();
                $job = $this->em->getRepository(KeywordTreatmentJob::class)->find($jobId);
            }

            // Vínculos e documentos cujas palavras-chave este job alterou (ou alteraria, na simulação).
            // Fusões entram pelos vínculos do termo apagado, medidos antes de movê-los.
            $affectedDocIds = $conn->fetchFirstColumn(
                "SELECT dk.document_id
                 FROM document_keyword dk
                 WHERE dk.keyword_id IN (
                     SELECT DISTINCT l.keyword_id FROM keyword_treatment_log l
                     WHERE l.job_id = ? AND l.action NOT IN ('suspicious', 'fuzzy_review_required', 'merged')
                 )",
                [$job->getId()]
            );
            $affectedDkCount = count($affectedDocIds) + $mergedDkCount;
            $affectedDocCount = count(array_flip($affectedDocIds) + $mergedDocIds);

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
