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
                a.name, 
                a.surname, 
                a.initials,
                COUNT(da.document_id) AS doc_count
            FROM author a
            JOIN document_author da ON a.id = da.author_id
            JOIN document d ON da.document_id = d.id
            WHERE d.project_id = ?
            GROUP BY a.id, a.name, a.surname, a.initials
            HAVING doc_count > 0
            ORDER BY a.name ASC
        ', [$projectId]);

        $groups = [];
        foreach ($authors as $auth) {
            $name = $auth['name'];
            $surname = strtolower(trim($auth['surname'] ?? ''));
            
            // Generate a grouping key: e.g., first 3 letters of surname + first letter of first initial
            $firstLetter = '';
            if ($auth['initials']) {
                $firstLetter = strtolower(substr(trim($auth['initials']), 0, 1));
            } elseif (str_contains($name, ' ')) {
                $firstLetter = strtolower(substr(trim(explode(' ', $name)[0]), 0, 1));
            }

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

        return array_slice($suggestions, 0, 150); // limit to top suggestions for performance and UI
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
                'SELECT document_id, position, original_name FROM document_author WHERE author_id = ?',
                [$discardId]
            );

            foreach ($docAuthors as $da) {
                $docId = $da['document_id'];

                // Check if the kept author is already associated with this document
                $exists = $conn->fetchOne(
                    'SELECT id FROM document_author WHERE document_id = ? AND author_id = ?',
                    [$docId, $keepId]
                );

                if ($exists) {
                    // Both exist on the same document: delete the discarded one's link
                    $conn->executeStatement(
                        'DELETE FROM document_author WHERE document_id = ? AND author_id = ?',
                        [$docId, $discardId]
                    );
                } else {
                    // Update discarded link to kept author
                    $conn->executeStatement(
                        'UPDATE document_author SET author_id = ? WHERE document_id = ? AND author_id = ?',
                        [$keepId, $docId, $discardId]
                    );
                }
            }

            // Finally, delete the discarded author
            $conn->executeStatement('DELETE FROM author WHERE id = ?', [$discardId]);

            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    // ── 2. Keyword Normalization Heuristics ─────────────────────────────────────

    /**
     * Find similar keywords.
     */
    public function findSimilarKeywords(int $projectId, string $type = 'author', float $minSimilarity = 0.85): array
    {
        $conn = $this->em->getConnection();

        // Fetch keywords in the project (only the selected type)
        $keywords = $conn->fetchAllAssociative('
            SELECT
                k.id,
                k.term,
                COUNT(dk.document_id) AS doc_count
            FROM keyword k
            JOIN document_keyword dk ON k.id = dk.keyword_id
            JOIN document d ON dk.document_id = d.id
            WHERE d.project_id = ? AND k.type = ?
            GROUP BY k.id, k.term
            HAVING doc_count > 0
            ORDER BY k.term ASC
        ', [$projectId, $type]);

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

        return array_slice($suggestions, 0, 200);
    }

    /**
     * Find keywords that exist as BOTH author-type AND indexed-type with the same (or very similar) term.
     * e.g. "Artificial intelligence" (author, 5518) vs "Artificial Intelligence" (indexed, 4178)
     * @param string $type Which type is currently selected — returned as the 'keep' candidate.
     */
    public function findCrossTypeKeywords(int $projectId, string $currentType = 'author'): array
    {
        $conn      = $this->em->getConnection();
        $otherType = $currentType === 'author' ? 'indexed' : 'author';

        $current = $conn->fetchAllAssociative(
            'SELECT k.id, k.term, k.type, COUNT(dk.document_id) AS doc_count
             FROM keyword k
             JOIN document_keyword dk ON k.id = dk.keyword_id
             JOIN document d ON dk.document_id = d.id
             WHERE d.project_id = ? AND k.type = ?
             GROUP BY k.id, k.term, k.type
             HAVING doc_count > 0
             ORDER BY doc_count DESC',
            [$projectId, $currentType]
        );

        $other = $conn->fetchAllAssociative(
            'SELECT k.id, k.term, k.type, COUNT(dk.document_id) AS doc_count
             FROM keyword k
             JOIN document_keyword dk ON k.id = dk.keyword_id
             JOIN document d ON dk.document_id = d.id
             WHERE d.project_id = ? AND k.type = ?
             GROUP BY k.id, k.term, k.type
             HAVING doc_count > 0',
            [$projectId, $otherType]
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
                    // Prefer to keep the current type (user is viewing it)
                    // but keep whichever has more occurrences
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
            $where   .= ' AND k.type = ?';
            $params[] = $type;
        }
        if ($search !== '') {
            $where   .= ' AND k.term LIKE ?';
            $params[] = '%' . $search . '%';
        }

        return $conn->fetchAllAssociative(
            "SELECT k.id, k.term, k.type, COUNT(dk.document_id) AS doc_count
             FROM keyword k
             JOIN document_keyword dk ON k.id = dk.keyword_id
             JOIN document d ON dk.document_id = d.id
             WHERE {$where}
             GROUP BY k.id, k.term, k.type
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

            $conn->executeStatement('DELETE FROM keyword WHERE id = ?', [$discardId]);

            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    // ── 3. Document Deduplication Heuristics ───────────────────────────────────

    /**
     * Find potential duplicate documents.
     * Heuristic: group by year, then compare titles within each year!
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

                    // Quick length check to avoid expensive similarity computation
                    $len1 = strlen($d1['title']);
                    $len2 = strlen($d2['title']);
                    if (abs($len1 - $len2) > max($len1, $len2) * (1 - $minSimilarity)) {
                        continue;
                    }

                    $sim = $this->calculateSimilarity($d1['title'], $d2['title']);
                    if ($sim >= $minSimilarity) {
                        // Keep the one with DOI or with more citations or abstract (simplified here by citations/EID)
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
                'SELECT author_id, position, original_name FROM document_author WHERE document_id = ?',
                [$discardId]
            );
            foreach ($discardAuthors as $da) {
                $exists = $conn->fetchOne(
                    'SELECT id FROM document_author WHERE document_id = ? AND author_id = ?',
                    [$keepId, $da['author_id']]
                );
                if (!$exists) {
                    $conn->insert('document_author', [
                        'document_id'   => $keepId,
                        'author_id'     => $da['author_id'],
                        'position'      => $da['position'],
                        'original_name' => $da['original_name'],
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

        // Strip non-alphanumeric for clean phonetic comparison
        $clean1 = preg_replace('/[^a-z0-9 ]/', '', $s1);
        $clean2 = preg_replace('/[^a-z0-9 ]/', '', $s2);
        if ($clean1 === $clean2) return 0.98;

        // Levenshtein-based similarity score
        $len1 = strlen($s1);
        $len2 = strlen($s2);
        $maxLen = max($len1, $len2);

        if ($maxLen === 0) return 1.0;

        // Fallback to similar_text for longer strings where levenshtein limits are reached (>255)
        if ($maxLen > 255) {
            similar_text($s1, $s2, $percent);
            return $percent / 100;
        }

        $lev = levenshtein($s1, $s2);
        return 1 - ($lev / $maxLen);
    }
}
