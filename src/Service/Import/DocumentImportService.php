<?php

namespace App\Service\Import;

use App\DTO\BibliographicRecordDTO;
use App\Entity\Dataset;
use App\Service\Import\StringNormalizer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * High-performance bulk import service using DBAL directly.
 *
 * Bypasses Doctrine ORM entity tracking entirely to avoid:
 *  - EntityManager being closed on DB errors
 *  - Memory exhaustion from entity identity map
 *  - Overhead of change-tracking for 20k+ records
 *
 * Authors and keywords are resolved via SQL with an in-memory cache.
 * Documents are inserted in batches inside transactions.
 */
class DocumentImportService
{
    private const BATCH_SIZE = 200;

    // Keyed caches: normalized → int ID
    private array $authorCache  = [];
    private array $keywordCache = [];
    private ?TextNormalizer $textNormalizer = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly DocumentEnrichmentService $enrichmentService,
    ) {}

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * @param iterable<BibliographicRecordDTO> $records  Generator or array
     * @param \Closure|null $onProgress  Called after every batch with current $stats
     */
    public function importAll(iterable $records, Dataset $dataset, ?\Closure $onProgress = null, bool $skipEnrichment = false): array
    {
        $stats      = ['imported' => 0, 'skipped' => 0, 'errors' => 0];
        $projectId  = $dataset->getProject()->getId();
        $datasetId  = $dataset->getId();
        $now        = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->authorCache  = [];
        $this->keywordCache = [];

        // Pre-load ALL existing authors and keywords in ONE query each.
        // This replaces potentially 100k+ individual SELECT queries during import.
        $this->preloadCaches();

        // Pre-load existing DOIs and hashes for O(1) deduplication (one query each)
        $existingDois   = $this->loadMap('SELECT doi, id FROM document WHERE project_id = ? AND doi IS NOT NULL', $projectId);
        $existingHashes = $this->loadMap('SELECT hash, id FROM document WHERE project_id = ? AND hash IS NOT NULL', $projectId);

        // Batch accumulators
        $docsBatch        = [];
        $authorLinksBatch = [];
        $kwLinksBatch     = [];
        $skipsBatch       = [];

        foreach ($records as $dto) {
            try {
                $hash = $this->computeHash($dto);

                if ($dto->doi && isset($existingDois[$dto->doi])) {
                    $stats['skipped']++;
                    $matchedId = is_int($existingDois[$dto->doi]) ? $existingDois[$dto->doi] : null;
                    $skipsBatch[] = [
                        'dataset_id'          => $datasetId,
                        'title'               => $dto->title ?? 'Sem título',
                        'doi'                 => $dto->doi,
                        'hash'               => $hash,
                        'reason'              => 'doi',
                        'matched_document_id' => $matchedId,
                        'created_at'          => $now,
                    ];
                    if (count($skipsBatch) >= self::BATCH_SIZE) {
                        $this->flushSkipsBatch($skipsBatch);
                        $skipsBatch = [];
                    }
                    continue;
                }

                if ($hash && isset($existingHashes[$hash])) {
                    $stats['skipped']++;
                    $matchedId = is_int($existingHashes[$hash]) ? $existingHashes[$hash] : null;
                    $skipsBatch[] = [
                        'dataset_id'          => $datasetId,
                        'title'               => $dto->title ?? 'Sem título',
                        'doi'                 => $dto->doi,
                        'hash'               => $hash,
                        'reason'              => 'hash',
                        'matched_document_id' => $matchedId,
                        'created_at'          => $now,
                    ];
                    if (count($skipsBatch) >= self::BATCH_SIZE) {
                        $this->flushSkipsBatch($skipsBatch);
                        $skipsBatch = [];
                    }
                    continue;
                }

                if ($dto->doi)  $existingDois[$dto->doi]   = true;
                if ($hash)      $existingHashes[$hash]      = true;

                // ── Resolve authors (SQL, cached) ──────────────────
                $authorLinks = [];
                foreach ($dto->authorNames as $pos => $rawName) {
                    $name = trim($rawName);
                    if (!$name) continue;
                    $authorId = $this->resolveAuthorId($name, $now, $projectId);
                    if ($authorId) {
                        $authorLinks[] = [
                            'author_id'     => $authorId,
                            'position'      => $pos,
                            'original_name' => substr($name, 0, 255),
                        ];
                    }
                }

                // ── Resolve keywords (SQL, cached) ─────────────────
                $kwLinks = [];
                $usedKw  = [];
                foreach ($dto->authorKeywords as $term) {
                    $norm = strtolower(trim($term));
                    if (!$norm || isset($usedKw[$norm])) continue;
                    $usedKw[$norm] = true;
                    $id = $this->resolveKeywordId($term, 'author', $now, $projectId);
                    if ($id) {
                        $kwLinks[] = [
                            'keyword_id' => $id,
                            'original_term' => substr(trim($term), 0, 255),
                        ];
                    }
                }
                foreach ($dto->indexedKeywords as $term) {
                    $norm = strtolower(trim($term));
                    if (!$norm || isset($usedKw[$norm])) continue;
                    $usedKw[$norm] = true;
                    $id = $this->resolveKeywordId($term, 'indexed', $now, $projectId);
                    if ($id) {
                        $kwLinks[] = [
                            'keyword_id' => $id,
                            'original_term' => substr(trim($term), 0, 255),
                        ];
                    }
                }

                $docsBatch[]        = $this->buildDocRow($dto, $projectId, $datasetId, $now, $hash);
                $authorLinksBatch[] = $authorLinks;
                $kwLinksBatch[]     = $kwLinks;

                $stats['imported']++;

                if (count($docsBatch) >= self::BATCH_SIZE) {
                    $result = $this->flushBatch($docsBatch, $authorLinksBatch, $kwLinksBatch, $now);
                    // Reconcile: some records may have been rejected by the DB unique constraint
                    $dbSkipped = $result['skipped'];
                    if ($dbSkipped > 0) {
                        $stats['imported'] -= $dbSkipped;
                        $stats['skipped']  += $dbSkipped;
                    }
                    $docsBatch        = [];
                    $authorLinksBatch = [];
                    $kwLinksBatch     = [];

                    // Persist live progress to the DB so the status endpoint
                    // can show real-time progress while the import runs
                    if ($onProgress !== null) {
                        $onProgress($stats);
                    }
                }

            } catch (\Throwable $e) {
                $stats['errors']++;
                $this->logger->error('Import error: ' . $e->getMessage(), [
                    'eid'   => $dto->externalId ?? '?',
                    'title' => substr($dto->title ?? '', 0, 60),
                ]);
            }
        }

        // Final partial batch
        if ($docsBatch) {
            $result = $this->flushBatch($docsBatch, $authorLinksBatch, $kwLinksBatch, $now);
            $dbSkipped = $result['skipped'];
            if ($dbSkipped > 0) {
                $stats['imported'] -= $dbSkipped;
                $stats['skipped']  += $dbSkipped;
            }
            if ($onProgress !== null) {
                $onProgress($stats);
            }
        }

        if ($skipsBatch) {
            $this->flushSkipsBatch($skipsBatch);
        }

        // Run automated geographical and institutional enrichment after import
        try {
            $this->enrichmentService->enrichProject($projectId);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to run geographical enrichment after import: ' . $e->getMessage());
        }

        return $stats;
    }

    // ── Private: SQL helpers ──────────────────────────────────────────────────

    private function conn(): Connection
    {
        return $this->em->getConnection();
    }

    /**
     * Pre-load all existing authors and keywords into memory caches.
     * Two queries instead of one per unique author/keyword.
     */
    private function preloadCaches(): void
    {
        $conn = $this->conn();

        $authors = $conn->fetchAllAssociative('SELECT id, normalized_name FROM author_identity WHERE normalized_name IS NOT NULL');
        foreach ($authors as $row) {
            $this->authorCache[$row['normalized_name']] = (int) $row['id'];
        }

        $keywords = $conn->fetchAllAssociative('SELECT id, keyword_normalized, keyword_type FROM keyword');
        foreach ($keywords as $row) {
            $cacheKey = $row['keyword_normalized'] . '|' . $row['keyword_type'];
            $this->keywordCache[$cacheKey] = (int) $row['id'];
        }

        $this->logger->info(sprintf(
            'Cache pre-loaded: %d authors, %d keywords',
            count($this->authorCache),
            count($this->keywordCache)
        ));
    }

    private function loadSet(string $sql, int $projectId): array
    {
        $rows = $this->conn()->fetchAllAssociative($sql, [$projectId]);
        $set  = [];
        foreach ($rows as $row) {
            $val = reset($row);
            if ($val !== null) $set[$val] = true;
        }
        return $set;
    }

    private function textNormalizer(): TextNormalizer
    {
        if ($this->textNormalizer === null) {
            $this->textNormalizer = new TextNormalizer();
        }
        return $this->textNormalizer;
    }

    private function resolveAuthorId(string $name, string $now, int $projectId): ?int
    {
        $normalizer = $this->textNormalizer();
        $res = $normalizer->normalizeAuthor($name);

        if (!$res['valid']) {
            try {
                $this->conn()->insert('import_error', [
                    'project_id'     => $projectId,
                    'entity_type'    => 'author',
                    'original_value' => $name,
                    'reason'         => $res['reason'],
                    'created_at'     => $now,
                ]);
            } catch (\Throwable) {}
            return null;
        }

        $displayName = $res['display'];
        $normKey     = substr($res['normalized'], 0, 255);

        if (isset($this->authorCache[$normKey])) {
            return $this->authorCache[$normKey];
        }

        $conn = $this->conn();
        $id   = $conn->fetchOne('SELECT id FROM author_identity WHERE normalized_name = ?', [$normKey]);

        $status = $res['needs_review'] ? 0 : 1;
        $reasonsStr = !empty($res['review_reasons']) ? implode(',', $res['review_reasons']) : null;

        if (!$id) {
            try {
                $conn->insert('author_identity', [
                    'preferred_name'  => substr($displayName, 0, 255),
                    'normalized_name' => $normKey,
                    'status'          => $status,
                    'review_reasons'  => $reasonsStr,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ]);
                $id = (int) $conn->lastInsertId();
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
                // Concurrent insert by another process
                $id = (int) $conn->fetchOne('SELECT id FROM author_identity WHERE normalized_name = ?', [$normKey]);
            }
        }

        // Ensure variation entry exists
        $varExists = $conn->fetchOne(
            'SELECT id FROM author_name_variant WHERE author_identity_id = ? AND normalized_name = ?',
            [$id, $normKey]
        );
        if (!$varExists) {
            $conn->insert('author_name_variant', [
                'author_identity_id' => $id,
                'original_name'      => substr(trim($name), 0, 255),
                'display_name'       => substr($displayName, 0, 255),
                'normalized_name'    => $normKey,
                'source'             => 'import',
                'confidence'         => $res['needs_review'] ? 0.5 : 1.0,
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
        }

        $this->authorCache[$normKey] = (int) $id;
        return (int) $id;
    }

    private function resolveKeywordId(string $term, string $type, string $now, int $projectId): ?int
    {
        $mappedType = $type;
        if ($type === 'author') {
            $mappedType = 'author_keyword';
        } elseif ($type === 'indexed') {
            $mappedType = 'indexed_keyword';
        }

        $normalizer = $this->textNormalizer();
        $res = $normalizer->normalizeKeyword($term);

        if (!$res['valid']) {
            try {
                $this->conn()->insert('import_error', [
                    'project_id'     => $projectId,
                    'entity_type'    => 'keyword',
                    'original_value' => $term,
                    'reason'         => $res['reason'],
                    'created_at'     => $now,
                ]);
            } catch (\Throwable) {}
            return null;
        }

        $displayName = $res['display'];
        $normKey     = substr($res['normalized'], 0, 255);
        $cacheKey    = $normKey . '|' . $mappedType;

        if (isset($this->keywordCache[$cacheKey])) {
            return $this->keywordCache[$cacheKey];
        }

        $conn = $this->conn();
        $id   = $conn->fetchOne(
            'SELECT id FROM keyword WHERE keyword_normalized = ? AND keyword_type = ?',
            [$normKey, $mappedType]
        );

        $status = $res['needs_review'] ? 0 : 1;
        $reasonsStr = !empty($res['review_reasons']) ? implode(',', $res['review_reasons']) : null;

        if (!$id) {
            try {
                $conn->insert('keyword', [
                    'keyword_original'   => substr(trim($term), 0, 255),
                    'keyword_display'    => substr($displayName, 0, 255),
                    'keyword_normalized' => $normKey,
                    'keyword_type'       => $mappedType,
                    'status'             => $status,
                    'review_reasons'     => $reasonsStr,
                ]);
                $id = (int) $conn->lastInsertId();
                // Point concept to itself
                $conn->executeStatement('UPDATE keyword SET keyword_concept_id = ? WHERE id = ?', [$id, $id]);
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
                // Concurrent insert
                $id = (int) $conn->fetchOne(
                    'SELECT id FROM keyword WHERE keyword_normalized = ? AND keyword_type = ?',
                    [$normKey, $mappedType]
                );
            }
        }

        $this->keywordCache[$cacheKey] = (int) $id;
        return (int) $id;
    }

    /**
     * Insert a batch of documents + their author/keyword links inside a transaction.
     * Handles DB-level unique constraint violations gracefully (counts as skipped).
     *
     * @return array{inserted: int, skipped: int}
     */
    private function flushBatch(array $docs, array $authorLinks, array $kwLinks, string $now): array
    {
        $conn = $this->conn();
        $inserted = 0;
        $skipped  = 0;

        // Insert one by one within a transaction so a duplicate on one row
        // doesn't roll back the entire batch
        foreach ($docs as $i => $docRow) {
            $conn->beginTransaction();
            try {
                $columns = [];
                $placeholders = [];
                $params = [];
                foreach ($docRow as $col => $val) {
                    $columns[] = '`' . $col . '`';
                    $placeholders[] = '?';
                    $params[] = $val;
                }
                $sql = sprintf(
                    'INSERT INTO document (%s) VALUES (%s)',
                    implode(', ', $columns),
                    implode(', ', $placeholders)
                );
                $conn->executeStatement($sql, $params);
                $docId = (int) $conn->lastInsertId();

                foreach ($authorLinks[$i] as $link) {
                    $conn->insert('document_author', [
                        'document_id'        => $docId,
                        'author_identity_id' => $link['author_id'],
                        'position'           => $link['position'],
                        'original_name'      => $link['original_name'],
                    ]);
                }

                foreach ($kwLinks[$i] as $link) {
                    $conn->insert('document_keyword', [
                        'document_id'   => $docId,
                        'keyword_id'    => $link['keyword_id'],
                        'original_term' => $link['original_term'],
                    ]);
                }

                $conn->commit();
                $inserted++;
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
                // Record already exists in this project (db-level safety net)
                $conn->rollBack();
                $skipped++;

                $matchedDocId = null;
                if (!empty($docRow['doi'])) {
                    $matchedDocId = $conn->fetchOne(
                        'SELECT id FROM document WHERE project_id = ? AND doi = ?',
                        [$docRow['project_id'], $docRow['doi']]
                    );
                }
                if (!$matchedDocId && !empty($docRow['hash'])) {
                    $matchedDocId = $conn->fetchOne(
                        'SELECT id FROM document WHERE project_id = ? AND hash = ?',
                        [$docRow['project_id'], $docRow['hash']]
                    );
                }

                try {
                    $conn->insert('dataset_skip', [
                        'dataset_id'          => $docRow['dataset_id'],
                        'title'               => substr($docRow['title'] ?? 'Sem título', 0, 1000),
                        'doi'                 => $docRow['doi'] ?? null,
                        'hash'               => $docRow['hash'] ?? null,
                        'reason'              => 'db_constraint',
                        'matched_document_id' => $matchedDocId ? (int) $matchedDocId : null,
                        'created_at'          => $now,
                    ]);
                } catch (\Throwable) {}
            } catch (\Throwable $e) {
                $conn->rollBack();
                $this->logger->warning('Batch insert failed: ' . $e->getMessage(), [
                    'hash' => $docRow['hash'] ?? '?',
                ]);
                $skipped++;
            }
        }

        return ['inserted' => $inserted, 'skipped' => $skipped];
    }

    private function loadMap(string $sql, int $projectId): array
    {
        $rows = $this->conn()->fetchAllAssociative($sql, [$projectId]);
        $map  = [];
        foreach ($rows as $row) {
            $cols = array_values($row);
            if ($cols[0] !== null) {
                $map[$cols[0]] = (int) $cols[1];
            }
        }
        return $map;
    }

    private function flushSkipsBatch(array $skips): void
    {
        $conn = $this->conn();
        $conn->beginTransaction();
        try {
            foreach ($skips as $skip) {
                $conn->insert('dataset_skip', [
                    'dataset_id'          => $skip['dataset_id'],
                    'title'               => substr($skip['title'], 0, 1000),
                    'doi'                 => $skip['doi'],
                    'hash'               => $skip['hash'],
                    'reason'              => $skip['reason'],
                    'matched_document_id' => $skip['matched_document_id'],
                    'created_at'          => $skip['created_at'],
                ]);
            }
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            $this->logger->error('Failed to insert skips batch: ' . $e->getMessage());
        }
    }

    private function buildDocRow(BibliographicRecordDTO $dto, int $projectId, int $datasetId, string $now, ?string $hash): array
    {
        return [
            'project_id'        => $projectId,
            'dataset_id'        => $datasetId,
            'source'            => $dto->source,
            'external_id'       => $dto->externalId       ? substr($dto->externalId,       0, 100) : null,
            'title'             => $dto->title,
            'normalized_title'  => $dto->title             ? strtolower(trim($dto->title))          : null,
            'abstract_text'     => $dto->abstractText,
            'year'              => $dto->year,
            'document_type'     => $dto->documentType      ? substr($dto->documentType,      0, 50)  : null,
            'doi'               => $dto->doi               ? substr($dto->doi,               0, 255) : null,
            'pmid'              => $dto->pmid              ? substr($dto->pmid,              0, 50)  : null,
            'isbn'              => $dto->isbn              ? substr($dto->isbn,              0, 50)  : null,
            'issn'              => $dto->issn              ? substr($dto->issn,              0, 50)  : null,
            'url'               => $dto->url               ? substr($dto->url,               0, 500) : null,
            'language'          => $dto->language          ? substr($dto->language,          0, 10)  : null,
            'source_title'      => $dto->sourceTitle       ? substr($dto->sourceTitle,       0, 500) : null,
            'volume'            => $dto->volume            ? substr($dto->volume,            0, 20)  : null,
            'issue'             => $dto->issue             ? substr($dto->issue,             0, 20)  : null,
            'page_start'        => $dto->pageStart         ? substr($dto->pageStart,         0, 30)  : null,
            'page_end'          => $dto->pageEnd           ? substr($dto->pageEnd,           0, 30)  : null,
            'publisher'         => $dto->publisher         ? substr($dto->publisher,         0, 255) : null,
            'cited_by'          => $dto->citedBy,
            'local_citations'   => null,
            'open_access_status'=> $dto->openAccessStatus  ? substr($dto->openAccessStatus,  0, 100) : null,
            'publication_stage' => $dto->publicationStage  ? substr($dto->publicationStage,  0, 50)  : null,
            'hash'              => $hash,
            'countries'         => $dto->countries ? json_encode(array_values($dto->countries), JSON_UNESCAPED_UNICODE) : null,
            'institutions'      => $dto->institutions ? json_encode(array_values($dto->institutions), JSON_UNESCAPED_UNICODE) : null,
            'references'        => $dto->references ? json_encode(array_values($dto->references), JSON_UNESCAPED_UNICODE) : null,
            'created_at'        => $now,
        ];
    }

    private function computeHash(BibliographicRecordDTO $dto): ?string
    {
        if ($dto->doi) {
            return md5('doi:' . strtolower(trim($dto->doi)));
        }
        if ($dto->title && $dto->year) {
            $normalized = preg_replace('/\s+/', ' ', strtolower(trim($dto->title)));
            return md5($normalized . ':' . $dto->year);
        }
        return null;
    }

    private function parseName(string $name): array
    {
        if (str_contains($name, ',')) {
            $parts = explode(',', $name, 2);
            return [trim($parts[0]), trim($parts[1] ?? '')];
        }
        $parts   = explode(' ', trim($name));
        $surname = array_pop($parts);
        $initials = implode('', array_map(fn($p) => strtoupper(substr($p, 0, 1)) . '.', $parts));
        return [$surname, $initials];
    }
}
