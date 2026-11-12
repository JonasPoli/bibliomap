<?php

namespace App\Service\Normalize;

use App\Service\Authority\AuthorityMergeLogger;
use Doctrine\ORM\EntityManagerInterface;

class NormalizationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuthorityMergeLogger $mergeLogger,
    ) {}

    // ── 1. Author Normalization Heuristics ──────────────────────────────────────

    /**
     * Find similar author suggestions.
     * Uses a fast grouping heuristic (4 primeiras letras do sobrenome + inicial do prenome,
     * ver authorBlockKey) to avoid O(N^2) comparison.
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
            $groups[self::authorBlockKey($auth['name'])][] = $auth;
        }

        $suggestions = [];
        foreach ($groups as $key => $group) {
            $count = count($group);
            if ($count < 2) continue;

            // Cada nome é preparado uma vez (minúsculas, forma limpa, tamanho), não a cada par
            $prep = array_map(fn ($a) => self::prepareSimilarity($a['name']), $group);

            // Compare inside the small group
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $a1 = $group[$i];
                    $a2 = $group[$j];

                    // Check similarity (null: o tamanho já garante similaridade abaixo do limiar)
                    $sim = self::similarityPrepared($prep[$i], $prep[$j], $minSimilarity);
                    if ($sim !== null && $sim >= $minSimilarity) {
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
     * Chave de bloco para comparar autores: 4 primeiras letras do sobrenome + inicial do prenome.
     * Os nomes gravados vêm como "Sobrenome, Prenome/Iniciais" (WoS, Scopus); também aceita
     * "Sobrenome JA" (iniciais no fim, sem vírgula) e "Prenome Sobrenome" (Lens).
     * Assim "Silva, J" e "Silva, JA" caem no mesmo bloco e são comparados.
     */
    public static function authorBlockKey(string $name): string
    {
        $name = trim($name);
        if (str_contains($name, ',')) {
            [$surname, $given] = array_map('trim', explode(',', $name, 2));
        } else {
            $parts = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [''];
            $last  = end($parts);
            if (count($parts) > 1 && preg_match('/^(\p{Lu}[.\-]?){1,4}$/u', $last)) {
                $given   = array_pop($parts);
                $surname = implode(' ', $parts);
            } elseif (count($parts) > 1) {
                $surname = array_pop($parts);
                $given   = $parts[0];
            } else {
                $surname = $parts[0];
                $given   = '';
            }
        }

        // Minúsculas, sem acentos e só letras/dígitos (transliterador criado uma vez: é caro)
        static $tr = null;
        $tr ??= \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC; Lower');
        $surname = preg_replace('/[^\p{L}\p{N}]+/u', '', (string) $tr->transliterate($surname));
        $given   = preg_replace('/[^\p{L}\p{N}]+/u', '', (string) $tr->transliterate($given));

        return mb_substr($surname, 0, 4) . '|' . mb_substr($given, 0, 1);
    }

    /**
     * Passa os vínculos documento -> autor/palavra-chave do descartado para o mantido, com o mesmo
     * resultado do laço por documento de antes (e dos mesmos dois comandos da mesclagem em lote):
     * se o mantido já está no documento, o vínculo do descartado some; senão, o vínculo passa a ser
     * do mantido.
     */
    private function remapDocumentLinks(string $table, string $column, int $keepId, int $discardId): void
    {
        $conn = $this->em->getConnection();

        // 1. Documentos em que o mantido já aparece
        $conn->executeStatement(
            "DELETE FROM {$table}
             WHERE {$column} = ?
               AND document_id IN (SELECT document_id FROM (SELECT document_id FROM {$table} WHERE {$column} = ?) AS _keep)",
            [$discardId, $keepId]
        );
        // 2. Os demais passam para o mantido
        $conn->executeStatement(
            "UPDATE {$table} SET {$column} = ? WHERE {$column} = ?",
            [$keepId, $discardId]
        );
    }

    /**
     * Execute author merge. Grava no diário (authority_merge_log) antes de repontuar os vínculos.
     *
     * @param array $logExtra dados extras do diário (por exemplo ['via' => 'csv'])
     */
    public function mergeAuthors(int $keepId, int $discardId, ?int $projectId = null, array $logExtra = []): void
    {
        $conn = $this->em->getConnection();
        $conn->beginTransaction();

        try {
            $this->mergeLogger->logAuthorMerge('screen_project', 'merge', $discardId, $keepId, null, $projectId, $logExtra);
            // Vínculos com os documentos, em comandos de conjunto (antes: 2 a 3 consultas por documento)
            $this->remapDocumentLinks('document_author', 'author_identity_id', $keepId, $discardId);

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
    public function mergeAuthorsBatch(array $pairs, ?int $projectId = null): int
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

                // Diário: vínculos do descartado, antes de apagar os repetidos e repontuar
                $this->mergeLogger->logAuthorMerge('screen_project', 'merge', $discardId, $keepId, null, $projectId, ['via' => 'batch']);

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

            // Cada termo é preparado uma vez (minúsculas, forma limpa, tamanho), não a cada par
            $prep = array_map(fn ($k) => self::prepareSimilarity($k['term']), $group);

            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $k1 = $group[$i];
                    $k2 = $group[$j];

                    $t1 = $prep[$i]['lower'];
                    $t2 = $prep[$j]['lower'];
                    $isPluralMatch = ($t1 . 's' === $t2) || ($t2 . 's' === $t1)
                                  || ($t1 . 'es' === $t2) || ($t2 . 'es' === $t1);

                    // Par singular/plural mostra a similaridade real: nunca é descartado pelo tamanho
                    $sim = self::similarityPrepared($prep[$i], $prep[$j], $isPluralMatch ? 0.0 : $minSimilarity);

                    if ($sim !== null && ($sim >= $minSimilarity || $isPluralMatch)) {
                        $keep    = $k1['doc_count'] >= $k2['doc_count'] ? $k1 : $k2;
                        $discard = $keep['id'] === $k1['id'] ? $k2 : $k1;

                        // 100% fica reservado a termos idênticos após normalização: o par
                        // singular/plural mostra a similaridade real e não entra em "Mesclar 100%".
                        $suggestions[] = [
                            'keep'       => $keep,
                            'discard'    => $discard,
                            'similarity' => round($sim * 100, 1),
                            'match_type' => $isPluralMatch ? 'plural' : 'same_type',
                        ];
                    }
                }
            }
        }

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
    public function mergeKeywords(int $keepId, int $discardId, ?int $projectId = null, array $logExtra = []): void
    {
        $conn = $this->em->getConnection();
        $conn->beginTransaction();

        try {
            $this->mergeLogger->logKeywordMerge('screen_project', 'merge', $discardId, $keepId, null, $projectId, $logExtra);
            // Vínculos com os documentos, em comandos de conjunto (antes: 2 a 3 consultas por documento)
            $this->remapDocumentLinks('document_keyword', 'keyword_id', $keepId, $discardId);

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
    public function mergeKeywordsBatch(array $pairs, ?int $projectId = null): int
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

                // Diário: vínculos do descartado, antes de apagar os repetidos e repontuar
                $this->mergeLogger->logKeywordMerge('screen_project', 'merge', $discardId, $keepId, null, $projectId, ['via' => 'batch']);

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

            // Tamanho do título e forma preparada calculados uma vez por documento, não por par
            $lens = array_map(fn ($d) => strlen($d['title']), $group);
            $prep = [];

            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $d1 = $group[$i];
                    $d2 = $group[$j];

                    $len1 = $lens[$i];
                    $len2 = $lens[$j];
                    if (abs($len1 - $len2) > max($len1, $len2) * (1 - $minSimilarity)) {
                        continue;
                    }

                    $prep[$i] ??= self::prepareSimilarity($d1['title']);
                    $prep[$j] ??= self::prepareSimilarity($d2['title']);
                    $sim = self::similarityPrepared($prep[$i], $prep[$j], $minSimilarity);
                    if ($sim !== null && $sim >= $minSimilarity) {
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
                // Colunas entre crases: `references` é palavra reservada do MySQL
                [$sql, $params] = self::buildUpdateStatement('document', $updates, 'id', $keepId);
                $conn->executeStatement($sql, $params);
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
                'SELECT keyword_id, original_term FROM document_keyword WHERE document_id = ?',
                [$discardId]
            );
            foreach ($discardKws as $dk) {
                $exists = $conn->fetchOne(
                    'SELECT id FROM document_keyword WHERE document_id = ? AND keyword_id = ?',
                    [$keepId, $dk['keyword_id']]
                );
                if (!$exists) {
                    $conn->insert('document_keyword', [
                        'document_id'   => $keepId,
                        'keyword_id'    => $dk['keyword_id'],
                        'original_term' => $dk['original_term'], // NOT NULL: sem ele o merge falhava
                    ]);
                }
            }
            $conn->executeStatement('DELETE FROM document_keyword WHERE document_id = ?', [$discardId]);

            // 4b. Vínculos geográficos e institucionais: sem isto o CASCADE do DELETE abaixo os apagaria
            foreach (['documento_paises' => 'country_id', 'documento_estados' => 'state_id', 'documento_cidades' => 'city_id'] as $table => $col) {
                $conn->executeStatement(
                    "INSERT IGNORE INTO {$table} (document_id, {$col}) SELECT ?, {$col} FROM {$table} WHERE document_id = ?",
                    [$keepId, $discardId]
                );
            }
            $conn->executeStatement(
                'INSERT INTO documento_instituicoes (document_id, institution_id, link_type)
                 SELECT ?, di.institution_id, di.link_type FROM documento_instituicoes di
                 WHERE di.document_id = ?
                   AND NOT EXISTS (SELECT 1 FROM documento_instituicoes k WHERE k.document_id = ? AND k.institution_id = di.institution_id)',
                [$keepId, $discardId, $keepId]
            );
            // Classificação temática: o documento mantido herda os grupos do descartado
            $conn->executeStatement(
                'UPDATE IGNORE document_classification SET document_id = ? WHERE document_id = ?',
                [$keepId, $discardId]
            );

            // 5. Delete discarded document
            $conn->executeStatement('DELETE FROM document WHERE id = ?', [$discardId]);

            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    /**
     * UPDATE com os nomes de coluna entre crases (DBAL::update não os protege).
     *
     * @return array{0: string, 1: array}  SQL e parâmetros
     */
    public static function buildUpdateStatement(string $table, array $updates, string $keyColumn, int|string $keyValue): array
    {
        $sets = [];
        $params = [];
        foreach ($updates as $col => $val) {
            $sets[] = '`' . str_replace('`', '``', (string) $col) . '` = ?';
            $params[] = $val;
        }
        $params[] = $keyValue;

        return [
            sprintf('UPDATE `%s` SET %s WHERE `%s` = ?', $table, implode(', ', $sets), $keyColumn),
            $params,
        ];
    }

    // ── Helper: String Similarity Heuristic ───────────────────────────────────

    private function calculateSimilarity(string $s1, string $s2): float
    {
        return self::similarityPrepared(self::prepareSimilarity($s1), self::prepareSimilarity($s2), 0.0);
    }

    /** Parte de cada texto que não depende do outro: minúsculas, forma só com a-z0-9 e espaço, tamanho em bytes e histograma de caracteres. */
    public static function prepareSimilarity(string $s): array
    {
        $lower = strtolower(trim($s));
        $len = strlen($lower);

        // Histograma por grupos de bytes (28 grupos: a-z, "dígito ou espaço" e o resto). Só para textos que
        // vão a Levenshtein (até 255 bytes); serve ao limite inferior de similarityPrepared.
        $hist = null;
        if ($len > 0 && $len <= 255) {
            static $map = null;
            if ($map === null) {
                $map = array_fill(0, 256, 27);
                foreach (range('a', 'z') as $i => $ch) {
                    $map[ord($ch)] = $i;
                }
                for ($d = 0; $d < 10; $d++) {
                    $map[ord((string) $d)] = 26;
                }
                $map[32] = 26;
            }
            $hist = array_fill(0, 28, 0);
            for ($k = 0; $k < $len; $k++) {
                $hist[$map[ord($lower[$k])]]++;
            }
        }

        return [
            'lower' => $lower,
            'clean' => preg_replace('/[^a-z0-9 ]/', '', $lower),
            'len'   => $len,
            'hist'  => $hist,
        ];
    }

    /**
     * Similaridade (0 a 1) entre dois textos preparados. Mesma regra de sempre: iguais = 1,0;
     * iguais sem pontuação = 0,98; textos longos (> 255) por similar_text; os demais por Levenshtein.
     *
     * Com $threshold > 0, devolve null quando já se sabe que a similaridade fica abaixo do limiar,
     * sem calcular Levenshtein. Dois limites inferiores da distância, ambos exatos:
     *  - a diferença de tamanho (cada inserção ou remoção muda o tamanho em 1);
     *  - metade da diferença entre os histogramas de caracteres (uma inserção ou remoção muda o
     *    histograma em 1 e uma troca em 2).
     * Com $threshold = 0 nunca devolve null.
     */
    public static function similarityPrepared(array $p1, array $p2, float $threshold): ?float
    {
        if ($p1['lower'] === $p2['lower']) return 1.0;
        if ($p1['clean'] === $p2['clean']) return 0.98;

        $maxLen = max($p1['len'], $p2['len']);

        if ($maxLen === 0) return 1.0;

        if ($maxLen > 255) {
            similar_text($p1['lower'], $p2['lower'], $percent);
            return $percent / 100;
        }

        if ($threshold > 0) {
            // Máximo de edições que ainda deixa a similaridade no limiar. A margem de 1e-9 evita que o
            // arredondamento decimal (ex.: 1 - 0,85 = 0,15000000000000002) descarte um par que chega.
            $allowed = (int) floor($maxLen * (1 - $threshold) + 1e-9);
            if (abs($p1['len'] - $p2['len']) > $allowed) {
                return null;
            }
            if ($p1['hist'] !== null && $p2['hist'] !== null) {
                $limit = 2 * $allowed;
                $sum = 0;
                $h1 = $p1['hist'];
                $h2 = $p2['hist'];
                for ($k = 0; $k < 28; $k++) {
                    $x = $h1[$k] - $h2[$k];
                    $sum += $x < 0 ? -$x : $x;
                    if ($sum > $limit) {
                        return null;
                    }
                }
            }
        }

        $lev = levenshtein($p1['lower'], $p2['lower']);
        return 1 - ($lev / $maxLen);
    }

    // ── Export & Import Normalization Rules ────────────────────────────────────

    /**
     * Desfaz o apóstrofo que a exportação põe antes de células que começam com = + - @
     * (proteção contra injeção de fórmula no Excel), para o CSV exportado poder ser reimportado.
     */
    public static function unescapeCsvCell(mixed $value): string
    {
        $value = (string) $value;
        if (preg_match("/^'[=+\\-@\t\r]/", $value)) {
            return substr($value, 1);
        }

        return $value;
    }

    public function exportAuthorNormalization(int $projectId): array
    {
        $conn = $this->em->getConnection();

        return $conn->fetchAllAssociative('
            SELECT DISTINCT
                ai.preferred_name AS preferred_name,
                anv.original_name AS variant_name,
                anv.source AS source,
                anv.created_at AS created_at
            FROM author_identity ai
            JOIN author_name_variant anv ON ai.id = anv.author_identity_id
            JOIN document_author da ON ai.id = da.author_identity_id
            JOIN document d ON da.document_id = d.id
            WHERE d.project_id = ? AND anv.original_name != ai.preferred_name
            ORDER BY ai.preferred_name ASC, anv.original_name ASC
        ', [$projectId]);
    }

    public function importAuthorNormalization(int $projectId, array $records): int
    {
        $conn = $this->em->getConnection();
        $appliedCount = 0;

        $authors = $conn->fetchAllAssociative('
            SELECT ai.id, ai.preferred_name, ai.normalized_name
            FROM author_identity ai
            JOIN document_author da ON ai.id = da.author_identity_id
            JOIN document d ON da.document_id = d.id
            WHERE d.project_id = ?
            GROUP BY ai.id, ai.preferred_name, ai.normalized_name
        ', [$projectId]);

        $authorMap = [];
        foreach ($authors as $a) {
            $authorMap[\App\Service\Import\DocumentEnrichmentService::normalize($a['preferred_name'])] = (int)$a['id'];
        }

        $variations = $conn->fetchAllAssociative('
            SELECT DISTINCT anv.author_identity_id, anv.normalized_name
            FROM author_name_variant anv
            JOIN document_author da ON anv.author_identity_id = da.author_identity_id
            JOIN document d ON da.document_id = d.id
            WHERE d.project_id = ?
        ', [$projectId]);

        foreach ($variations as $v) {
            $authorMap[$v['normalized_name']] = (int)$v['author_identity_id'];
        }

        foreach ($records as $record) {
            $record = array_map([self::class, 'unescapeCsvCell'], $record);
            $preferredName = trim($record['preferred_name'] ?? $record['keep_name'] ?? $record['preferred'] ?? $record['keep'] ?? '');
            $variantName   = trim($record['variant_name'] ?? $record['discard_name'] ?? $record['variant'] ?? $record['discard'] ?? '');

            if ($preferredName === '' || $variantName === '' || strcasecmp($preferredName, $variantName) === 0) {
                continue;
            }

            $normPreferred = \App\Service\Import\DocumentEnrichmentService::normalize($preferredName);
            $normVariant   = \App\Service\Import\DocumentEnrichmentService::normalize($variantName);

            $keepId = $authorMap[$normPreferred] ?? null;
            if (!$keepId) {
                $keepId = $conn->fetchOne('SELECT id FROM author_identity WHERE normalized_name = ?', [$normPreferred]);
                if (!$keepId) {
                    $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
                    $conn->insert('author_identity', [
                        'preferred_name' => $preferredName,
                        'normalized_name' => $normPreferred,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $keepId = (int)$conn->lastInsertId();
                } else {
                    $keepId = (int)$keepId;
                }
                $authorMap[$normPreferred] = $keepId;
            }

            $discardId = $authorMap[$normVariant] ?? null;
            if ($discardId && $discardId !== $keepId) {
                $this->mergeAuthors($keepId, $discardId, $projectId, ['via' => 'csv']);
                // O descartado deixou de existir: os nomes dele passam a apontar para o mantido
                foreach ($authorMap as $mapKey => $mapId) {
                    if ($mapId === $discardId) {
                        $authorMap[$mapKey] = $keepId;
                    }
                }
                $appliedCount++;
            } else {
                $existsVar = $conn->fetchOne(
                    'SELECT id FROM author_name_variant WHERE author_identity_id = ? AND normalized_name = ?',
                    [$keepId, $normVariant]
                );
                if (!$existsVar) {
                    $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
                    $conn->insert('author_name_variant', [
                        'author_identity_id' => $keepId,
                        'original_name'      => $variantName,
                        'display_name'       => $variantName,
                        'normalized_name'    => $normVariant,
                        'source'             => 'alternative',
                        'confidence'         => 1.0,
                        'created_at'         => $now,
                        'updated_at'         => $now,
                    ]);
                    $appliedCount++;
                }
            }
        }

        return $appliedCount;
    }

    public function exportKeywordNormalization(int $projectId): array
    {
        $conn = $this->em->getConnection();

        return $conn->fetchAllAssociative('
            SELECT DISTINCT
                k.keyword_display AS preferred_keyword,
                kv.variation_name AS variant_keyword,
                k.keyword_type AS keyword_type,
                kv.status AS status
            FROM keyword k
            JOIN palavra_chave_variacoes_nome kv ON k.id = kv.keyword_id
            JOIN document_keyword dk ON k.id = dk.keyword_id
            JOIN document d ON dk.document_id = d.id
            WHERE d.project_id = ? AND kv.variation_name COLLATE utf8mb4_unicode_ci != k.keyword_display COLLATE utf8mb4_unicode_ci
            ORDER BY k.keyword_display ASC, kv.variation_name ASC
        ', [$projectId]);
    }

    public function importKeywordNormalization(int $projectId, array $records): int
    {
        $conn = $this->em->getConnection();
        $appliedCount = 0;
        $textNormalizer = new \App\Service\Import\TextNormalizer();

        $keywords = $conn->fetchAllAssociative('
            SELECT k.id, k.keyword_display, k.keyword_original, k.keyword_type
            FROM keyword k
            JOIN document_keyword dk ON k.id = dk.keyword_id
            JOIN document d ON dk.document_id = d.id
            WHERE d.project_id = ?
            GROUP BY k.id, k.keyword_display, k.keyword_original, k.keyword_type
        ', [$projectId]);

        $keywordMap = [];
        foreach ($keywords as $k) {
            $norm = \App\Service\Import\DocumentEnrichmentService::normalize($k['keyword_display']);
            $type = $k['keyword_type'];
            $keywordMap[$type . '_' . $norm] = (int)$k['id'];
            $keywordMap['all_' . $norm] = (int)$k['id'];
        }

        $variations = $conn->fetchAllAssociative('
            SELECT DISTINCT kv.keyword_id, kv.normalized_name, k.keyword_type
            FROM palavra_chave_variacoes_nome kv
            JOIN keyword k ON kv.keyword_id = k.id
            JOIN document_keyword dk ON k.id = dk.keyword_id
            JOIN document d ON dk.document_id = d.id
            WHERE d.project_id = ?
        ', [$projectId]);

        foreach ($variations as $v) {
            $keywordMap[$v['keyword_type'] . '_' . $v['normalized_name']] = (int)$v['keyword_id'];
            $keywordMap['all_' . $v['normalized_name']] = (int)$v['keyword_id'];
        }

        foreach ($records as $record) {
            $record = array_map([self::class, 'unescapeCsvCell'], $record);
            $preferredTerm = trim($record['preferred_keyword'] ?? $record['keep_term'] ?? $record['preferred'] ?? $record['keep'] ?? '');
            $variantTerm   = trim($record['variant_keyword'] ?? $record['discard_term'] ?? $record['variant'] ?? $record['discard'] ?? '');
            $kwType        = trim($record['keyword_type'] ?? $record['type'] ?? 'author_keyword');

            if ($kwType === 'author') $kwType = 'author_keyword';
            if ($kwType === 'indexed') $kwType = 'indexed_keyword';

            if ($preferredTerm === '' || $variantTerm === '' || strcasecmp($preferredTerm, $variantTerm) === 0) {
                continue;
            }

            $normPreferred = \App\Service\Import\DocumentEnrichmentService::normalize($preferredTerm);
            $normVariant   = \App\Service\Import\DocumentEnrichmentService::normalize($variantTerm);

            $keepId = $keywordMap[$kwType . '_' . $normPreferred] ?? $keywordMap['all_' . $normPreferred] ?? null;

            if (!$keepId) {
                $keepId = $conn->fetchOne('SELECT id FROM keyword WHERE keyword_type = ? AND keyword_original = ?', [$kwType, $preferredTerm]);
                if (!$keepId) {
                    // Mesmo tratamento da importação: exibição limpa, forma normalizada e conceito próprio
                    $res = $textNormalizer->normalizeKeyword($preferredTerm);
                    if (!$res['valid']) {
                        continue;
                    }
                    $normKey = \App\Service\Import\DocumentImportService::cut($res['normalized'], 255);
                    $keepId = $conn->fetchOne('SELECT id FROM keyword WHERE keyword_type = ? AND keyword_normalized = ?', [$kwType, $normKey]);
                    if (!$keepId) {
                        $conn->insert('keyword', [
                            'keyword_original'   => \App\Service\Import\DocumentImportService::cut($preferredTerm, 255),
                            'keyword_display'    => \App\Service\Import\DocumentImportService::cut($textNormalizer->cleanDisplayValue($preferredTerm), 255),
                            'keyword_normalized' => $normKey,
                            'keyword_type'       => $kwType,
                            'status'             => $res['needs_review'] ? 0 : 1,
                            'review_reasons'     => !empty($res['review_reasons']) ? implode(',', $res['review_reasons']) : null,
                        ]);
                        $keepId = (int)$conn->lastInsertId();
                        $conn->executeStatement('UPDATE keyword SET keyword_concept_id = ? WHERE id = ?', [$keepId, $keepId]);
                    }
                }
                $keepId = (int)$keepId;
                $keywordMap[$kwType . '_' . $normPreferred] = $keepId;
                $keywordMap['all_' . $normPreferred] = $keepId;
            }

            $discardId = $keywordMap[$kwType . '_' . $normVariant] ?? $keywordMap['all_' . $normVariant] ?? null;

            if ($discardId && $discardId !== $keepId) {
                $this->mergeKeywords($keepId, $discardId, $projectId, ['via' => 'csv']);
                // O descartado deixou de existir: as chaves dele passam a apontar para o mantido
                foreach ($keywordMap as $mapKey => $mapId) {
                    if ($mapId === $discardId) {
                        $keywordMap[$mapKey] = $keepId;
                    }
                }
                $appliedCount++;
            } else {
                $existsVar = $conn->fetchOne(
                    'SELECT id FROM palavra_chave_variacoes_nome WHERE keyword_id = ? AND normalized_name = ?',
                    [$keepId, $normVariant]
                );
                if (!$existsVar) {
                    $conn->insert('palavra_chave_variacoes_nome', [
                        'keyword_id'      => $keepId,
                        'variation_name'  => $variantTerm,
                        'normalized_name' => $normVariant,
                        'variation_type'  => 'alternative',
                        'status'          => 1
                    ]);
                    $appliedCount++;
                }
            }
        }

        return $appliedCount;
    }
}
