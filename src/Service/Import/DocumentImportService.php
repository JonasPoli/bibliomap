<?php

namespace App\Service\Import;

use App\DTO\BibliographicRecordDTO;
use App\Entity\Dataset;
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

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * @param iterable<BibliographicRecordDTO> $records  Generator or array
     * @param \Closure|null $onProgress  Called after every batch with current $stats
     */
    public function importAll(iterable $records, Dataset $dataset, ?\Closure $onProgress = null): array
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
        $existingDois   = $this->loadSet('SELECT doi  FROM document WHERE project_id = ? AND doi  IS NOT NULL', $projectId);
        $existingHashes = $this->loadSet('SELECT hash FROM document WHERE project_id = ? AND hash IS NOT NULL', $projectId);

        // Batch accumulators
        $docsBatch        = [];
        $authorLinksBatch = [];
        $kwLinksBatch     = [];

        foreach ($records as $dto) {
            try {
                $hash = $this->computeHash($dto);

                if ($dto->doi && isset($existingDois[$dto->doi])) { $stats['skipped']++; continue; }
                if ($hash    && isset($existingHashes[$hash]))    { $stats['skipped']++; continue; }

                if ($dto->doi)  $existingDois[$dto->doi]   = true;
                if ($hash)      $existingHashes[$hash]      = true;

                // ── Resolve authors (SQL, cached) ──────────────────
                $authorLinks = [];
                foreach ($dto->authorNames as $pos => $rawName) {
                    $name = trim($rawName);
                    if (!$name) continue;
                    $authorId = $this->resolveAuthorId($name, $now);
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
                    $id = $this->resolveKeywordId($term, 'author');
                    if ($id) $kwLinks[] = $id;
                }
                foreach ($dto->indexedKeywords as $term) {
                    $norm = strtolower(trim($term));
                    if (!$norm || isset($usedKw[$norm])) continue;
                    $usedKw[$norm] = true;
                    $id = $this->resolveKeywordId($term, 'indexed');
                    if ($id) $kwLinks[] = $id;
                }

                $docsBatch[]        = $this->buildDocRow($dto, $projectId, $datasetId, $now, $hash);
                $authorLinksBatch[] = $authorLinks;
                $kwLinksBatch[]     = $kwLinks;

                $stats['imported']++;

                if (count($docsBatch) >= self::BATCH_SIZE) {
                    $result = $this->flushBatch($docsBatch, $authorLinksBatch, $kwLinksBatch);
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
            $result = $this->flushBatch($docsBatch, $authorLinksBatch, $kwLinksBatch);
            $dbSkipped = $result['skipped'];
            if ($dbSkipped > 0) {
                $stats['imported'] -= $dbSkipped;
                $stats['skipped']  += $dbSkipped;
            }
            if ($onProgress !== null) {
                $onProgress($stats);
            }
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

        $authors = $conn->fetchAllAssociative('SELECT id, normalized_name FROM author WHERE normalized_name IS NOT NULL');
        foreach ($authors as $row) {
            $this->authorCache[$row['normalized_name']] = (int) $row['id'];
        }

        $keywords = $conn->fetchAllAssociative('SELECT id, normalized_term, type FROM keyword');
        foreach ($keywords as $row) {
            $cacheKey = $row['normalized_term'] . '|' . $row['type'];
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

    private function resolveAuthorId(string $name, string $now): int
    {
        $normalized = strtolower(trim($name));
        $normKey    = substr($normalized, 0, 255);

        if (isset($this->authorCache[$normKey])) {
            return $this->authorCache[$normKey];
        }

        $conn = $this->conn();
        $id   = $conn->fetchOne('SELECT id FROM author WHERE normalized_name = ?', [$normKey]);

        if (!$id) {
            [$surname, $initials] = $this->parseName($name);

            // Strip non-ASCII from initials to avoid charset errors with
            // author names like "Ö.", "Ã.", etc. in latin1-adjacent MySQL configs
            $safeInitials = $initials
                ? preg_replace('/[^\x00-\x7F.\- ]/u', '', $initials)
                : null;
            $safeInitials = $safeInitials !== '' ? $safeInitials : null;

            try {
                $conn->insert('author', [
                    'name'            => substr(trim($name), 0, 255),
                    'normalized_name' => $normKey,
                    'surname'         => $surname  ? substr($surname,  0, 150) : null,
                    'initials'        => $safeInitials ? substr($safeInitials, 0, 20) : null,
                    'created_at'      => $now,
                ]);
                $id = (int) $conn->lastInsertId();
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
                // Concurrent insert by another process — fetch the existing row
                $id = (int) $conn->fetchOne('SELECT id FROM author WHERE normalized_name = ?', [$normKey]);
            }
        }

        $this->authorCache[$normKey] = (int) $id;
        return (int) $id;
    }

    private function resolveKeywordId(string $term, string $type): int
    {
        $normalized = strtolower(trim($term));
        $normKey    = substr($normalized, 0, 255);
        $cacheKey   = $normKey . '|' . $type;

        if (isset($this->keywordCache[$cacheKey])) {
            return $this->keywordCache[$cacheKey];
        }

        $conn = $this->conn();
        $id   = $conn->fetchOne(
            'SELECT id FROM keyword WHERE normalized_term = ? AND type = ?',
            [$normKey, $type]
        );

        if (!$id) {
            try {
                $conn->insert('keyword', [
                    'term'            => substr(trim($term), 0, 255),
                    'normalized_term' => $normKey,
                    'type'            => $type,
                ]);
                $id = (int) $conn->lastInsertId();
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
                // Concurrent insert — fetch the existing row
                $id = (int) $conn->fetchOne(
                    'SELECT id FROM keyword WHERE normalized_term = ? AND type = ?',
                    [$normKey, $type]
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
    private function flushBatch(array $docs, array $authorLinks, array $kwLinks): array
    {
        $conn = $this->conn();
        $inserted = 0;
        $skipped  = 0;

        // Insert one by one within a transaction so a duplicate on one row
        // doesn't roll back the entire batch
        foreach ($docs as $i => $docRow) {
            $conn->beginTransaction();
            try {
                $conn->insert('document', $docRow);
                $docId = (int) $conn->lastInsertId();

                foreach ($authorLinks[$i] as $link) {
                    $conn->insert('document_author', [
                        'document_id'   => $docId,
                        'author_id'     => $link['author_id'],
                        'position'      => $link['position'],
                        'original_name' => $link['original_name'],
                    ]);
                }

                foreach ($kwLinks[$i] as $kwId) {
                    $conn->insert('document_keyword', [
                        'document_id' => $docId,
                        'keyword_id'  => $kwId,
                    ]);
                }

                $conn->commit();
                $inserted++;
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
                // Record already exists in this project (db-level safety net)
                $conn->rollBack();
                $skipped++;
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
