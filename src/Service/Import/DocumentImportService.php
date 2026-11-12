<?php

namespace App\Service\Import;

use App\DTO\BibliographicRecordDTO;
use App\Entity\Dataset;
use App\Service\Authority\AuthorityResolver;
use App\Service\Import\StringNormalizer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
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
        private ?AuthorityResolver $authority = null,
    ) {}

    private function authority(): AuthorityResolver
    {
        return $this->authority ??= new AuthorityResolver($this->conn());
    }

    /** @var array<int,?int> projeto => dono (para a camada de mapeamento do usuário) */
    private array $projectOwner = [];

    /**
     * Efetivo dos vínculos do lote: uma consulta para os autores e outra para as palavras-chave distintas do lote.
     *
     * @param list<list<array>> $authorLinks
     * @param list<list<array>> $kwLinks
     * @return array{0: array<int,int>, 1: array<int,int>}  [autor bruto => efetivo, palavra bruta => efetivo]
     */
    private function effectiveMaps(array $authorLinks, array $kwLinks, int $projectId): array
    {
        if (!array_key_exists($projectId, $this->projectOwner)) {
            $u = $this->conn()->fetchOne('SELECT user_id FROM bibliometric_project WHERE id = ?', [$projectId]);
            $this->projectOwner[$projectId] = $u === false || $u === null ? null : (int) $u;
        }
        $a = [];
        foreach ($authorLinks as $links) {
            foreach ($links as $l) {
                $a[] = $l['author_id'];
            }
        }
        $k = [];
        foreach ($kwLinks as $links) {
            foreach ($links as $l) {
                $k[] = $l['keyword_id'];
            }
        }
        $owner = $this->projectOwner[$projectId];

        return [
            $a ? $this->authority()->effectiveMap(AuthorityResolver::ENTITY_AUTHOR, $projectId, $owner, $a) : [],
            $k ? $this->authority()->effectiveMap(AuthorityResolver::ENTITY_KEYWORD, $projectId, $owner, $k) : [],
        ];
    }

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
        // Documentos já gravados por uma execução anterior desta mesma importação
        // (reexecução após falha): contam como importados, não como duplicados.
        $ownDocIds = $this->loadSet('SELECT id FROM document WHERE dataset_id = ?', $datasetId);

        // Batch accumulators
        $docsBatch        = [];
        $authorLinksBatch = [];
        $kwLinksBatch     = [];
        $dtoBatch         = [];
        $skipsBatch       = [];

        foreach ($records as $dto) {
            // null = registro que o leitor não conseguiu interpretar
            if (!$dto instanceof BibliographicRecordDTO || (!$dto->title && !$dto->doi && !$dto->externalId)) {
                $stats['errors']++;
                continue;
            }

            try {
                $hash = self::computeHash($dto);

                $matchedId = null;
                if ($dto->doi && isset($existingDois[$dto->doi])) {
                    $matchedId = $existingDois[$dto->doi];
                } elseif ($hash && isset($existingHashes[$hash])) {
                    $matchedId = $existingHashes[$hash];
                }
                if (is_int($matchedId) && isset($ownDocIds[$matchedId])) {
                    $stats['imported']++;
                    // Repetições deste registro no arquivo passam a contar como duplicadas
                    if ($dto->doi) $existingDois[$dto->doi] = true;
                    if ($hash)     $existingHashes[$hash]   = true;
                    unset($ownDocIds[$matchedId]);
                    continue;
                }

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

                [$authorLinks, $kwLinks] = $this->resolveLinks($dto, $now, $projectId);

                $docsBatch[]        = $this->buildDocRow($dto, $projectId, $datasetId, $now, $hash);
                $authorLinksBatch[] = $authorLinks;
                $kwLinksBatch[]     = $kwLinks;
                $dtoBatch[]         = $dto; // para refazer os vínculos se o cache de IDs estiver velho

                $stats['imported']++;

                if (count($docsBatch) >= self::BATCH_SIZE) {
                    $result = $this->flushBatch($docsBatch, $authorLinksBatch, $kwLinksBatch, $dtoBatch, $now, $projectId);
                    // Reconcile: some records may have been rejected by the DB
                    $this->reconcile($stats, $result);
                    $docsBatch        = [];
                    $authorLinksBatch = [];
                    $kwLinksBatch     = [];
                    $dtoBatch         = [];

                    // Persist live progress to the DB so the status endpoint
                    // can show real-time progress while the import runs
                    if ($onProgress !== null) {
                        $onProgress($stats);
                    }
                }

            } catch (ConnectionException $e) {
                // Banco fora do ar: seguir registro a registro só geraria erros em série
                throw $e;
            } catch (\Throwable $e) {
                $stats['errors']++;
                $this->logger->error('Import error: ' . $e->getMessage(), [
                    'eid'   => $dto->externalId ?? '?',
                    'title' => self::cut($dto->title ?? '', 60),
                ]);
            }
        }

        // Final partial batch
        if ($docsBatch) {
            $result = $this->flushBatch($docsBatch, $authorLinksBatch, $kwLinksBatch, $dtoBatch, $now, $projectId);
            $this->reconcile($stats, $result);
            if ($onProgress !== null) {
                $onProgress($stats);
            }
        }

        if ($skipsBatch) {
            $this->flushSkipsBatch($skipsBatch);
        }

        // Enriquecimento geográfico/institucional reprocessa o projeto inteiro:
        // quem importa vários arquivos em sequência pede para pular e roda uma vez no fim.
        if (!$skipEnrichment) {
            try {
                $this->enrichmentService->enrichProject($projectId);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to run geographical enrichment after import: ' . $e->getMessage());
            }
        }

        return $stats;
    }

    /** Ajusta as contagens com o que o banco recusou ao gravar o lote. */
    private function reconcile(array &$stats, array $result): void
    {
        $stats['imported'] -= $result['skipped'] + $result['errors'];
        $stats['skipped']  += $result['skipped'];
        $stats['errors']   += $result['errors'];
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

        // Linha a linha (iterateKeyValue), sem montar antes uma matriz com a tabela inteira:
        // em produção são centenas de milhares de autores e palavras-chave. Mesma ordem e mesma
        // regra de antes (em nome repetido vale o último).
        foreach ($conn->iterateKeyValue('SELECT normalized_name, id FROM author_identity WHERE normalized_name IS NOT NULL') as $name => $id) {
            $this->authorCache[$name] = (int) $id;
        }

        foreach ($conn->iterateKeyValue("SELECT CONCAT(keyword_normalized, '|', keyword_type), id FROM keyword") as $cacheKey => $id) {
            $this->keywordCache[$cacheKey] = (int) $id;
        }

        $this->logger->info(sprintf(
            'Cache pre-loaded: %d authors, %d keywords',
            count($this->authorCache),
            count($this->keywordCache)
        ));
    }

    private function loadSet(string $sql, int $projectId): array
    {
        $set = [];
        foreach ($this->conn()->iterateNumeric($sql, [$projectId]) as [$val]) {
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
            // Termo inválido (vazio, só números etc.) é descartado; a tabela import_error foi removida.
            return null;
        }

        $displayName = $res['display'];
        $normKey     = self::cut($res['normalized'], 255);

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
                    'preferred_name'  => self::cut($displayName, 255),
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
                'original_name'      => self::cut(trim($name), 255),
                'display_name'       => self::cut($displayName, 255),
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
            // Termo inválido (vazio, só números etc.) é descartado; a tabela import_error foi removida.
            return null;
        }

        $displayName = $res['display'];
        $normKey     = self::cut($res['normalized'], 255);
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
                    'keyword_original'   => self::cut(trim($term), 255),
                    'keyword_display'    => self::cut($displayName, 255),
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
     * Resolve (com cache) os autores e as palavras-chave de um registro.
     *
     * @return array{0: list<array>, 1: list<array>}  vínculos de autor e de palavra-chave
     */
    private function resolveLinks(BibliographicRecordDTO $dto, string $now, int $projectId): array
    {
        $authorLinks = [];
        foreach ($dto->authorNames as $pos => $rawName) {
            $name = trim($rawName);
            if (!$name) continue;
            $authorId = $this->resolveAuthorId($name, $now, $projectId);
            if ($authorId) {
                $authorLinks[] = [
                    'author_id'     => $authorId,
                    'position'      => $pos,
                    'original_name' => self::cut($name, 255),
                ];
            }
        }

        $kwLinks = [];
        $usedKw  = [];
        foreach (['author' => $dto->authorKeywords, 'indexed' => $dto->indexedKeywords] as $type => $terms) {
            foreach ($terms as $term) {
                $norm = strtolower(trim($term));
                if (!$norm || isset($usedKw[$norm])) continue;
                $usedKw[$norm] = true;
                $id = $this->resolveKeywordId($term, $type, $now, $projectId);
                if ($id) {
                    $kwLinks[] = [
                        'keyword_id' => $id,
                        'original_term' => self::cut(trim($term), 255),
                    ];
                }
            }
        }

        return [$authorLinks, $kwLinks];
    }

    /** Tira do cache de IDs as entradas usadas pelo registro (o ID pode ter sumido por fusão em outro processo). */
    private function forgetCachedIds(BibliographicRecordDTO $dto): void
    {
        $normalizer = $this->textNormalizer();
        foreach ($dto->authorNames as $rawName) {
            $res = $normalizer->normalizeAuthor(trim($rawName));
            if ($res['valid']) {
                unset($this->authorCache[self::cut($res['normalized'], 255)]);
            }
        }
        foreach (['author_keyword' => $dto->authorKeywords, 'indexed_keyword' => $dto->indexedKeywords] as $type => $terms) {
            foreach ($terms as $term) {
                $res = $normalizer->normalizeKeyword($term);
                if ($res['valid']) {
                    unset($this->keywordCache[self::cut($res['normalized'], 255) . '|' . $type]);
                }
            }
        }
    }

    /**
     * Grava um lote de documentos com seus vínculos.
     *
     * Caminho rápido: uma transação para o lote inteiro, um INSERT por documento (precisa do ID)
     * e INSERTs de várias linhas para os vínculos. Se qualquer coisa falhar (duplicidade que o
     * cache em memória não viu, ID velho no cache...), desfaz o lote e o refaz documento a
     * documento, que é onde duplicados viram "ignorados" e falhas viram "erros".
     *
     * @param list<array>                  $docs
     * @param list<list<array>>            $authorLinks
     * @param list<list<array>>            $kwLinks
     * @param list<BibliographicRecordDTO> $dtos
     * @return array{inserted: int, skipped: int, errors: int}
     */
    private function flushBatch(array $docs, array $authorLinks, array $kwLinks, array $dtos, string $now, int $projectId): array
    {
        $conn = $this->conn();

        $conn->beginTransaction();
        try {
            [$effA, $effK] = $this->effectiveMaps($authorLinks, $kwLinks, $projectId);
            $authorRows = [];
            $kwRows = [];
            foreach ($docs as $i => $docRow) {
                $docId = $this->insertDocumentRow($docRow);
                foreach ($authorLinks[$i] as $link) {
                    $authorRows[] = [$docId, $link['author_id'], $link['position'], $link['original_name'], $effA[$link['author_id']] ?? $link['author_id']];
                }
                foreach ($kwLinks[$i] as $link) {
                    $kwRows[] = [$docId, $link['keyword_id'], $link['original_term'], $effK[$link['keyword_id']] ?? $link['keyword_id']];
                }
            }
            $this->insertRows('document_author', ['document_id', 'author_identity_id', 'position', 'original_name', 'effective_author_id'], $authorRows);
            $this->insertRows('document_keyword', ['document_id', 'keyword_id', 'original_term', 'effective_keyword_id'], $kwRows);
            $conn->commit();

            return ['inserted' => count($docs), 'skipped' => 0, 'errors' => 0];
        } catch (\Throwable $e) {
            try {
                $conn->rollBack();
            } catch (\Throwable) {}
            if ($e instanceof ConnectionException) {
                throw $e;
            }
        }

        // Refaz linha a linha. A tentativa em lote não deixou nada gravado (rollback).
        return $this->flushBatchRowByRow($docs, $authorLinks, $kwLinks, $dtos, $now, $projectId);
    }

    private function flushBatchRowByRow(array $docs, array $authorLinks, array $kwLinks, array $dtos, string $now, int $projectId): array
    {
        $conn = $this->conn();
        $inserted = 0;
        $skipped  = 0;
        $errors   = 0;

        foreach ($docs as $i => $docRow) {
            $retried = false;
            while (true) {
                $conn->beginTransaction();
                try {
                    $docId = $this->insertDocumentRow($docRow);
                    [$effA, $effK] = $this->effectiveMaps([$authorLinks[$i]], [$kwLinks[$i]], $projectId);
                    $this->insertRows('document_author', ['document_id', 'author_identity_id', 'position', 'original_name', 'effective_author_id'],
                        array_map(fn ($l) => [$docId, $l['author_id'], $l['position'], $l['original_name'], $effA[$l['author_id']] ?? $l['author_id']], $authorLinks[$i]));
                    $this->insertRows('document_keyword', ['document_id', 'keyword_id', 'original_term', 'effective_keyword_id'],
                        array_map(fn ($l) => [$docId, $l['keyword_id'], $l['original_term'], $effK[$l['keyword_id']] ?? $l['keyword_id']], $kwLinks[$i]));
                    $conn->commit();
                    $inserted++;
                } catch (ForeignKeyConstraintViolationException $e) {
                    try {
                        $conn->rollBack();
                    } catch (\Throwable) {}
                    if (!$retried) {
                        // ID de autor/palavra-chave do cache não existe mais (fusão em outro processo):
                        // limpa as entradas deste registro, resolve de novo e tenta uma vez mais.
                        $retried = true;
                        $this->forgetCachedIds($dtos[$i]);
                        [$authorLinks[$i], $kwLinks[$i]] = $this->resolveLinks($dtos[$i], $now, $projectId);
                        continue;
                    }
                    $this->logger->warning('Batch insert failed: ' . $e->getMessage(), ['hash' => $docRow['hash'] ?? '?']);
                    $errors++;
                } catch (UniqueConstraintViolationException) {
                    // Record already exists in this project (db-level safety net)
                    $conn->rollBack();
                    $skipped++;
                    $this->recordDbConstraintSkip($docRow, $now);
                } catch (\Throwable $e) {
                    try {
                        $conn->rollBack();
                    } catch (\Throwable) {}
                    if ($e instanceof ConnectionException) {
                        throw $e;
                    }
                    $this->logger->warning('Batch insert failed: ' . $e->getMessage(), ['hash' => $docRow['hash'] ?? '?']);
                    // Falha de gravação é erro, não duplicado
                    $errors++;
                }
                break;
            }
        }

        return ['inserted' => $inserted, 'skipped' => $skipped, 'errors' => $errors];
    }

    /** INSERT de um documento; devolve o ID gerado. */
    private function insertDocumentRow(array $docRow): int
    {
        $columns = [];
        $placeholders = [];
        $params = [];
        foreach ($docRow as $col => $val) {
            $columns[] = '`' . $col . '`';
            $placeholders[] = '?';
            $params[] = $val;
        }
        $conn = $this->conn();
        $conn->executeStatement(
            sprintf('INSERT INTO document (%s) VALUES (%s)', implode(', ', $columns), implode(', ', $placeholders)),
            $params
        );

        return (int) $conn->lastInsertId();
    }

    /** INSERT de várias linhas, em blocos (limite de parâmetros do MySQL). */
    private function insertRows(string $table, array $columns, array $rows): void
    {
        if (!$rows) {
            return;
        }
        $cols = '`' . implode('`, `', $columns) . '`';
        $tuple = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        foreach (array_chunk($rows, 1000) as $chunk) {
            $params = [];
            foreach ($chunk as $row) {
                foreach ($row as $v) {
                    $params[] = $v;
                }
            }
            $this->conn()->executeStatement(
                sprintf('INSERT INTO `%s` (%s) VALUES %s', $table, $cols, implode(', ', array_fill(0, count($chunk), $tuple))),
                $params
            );
        }
    }

    private function recordDbConstraintSkip(array $docRow, string $now): void
    {
        $conn = $this->conn();
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
                'title'               => self::cut($docRow['title'] ?? 'Sem título', 1000),
                'doi'                 => $docRow['doi'] ?? null,
                'hash'               => $docRow['hash'] ?? null,
                'reason'              => 'db_constraint',
                'matched_document_id' => $matchedDocId ? (int) $matchedDocId : null,
                'created_at'          => $now,
            ]);
        } catch (\Throwable) {}
    }

    private function loadMap(string $sql, int $projectId): array
    {
        $map = [];
        foreach ($this->conn()->iterateKeyValue($sql, [$projectId]) as $key => $id) {
            if ($key !== null) {
                $map[$key] = (int) $id;
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
                    'title'               => self::cut($skip['title'], 1000),
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
            'external_id'       => $dto->externalId       ? self::cut($dto->externalId, 100) : null,
            'title'             => $dto->title,
            'normalized_title'  => $dto->title             ? strtolower(trim($dto->title))          : null,
            'abstract_text'     => $dto->abstractText,
            'year'              => $dto->year,
            'document_type'     => $dto->documentType      ? self::cut($dto->documentType, 50)  : null,
            'doi'               => $dto->doi               ? self::cut($dto->doi, 255) : null,
            'pmid'              => $dto->pmid              ? self::cut($dto->pmid, 50)  : null,
            'isbn'              => $dto->isbn              ? self::cut($dto->isbn, 50)  : null,
            'issn'              => $dto->issn              ? self::cut($dto->issn, 50)  : null,
            'url'               => ($url = TextNormalizer::safeUrl($dto->url)) ? self::cut($url, 500) : null,
            'language'          => $dto->language          ? self::cut($dto->language, 10)  : null,
            'source_title'      => $dto->sourceTitle       ? self::cut($dto->sourceTitle, 500) : null,
            'volume'            => $dto->volume            ? self::cut($dto->volume, 20)  : null,
            'issue'             => $dto->issue             ? self::cut($dto->issue, 20)  : null,
            'page_start'        => $dto->pageStart         ? self::cut($dto->pageStart, 30)  : null,
            'page_end'          => $dto->pageEnd           ? self::cut($dto->pageEnd, 30)  : null,
            'publisher'         => $dto->publisher         ? self::cut($dto->publisher, 255) : null,
            'cited_by'          => $dto->citedBy,
            'local_citations'   => null,
            'open_access_status'=> $dto->openAccessStatus  ? self::cut($dto->openAccessStatus, 100) : null,
            'publication_stage' => $dto->publicationStage  ? self::cut($dto->publicationStage, 50)  : null,
            'hash'              => $hash,
            'countries'         => $dto->countries ? json_encode(array_values($dto->countries), JSON_UNESCAPED_UNICODE) : null,
            'institutions'      => $dto->institutions ? json_encode(array_values($dto->institutions), JSON_UNESCAPED_UNICODE) : null,
            'references'        => $dto->references ? json_encode(array_values($dto->references), JSON_UNESCAPED_UNICODE) : null,
            'created_at'        => $now,
        ];
    }

    /**
     * Hash de deduplicação. Com DOI ou com título + ano o valor é o de sempre (não pode mudar:
     * há documentos gravados com ele). Sem isso, usa a base + o identificador externo e,
     * por último, o título normalizado, para esses registros também não entrarem em duplicata.
     */
    public static function computeHash(BibliographicRecordDTO $dto): ?string
    {
        if ($dto->doi) {
            return md5('doi:' . strtolower(trim($dto->doi)));
        }
        if ($dto->title && $dto->year) {
            $normalized = preg_replace('/\s+/', ' ', strtolower(trim($dto->title)));
            return md5($normalized . ':' . $dto->year);
        }
        if ($dto->externalId !== null && trim($dto->externalId) !== '') {
            return md5('ext:' . strtolower(trim($dto->source)) . ':' . trim($dto->externalId));
        }
        if ($dto->title && trim($dto->title) !== '') {
            return md5('title:' . preg_replace('/\s+/', ' ', strtolower(trim($dto->title))));
        }
        return null;
    }

    /** Corta em bytes sem partir um caractere UTF-8 ao meio (substr deixava bytes inválidos no fim). */
    public static function cut(string $value, int $bytes): string
    {
        return mb_strcut($value, 0, $bytes, 'UTF-8');
    }

    private function parseName(string $name): array
    {
        if (str_contains($name, ',')) {
            $parts = explode(',', $name, 2);
            return [trim($parts[0]), trim($parts[1] ?? '')];
        }
        $parts   = explode(' ', trim($name));
        $surname = array_pop($parts);
        $initials = implode('', array_map(fn($p) => strtoupper(self::cut($p, 1)) . '.', $parts));
        return [$surname, $initials];
    }
}
