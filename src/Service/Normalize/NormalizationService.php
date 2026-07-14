<?php

namespace App\Service\Normalize;

use Doctrine\ORM\EntityManagerInterface;

class NormalizationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    // ── 1. Author Normalization Heuristics ──────────────────────────────────────

    /**
     * Find similar author suggestions.
     * Uses a fast grouping heuristic (by surname first letter + initial of first name)
     * to avoid O(N^2) comparison.
     */
    public function findSimilarAuthors(int $projectId, float $minSimilarity = 0.85): array
    {
        $conn = $this->em->getConnection();

        // Get all authors associated with this project
        $authors = $conn->fetchAllAssociative('
            SELECT 
                a.id, 
                a.preferred_name AS name, 
                a.normalized_name, 
                COUNT(da.document_id) AS doc_count
            FROM author_identity a
            JOIN document_author da ON a.id = da.author_identity_id
            JOIN document d ON da.document_id = d.id
            WHERE d.project_id = ?
            GROUP BY a.id, a.preferred_name, a.normalized_name
            HAVING doc_count > 0
            ORDER BY a.preferred_name ASC
        ', [$projectId]);

        $groups = [];
        foreach ($authors as $auth) {
            $name = $auth['name'];
            
            // Extract pseudo-surname and first letter of first name by splitting the name
            $parts = array_filter(explode(' ', trim($name)));
            $surname = strtolower(end($parts) ?: '');
            $firstLetter = strtolower(substr(reset($parts) ?: '', 0, 1));

            $key = substr($surname, 0, 4) . '|' . $firstLetter;
            $groups[$key][] = $auth;
        }

        $suggestions = [];
        foreach ($groups as $key => $group) {
            $count = count($group);
            if ($count < 2) continue;

            // Compare inside the small group
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $a1 = $group[$i];
                    $a2 = $group[$j];

                    // Check similarity
                    $sim = $this->calculateSimilarity($a1['name'], $a2['name']);
                    if ($sim >= $minSimilarity) {
                        // Order so the one with more documents is preferred to keep
                        $keep = $a1['doc_count'] >= $a2['doc_count'] ? $a1 : $a2;
                        $discard = $keep['id'] === $a1['id'] ? $a2 : $a1;

                        $suggestions[] = [
                            'keep'       => $keep,
                            'discard'    => $discard,
                            'similarity' => round($sim * 100, 1),
                        ];
                    }
                }
            }
        }

        // Sort by similarity desc
        usort($suggestions, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

        return array_slice($suggestions, 0, 5000);
    }

    /**
     * Execute author merge.
     */
    public function mergeAuthors(int $keepId, int $discardId): void
    {
        $conn = $this->em->getConnection();
        $conn->beginTransaction();

        try {
            // Find all documents of the discarded author
            $docAuthors = $conn->fetchAllAssociative(
                'SELECT document_id, position, original_name FROM document_author WHERE author_identity_id = ?',
                [$discardId]
            );

            foreach ($docAuthors as $da) {
                $docId = $da['document_id'];

                // Check if the kept author is already associated with this document
                $exists = $conn->fetchOne(
                    'SELECT id FROM document_author WHERE document_id = ? AND author_identity_id = ?',
                    [$docId, $keepId]
                );

                if ($exists) {
                    // Both exist on the same document: delete the discarded one's link
                    $conn->executeStatement(
                        'DELETE FROM document_author WHERE document_id = ? AND author_identity_id = ?',
                        [$docId, $discardId]
                    );
                } else {
                    // Update discarded link to kept author
                    $conn->executeStatement(
                        'UPDATE document_author SET author_identity_id = ? WHERE document_id = ? AND author_identity_id = ?',
                        [$keepId, $docId, $discardId]
                    );
                }
            }

            // Create variation for the kept author with the name of the discarded author
            $discardName = $conn->fetchOne('SELECT preferred_name FROM author_identity WHERE id = ?', [$discardId]);
            if ($discardName) {
                $norm = \App\Service\Import\DocumentEnrichmentService::normalize($discardName);
                $existsVar = $conn->fetchOne(
                    'SELECT id FROM author_name_variant WHERE author_identity_id = ? AND normalized_name = ?',
                    [$keepId, $norm]
                );
                $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
                if (!$existsVar) {
                    $conn->insert('author_name_variant', [
                        'author_identity_id' => $keepId,
                        'original_name'      => $discardName,
                        'display_name'       => $discardName,
                        'normalized_name'    => $norm,
                        'source'             => 'alternative',
                        'confidence'         => 1.0,
                        'created_at'         => $now,
                        'updated_at'         => $now,
                    ]);
                }
            }

            // Move variations of the discarded author to point to the kept author
            $discardVars = $conn->fetchAllAssociative(
                'SELECT id, original_name AS variation_name, normalized_name FROM author_name_variant WHERE author_identity_id = ?',
                [$discardId]
            );
            foreach ($discardVars as $v) {
                $existsVar = $conn->fetchOne(
                    'SELECT id FROM author_name_variant WHERE author_identity_id = ? AND normalized_name = ?',
                    [$keepId, $v['normalized_name']]
                );
                if (!$existsVar) {
                    $conn->executeStatement(
                        'UPDATE author_name_variant SET author_identity_id = ? WHERE id = ?',
                        [$keepId, $v['id']]
                    );
                } else {
                    $conn->executeStatement(
                        'DELETE FROM author_name_variant WHERE id = ?',
                        [$v['id']]
                    );
                }
            }

            // Finally, delete the discarded author
            $conn->executeStatement('DELETE FROM author_identity WHERE id = ?', [$discardId]);

            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    /**
     * Batch merge authors — all pairs in one transaction, with chain resolution.
     */
    public function mergeAuthorsBatch(array $pairs): int
    {
        $conn   = $this->em->getConnection();
        $merged = 0;
        $conn->beginTransaction();

        try {
            // Resolve transitive chains
            $discardToKeep = [];
            foreach ($pairs as $pair) {
                $k = (int)($pair['keepId']    ?? 0);
                $d = (int)($pair['discardId'] ?? 0);
                if ($k > 0 && $d > 0 && $k !== $d) {
                    $discardToKeep[$d] = $k;
                }
            }

            $changed = true;
            while ($changed) {
                $changed = false;
                foreach ($discardToKeep as $d => &$k) {
                    if (isset($discardToKeep[$k]) && $discardToKeep[$k] !== $d) {
                        $k       = $discardToKeep[$k];
                        $changed = true;
                    }
                }
                unset($k);
            }

            $seen = [];

            foreach ($pairs as $pair) {
                $rawKeepId = (int)($pair['keepId']    ?? 0);
                $discardId = (int)($pair['discardId'] ?? 0);

                if ($rawKeepId <= 0 || $discardId <= 0 || $rawKeepId === $discardId) {
                    continue;
                }
                if (isset($seen[$discardId])) {
                    continue;
                }

                $keepId = $discardToKeep[$rawKeepId] ?? $rawKeepId;

                if ($keepId === $discardId) {
                    continue;
                }

                $seen[$discardId] = true;

                $exists = $conn->fetchOne('SELECT COUNT(*) FROM author_identity WHERE id = ?', [$discardId]);
                if (!$exists) {
                    continue;
                }

                // 1. Remove conflicting document_author rows
                $conn->executeStatement(
                    'DELETE FROM document_author
                     WHERE author_identity_id = ?
                       AND document_id IN (
                           SELECT document_id FROM (
                               SELECT document_id FROM document_author WHERE author_identity_id = ?
                           ) AS _tmp
                       )',
                     [$discardId, $keepId]
                 );

                // 2. Remap remaining rows
                $conn->executeStatement(
                    'UPDATE document_author SET author_identity_id = ? WHERE author_identity_id = ?',
                    [$keepId, $discardId]
                );

                // Fetch discard name and migrate to variation
                $discardName = $conn->fetchOne('SELECT preferred_name FROM author_identity WHERE id = ?', [$discardId]);
                $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
                if ($discardName) {
                    $norm = \App\Service\Import\DocumentEnrichmentService::normalize($discardName);
                    $existsVar = $conn->fetchOne(
                        'SELECT id FROM author_name_variant WHERE author_identity_id = ? AND normalized_name = ?',
                        [$keepId, $norm]
                    );
                    if (!$existsVar) {
                        $conn->insert('author_name_variant', [
                            'author_identity_id' => $keepId,
                            'original_name'      => $discardName,
                            'display_name'       => $discardName,
                            'normalized_name'    => $norm,
                            'source'             => 'alternative',
                            'confidence'         => 1.0,
                            'created_at'         => $now,
                            'updated_at'         => $now,
                        ]);
                    }
                }

                // Move variations of the discarded author to point to the kept author
                $discardVars = $conn->fetchAllAssociative(
                    'SELECT id, original_name AS variation_name, normalized_name FROM author_name_variant WHERE author_identity_id = ?',
                    [$discardId]
                );
                foreach ($discardVars as $v) {
                    $existsVar = $conn->fetchOne(
                        'SELECT id FROM author_name_variant WHERE author_identity_id = ? AND normalized_name = ?',
                        [$keepId, $v['normalized_name']]
                    );
                    if (!$existsVar) {
                        $conn->executeStatement(
                            'UPDATE author_name_variant SET author_identity_id = ? WHERE id = ?',
                            [$keepId, $v['id']]
                        );
                    } else {
                        $conn->executeStatement(
                            'DELETE FROM author_name_variant WHERE id = ?',
                            [$v['id']]
                        );
                    }
                }

                // 3. Delete the discarded author record
                $conn->executeStatement('DELETE FROM author_identity WHERE id = ?', [$discardId]);

                $merged++;
            }

            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }

        return $merged;
    }

    // ── 2. Keyword Normalization Heuristics ─────────────────────────────────────

    /**
     * Find similar keywords.
     */
    public function findSimilarKeywords(int $projectId, string $type = 'author', float $minSimilarity = 0.85): array
    {
        $conn = $this->em->getConnection();

        $mappedType = $type === 'author' ? 'author_keyword' : ($type === 'indexed' ? 'indexed_keyword' : $type);

        // Fetch keywords in the project (only the selected type and not mapped to thesaurus)
        $keywords = $conn->fetchAllAssociative('
            SELECT
                k.id,
                k.keyword_display AS term,
                COUNT(dk.document_id) AS doc_count
            FROM keyword k
            JOIN document_keyword dk ON k.id = dk.keyword_id
            JOIN document d ON dk.document_id = d.id
            WHERE d.project_id = ? AND k.keyword_type = ? AND k.thesaurus_concept_id IS NULL
            GROUP BY k.id, k.keyword_display
            HAVING doc_count > 0
            ORDER BY k.keyword_display ASC
        ', [$projectId, $mappedType]);

        // Group keywords by first 4 letters for efficient O(n) grouping
        $groups = [];
        foreach ($keywords as $kw) {
            $term = strtolower(trim($kw['term']));
            $key  = substr($term, 0, 4);
            $groups[$key][] = $kw;
        }

        $suggestions = [];
        foreach ($groups as $key => $group) {
            $count = count($group);
            if ($count < 2) continue;

            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $k1 = $group[$i];
                    $k2 = $group[$j];

                    $sim = $this->calculateSimilarity($k1['term'], $k2['term']);

                    $t1 = strtolower(trim($k1['term']));
                    $t2 = strtolower(trim($k2['term']));
                    $isPluralMatch = ($t1 . 's' === $t2) || ($t2 . 's' === $t1)
                                  || ($t1 . 'es' === $t2) || ($t2 . 'es' === $t1);

                    if ($sim >= $minSimilarity || $isPluralMatch) {
                        $keep    = $k1['doc_count'] >= $k2['doc_count'] ? $k1 : $k2;
                        $discard = $keep['id'] === $k1['id'] ? $k2 : $k1;

                        $suggestions[] = [
                            'keep'       => $keep,
                            'discard'    => $discard,
                            'similarity' => $isPluralMatch ? 100.0 : round($sim * 100, 1),
                            'match_type' => 'same_type',
                        ];
                    }
                }
            }
        }

        // ── Cross-type matches (same term in author AND indexed) ──────────────
        $crossType = $this->findCrossTypeKeywords($projectId, $type);
        $suggestions = array_merge($crossType, $suggestions);

        usort($suggestions, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

        return array_slice($suggestions, 0, 5000);
    }

    /**
     * Find keywords that exist as BOTH author-type AND indexed-type with the same (or very similar) term.
     * @param string $currentType Which type is currently selected — returned as the 'keep' candidate.
     */
    public function findCrossTypeKeywords(int $projectId, string $currentType = 'author'): array
    {
        $conn      = $this->em->getConnection();
        $otherType = $currentType === 'author' ? 'indexed' : 'author';

        $mappedCurrentType = $currentType === 'author' ? 'author_keyword' : ($currentType === 'indexed' ? 'indexed_keyword' : $currentType);
        $mappedOtherType   = $otherType === 'author' ? 'author_keyword' : ($otherType === 'indexed' ? 'indexed_keyword' : $otherType);

        $current = $conn->fetchAllAssociative(
            'SELECT k.id, k.keyword_display AS term,
                    CASE WHEN k.keyword_type = \'author_keyword\' THEN \'author\' WHEN k.keyword_type = \'indexed_keyword\' THEN \'indexed\' ELSE k.keyword_type END AS type,
                    COUNT(dk.document_id) AS doc_count
             FROM keyword k
             JOIN document_keyword dk ON k.id = dk.keyword_id
             JOIN document d ON dk.document_id = d.id
             WHERE d.project_id = ? AND k.keyword_type = ? AND k.thesaurus_concept_id IS NULL
             GROUP BY k.id, k.keyword_display, k.keyword_type
             HAVING doc_count > 0
             ORDER BY doc_count DESC',
            [$projectId, $mappedCurrentType]
        );

        $other = $conn->fetchAllAssociative(
            'SELECT k.id, k.keyword_display AS term,
                    CASE WHEN k.keyword_type = \'author_keyword\' THEN \'author\' WHEN k.keyword_type = \'indexed_keyword\' THEN \'indexed\' ELSE k.keyword_type END AS type,
                    COUNT(dk.document_id) AS doc_count
             FROM keyword k
             JOIN document_keyword dk ON k.id = dk.keyword_id
             JOIN document d ON dk.document_id = d.id
             WHERE d.project_id = ? AND k.keyword_type = ? AND k.thesaurus_concept_id IS NULL
             GROUP BY k.id, k.keyword_display, k.keyword_type
             HAVING doc_count > 0',
            [$projectId, $mappedOtherType]
        );

        // Index other-type by normalized term
        $otherByTerm = [];
        foreach ($other as $kw) {
            $normalized = strtolower(trim($kw['term']));
            $otherByTerm[$normalized][] = $kw;
        }

        $suggestions = [];
        foreach ($current as $kw) {
            $normalized = strtolower(trim($kw['term']));
            if (isset($otherByTerm[$normalized])) {
                foreach ($otherByTerm[$normalized] as $match) {
                    $keep    = $kw['doc_count'] >= $match['doc_count'] ? $kw : $match;
                    $discard = $keep['id'] === $kw['id'] ? $match : $kw;

                    $suggestions[] = [
                        'keep'       => $keep,
                        'discard'    => $discard,
                        'similarity' => 100.0,
                        'match_type' => 'cross_type',
                        'cross_note' => 'Mesmo termo em tipos diferentes (Autor vs Indexada)',
                    ];
                }
            }
        }

        return $suggestions;
    }

    /**
     * Return ALL keywords for a project (for the full list view).
     */
    public function getAllKeywords(int $projectId, string $type = '', string $search = '', int $limit = 500): array
    {
        $conn = $this->em->getConnection();

        $where  = 'd.project_id = ?';
        $params = [$projectId];

        if ($type !== '') {
            $mappedType = $type === 'author' ? 'author_keyword' : ($type === 'indexed' ? 'indexed_keyword' : $type);
            $where   .= ' AND k.keyword_type = ?';
            $params[] = $mappedType;
        }
        if ($search !== '') {
            $where   .= ' AND k.keyword_display LIKE ?';
            $params[] = '%' . $search . '%';
        }

        return $conn->fetchAllAssociative(
            "SELECT k.id, k.keyword_display AS term,
                    CASE WHEN k.keyword_type = 'author_keyword' THEN 'author' WHEN k.keyword_type = 'indexed_keyword' THEN 'indexed' ELSE k.keyword_type END AS type,
                    COUNT(dk.document_id) AS doc_count
             FROM keyword k
             JOIN document_keyword dk ON k.id = dk.keyword_id
             JOIN document d ON dk.document_id = d.id
             WHERE {$where}
             GROUP BY k.id, k.keyword_display, k.keyword_type
             HAVING doc_count > 0
             ORDER BY doc_count DESC
             LIMIT {$limit}",
            $params
        );
    }

    /**
     * Execute keyword merge.
     */
    public function mergeKeywords(int $keepId, int $discardId): void
    {
        $conn = $this->em->getConnection();
        $conn->beginTransaction();

        try {
            $docKeywords = $conn->fetchAllAssociative(
                'SELECT document_id FROM document_keyword WHERE keyword_id = ?',
                [$discardId]
            );

            foreach ($docKeywords as $dk) {
                $docId = $dk['document_id'];

                $exists = $conn->fetchOne(
                    'SELECT id FROM document_keyword WHERE document_id = ? AND keyword_id = ?',
                    [$docId, $keepId]
                );

                if ($exists) {
                    $conn->executeStatement(
                        'DELETE FROM document_keyword WHERE document_id = ? AND keyword_id = ?',
                        [$docId, $discardId]
                    );
                } else {
                    $conn->executeStatement(
                        'UPDATE document_keyword SET keyword_id = ? WHERE document_id = ? AND keyword_id = ?',
                        [$keepId, $docId, $discardId]
                    );
                }
            }

            // Create variation for the kept keyword with the term of the discarded keyword
            $discardTerm = $conn->fetchOne('SELECT keyword_original FROM keyword WHERE id = ?', [$discardId]);
            if ($discardTerm) {
                $norm = \App\Service\Import\DocumentEnrichmentService::normalize($discardTerm);
                $existsVar = $conn->fetchOne(
                    'SELECT id FROM palavra_chave_variacoes_nome WHERE keyword_id = ? AND normalized_name = ?',
                    [$keepId, $norm]
                );
                if (!$existsVar) {
                    $conn->insert('palavra_chave_variacoes_nome', [
                        'keyword_id' => $keepId,
                        'variation_name' => $discardTerm,
                        'normalized_name' => $norm,
                        'variation_type' => 'alternative',
                        'status' => 1
                    ]);
                }
            }

            // Move variations of the discarded keyword to point to the kept keyword
            $discardVars = $conn->fetchAllAssociative(
                'SELECT id, variation_name, normalized_name, variation_type FROM palavra_chave_variacoes_nome WHERE keyword_id = ?',
                [$discardId]
            );
            foreach ($discardVars as $v) {
                $existsVar = $conn->fetchOne(
                    'SELECT id FROM palavra_chave_variacoes_nome WHERE keyword_id = ? AND normalized_name = ?',
                    [$keepId, $v['normalized_name']]
                );
                if (!$existsVar) {
                    $conn->executeStatement(
                        'UPDATE palavra_chave_variacoes_nome SET keyword_id = ? WHERE id = ?',
                        [$keepId, $v['id']]
                    );
                } else {
                    $conn->executeStatement(
                        'DELETE FROM palavra_chave_variacoes_nome WHERE id = ?',
                        [$v['id']]
                    );
                }
            }

            $conn->executeStatement('DELETE FROM keyword WHERE id = ?', [$discardId]);

            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    /**
     * Batch merge keywords — all pairs in one transaction, 2 SQL statements per pair.
     */
    public function mergeKeywordsBatch(array $pairs): int
    {
        $conn   = $this->em->getConnection();
        $merged = 0;
        $conn->beginTransaction();

        try {
            $discardToKeep = [];
            foreach ($pairs as $pair) {
                $k = (int)($pair['keepId']    ?? 0);
                $d = (int)($pair['discardId'] ?? 0);
                if ($k > 0 && $d > 0 && $k !== $d) {
                    $discardToKeep[$d] = $k;
                }
            }

            $changed = true;
            while ($changed) {
                $changed = false;
                foreach ($discardToKeep as $d => &$k) {
                    if (isset($discardToKeep[$k]) && $discardToKeep[$k] !== $d) {
                        $k       = $discardToKeep[$k];
                        $changed = true;
                    }
                }
                unset($k);
            }

            $seen = [];

            foreach ($pairs as $pair) {
                $rawKeepId = (int)($pair['keepId']    ?? 0);
                $discardId = (int)($pair['discardId'] ?? 0);

                if ($rawKeepId <= 0 || $discardId <= 0 || $rawKeepId === $discardId) {
                    continue;
                }
                if (isset($seen[$discardId])) {
                    continue;
                }

                $keepId = $discardToKeep[$rawKeepId] ?? $rawKeepId;

                if ($keepId === $discardId) {
                    continue;
                }

                $seen[$discardId] = true;

                $exists = $conn->fetchOne('SELECT COUNT(*) FROM keyword WHERE id = ?', [$discardId]);
                if (!$exists) {
                    continue;
                }

                // 1. Remove conflicting rows
                $conn->executeStatement(
                    'DELETE FROM document_keyword
                     WHERE keyword_id = ?
                       AND document_id IN (
                           SELECT document_id FROM (
                               SELECT document_id FROM document_keyword WHERE keyword_id = ?
                           ) AS _tmp
                       )',
                    [$discardId, $keepId]
                );

                // 2. Remap the remaining document_keyword rows to keepId
                $conn->executeStatement(
                    'UPDATE document_keyword SET keyword_id = ? WHERE keyword_id = ?',
                    [$keepId, $discardId]
                );

                // Fetch discard term and migrate to variation
                $discardTerm = $conn->fetchOne('SELECT keyword_original FROM keyword WHERE id = ?', [$discardId]);
                if ($discardTerm) {
                    $norm = \App\Service\Import\DocumentEnrichmentService::normalize($discardTerm);
                    $existsVar = $conn->fetchOne(
                        'SELECT id FROM palavra_chave_variacoes_nome WHERE keyword_id = ? AND normalized_name = ?',
                        [$keepId, $norm]
                    );
                    if (!$existsVar) {
                        $conn->insert('palavra_chave_variacoes_nome', [
                            'keyword_id' => $keepId,
                            'variation_name' => $discardTerm,
                            'normalized_name' => $norm,
                            'variation_type' => 'alternative',
                            'status' => 1
                        ]);
                    }
                }

                // Move variations of the discarded keyword to point to the kept keyword
                $discardVars = $conn->fetchAllAssociative(
                    'SELECT id, variation_name, normalized_name, variation_type FROM palavra_chave_variacoes_nome WHERE keyword_id = ?',
                    [$discardId]
                );
                foreach ($discardVars as $v) {
                    $existsVar = $conn->fetchOne(
                        'SELECT id FROM palavra_chave_variacoes_nome WHERE keyword_id = ? AND normalized_name = ?',
                        [$keepId, $v['normalized_name']]
                    );
                    if (!$existsVar) {
                        $conn->executeStatement(
                            'UPDATE palavra_chave_variacoes_nome SET keyword_id = ? WHERE id = ?',
                            [$keepId, $v['id']]
                        );
                    } else {
                        $conn->executeStatement(
                            'DELETE FROM palavra_chave_variacoes_nome WHERE id = ?',
                            [$v['id']]
                        );
                    }
                }

                // 3. Delete the now-orphaned keyword record
                $conn->executeStatement('DELETE FROM keyword WHERE id = ?', [$discardId]);

                $merged++;
            }

            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }

        return $merged;
    }

    // ── 3. Document Deduplication Heuristics ───────────────────────────────────

    /**
     * Find potential duplicate documents.
     */
    public function findPotentialDuplicates(int $projectId, float $minSimilarity = 0.85): array
    {
        $conn = $this->em->getConnection();

        $docs = $conn->fetchAllAssociative('
            SELECT 
                id, 
                title, 
                year, 
                source_title,
                doi, 
                cited_by
            FROM document
            WHERE project_id = ? AND year IS NOT NULL AND title IS NOT NULL
            ORDER BY year DESC, title ASC
        ', [$projectId]);

        // Group by year
        $groups = [];
        foreach ($docs as $doc) {
            $groups[$doc['year']][] = $doc;
        }

        $suggestions = [];
        foreach ($groups as $year => $group) {
            $count = count($group);
            if ($count < 2) continue;

            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $d1 = $group[$i];
                    $d2 = $group[$j];

                    $len1 = strlen($d1['title']);
                    $len2 = strlen($d2['title']);
                    if (abs($len1 - $len2) > max($len1, $len2) * (1 - $minSimilarity)) {
                        continue;
                    }

                    $sim = $this->calculateSimilarity($d1['title'], $d2['title']);
                    if ($sim >= $minSimilarity) {
                        $keep = ($d1['doi'] && !$d2['doi']) || ($d1['cited_by'] >= $d2['cited_by']) ? $d1 : $d2;
                        $discard = $keep['id'] === $d1['id'] ? $d2 : $d1;

                        $suggestions[] = [
                            'keep'       => $keep,
                            'discard'    => $discard,
                            'similarity' => round($sim * 100, 1),
                        ];
                    }
                }
            }
        }

        usort($suggestions, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

        return array_slice($suggestions, 0, 100);
    }

    /**
     * Execute document merge.
     */
    public function mergeDocuments(int $keepId, int $discardId): void
    {
        $conn = $this->em->getConnection();
        $conn->beginTransaction();

        try {
            // 1. Load both documents to merge properties
            $keepDoc = $conn->fetchAssociative('SELECT * FROM document WHERE id = ?', [$keepId]);
            $discardDoc = $conn->fetchAssociative('SELECT * FROM document WHERE id = ?', [$discardId]);

            if (!$keepDoc || !$discardDoc) {
                throw new \InvalidArgumentException('Documento não encontrado.');
            }

            // 2. Merge columns (take most complete fields)
            $updates = [];
            foreach ($keepDoc as $col => $val) {
                if ($col === 'id' || $col === 'created_at') continue;
                if ($val === null && $discardDoc[$col] !== null) {
                    $updates[$col] = $discardDoc[$col];
                }
            }

            // Cited by = MAX citation count
            $keepCitations = (int)($keepDoc['cited_by'] ?? 0);
            $discardCitations = (int)($discardDoc['cited_by'] ?? 0);
            if ($discardCitations > $keepCitations) {
                $updates['cited_by'] = $discardCitations;
            }

            if (!empty($updates)) {
                $conn->update('document', $updates, ['id' => $keepId]);
            }

            // 3. Move document authors
            $discardAuthors = $conn->fetchAllAssociative(
                'SELECT author_identity_id, position, original_name FROM document_author WHERE document_id = ?',
                [$discardId]
            );
            foreach ($discardAuthors as $da) {
                $exists = $conn->fetchOne(
                    'SELECT id FROM document_author WHERE document_id = ? AND author_identity_id = ?',
                    [$keepId, $da['author_identity_id']]
                );
                if (!$exists) {
                    $conn->insert('document_author', [
                        'document_id'        => $keepId,
                        'author_identity_id' => $da['author_identity_id'],
                        'position'           => $da['position'],
                        'original_name'      => $da['original_name'],
                    ]);
                }
            }
            $conn->executeStatement('DELETE FROM document_author WHERE document_id = ?', [$discardId]);

            // 4. Move document keywords
            $discardKws = $conn->fetchAllAssociative(
                'SELECT keyword_id FROM document_keyword WHERE document_id = ?',
                [$discardId]
            );
            foreach ($discardKws as $dk) {
                $exists = $conn->fetchOne(
                    'SELECT id FROM document_keyword WHERE document_id = ? AND keyword_id = ?',
                    [$keepId, $dk['keyword_id']]
                );
                if (!$exists) {
                    $conn->insert('document_keyword', [
                        'document_id' => $keepId,
                        'keyword_id'  => $dk['keyword_id']
                    ]);
                }
            }
            $conn->executeStatement('DELETE FROM document_keyword WHERE document_id = ?', [$discardId]);

            // 5. Delete discarded document
            $conn->executeStatement('DELETE FROM document WHERE id = ?', [$discardId]);

            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    // ── Helper: String Similarity Heuristic ───────────────────────────────────

    private function calculateSimilarity(string $s1, string $s2): float
    {
        $s1 = strtolower(trim($s1));
        $s2 = strtolower(trim($s2));

        if ($s1 === $s2) return 1.0;

        $clean1 = preg_replace('/[^a-z0-9 ]/', '', $s1);
        $clean2 = preg_replace('/[^a-z0-9 ]/', '', $s2);
        if ($clean1 === $clean2) return 0.98;

        $len1 = strlen($s1);
        $len2 = strlen($s2);
        $maxLen = max($len1, $len2);

        if ($maxLen === 0) return 1.0;

        if ($maxLen > 255) {
            similar_text($s1, $s2, $percent);
            return $percent / 100;
        }

        $lev = levenshtein($s1, $s2);
        return 1 - ($lev / $maxLen);
    }
}
