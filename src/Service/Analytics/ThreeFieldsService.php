<?php

namespace App\Service\Analytics;

use Doctrine\DBAL\Connection;

class ThreeFieldsService
{
    public function __construct(private readonly Connection $conn) {}

    /**
     * Build the Three-Fields Plot (Sankey structure) for ECharts.
     */
    public function buildThreeFieldsPlot(
        int $projectId,
        string $leftField,
        string $middleField,
        string $rightField,
        int $limit = 20,
        int $minWeight = 1
    ): array {
        // 1. Get Top Items for each of the three fields
        $topLeft        = $this->getTopItems($projectId, $leftField, $limit);
        $topMiddle      = $this->getTopItems($projectId, $middleField, $limit);
        $topRight       = $this->getTopItems($projectId, $rightField, $limit);

        // Convert lists to lookup maps for O(1) checks
        $leftSet   = array_flip($topLeft);
        $middleSet = array_flip($topMiddle);
        $rightSet  = array_flip($topRight);

        // 2. Fetch all document relationships in the project in three targeted queries
        $docs = $this->conn->fetchAllAssociative(
            'SELECT id, source_title, countries, institutions FROM document WHERE project_id = ?',
            [$projectId]
        );

        $authorsByDoc = [];
        $authorRows = $this->conn->fetchAllAssociative(
            'SELECT da.document_id, a.name
             FROM document_author da
             JOIN author a ON a.id = da.author_id
             JOIN document d ON d.id = da.document_id
             WHERE d.project_id = ?',
            [$projectId]
        );
        foreach ($authorRows as $row) {
            $authorsByDoc[(int)$row['document_id']][] = $row['name'];
        }

        $kwsByDoc = [];
        $kwRows = $this->conn->fetchAllAssociative(
            'SELECT dk.document_id, k.term, k.type
             FROM document_keyword dk
             JOIN keyword k ON k.id = dk.keyword_id
             JOIN document d ON d.id = dk.document_id
             WHERE d.project_id = ?',
            [$projectId]
        );
        foreach ($kwRows as $row) {
            $kwsByDoc[(int)$row['document_id']][$row['type']][] = $row['term'];
        }

        // 3. Compute Links
        $leftMiddleLinks = [];
        $middleRightLinks = [];

        foreach ($docs as $doc) {
            $docId = (int)$doc['id'];

            // Extract values for Left, Middle, and Right
            $leftValues   = $this->extractFieldValues($doc, $docId, $leftField, $authorsByDoc, $kwsByDoc);
            $middleValues = $this->extractFieldValues($doc, $docId, $middleField, $authorsByDoc, $kwsByDoc);
            $rightValues  = $this->extractFieldValues($doc, $docId, $rightField, $authorsByDoc, $kwsByDoc);

            // Filter to only include Top Items
            $leftFiltered   = array_intersect($leftValues, $topLeft);
            $middleFiltered = array_intersect($middleValues, $topMiddle);
            $rightFiltered  = array_intersect($rightValues, $topRight);

            // Left -> Middle links
            foreach ($leftFiltered as $lVal) {
                foreach ($middleFiltered as $mVal) {
                    $key = $lVal . '||' . $mVal;
                    $leftMiddleLinks[$key] = ($leftMiddleLinks[$key] ?? 0) + 1;
                }
            }

            // Middle -> Right links
            foreach ($middleFiltered as $mVal) {
                foreach ($rightFiltered as $rVal) {
                    $key = $mVal . '||' . $rVal;
                    $middleRightLinks[$key] = ($middleRightLinks[$key] ?? 0) + 1;
                }
            }
        }

        // 4. Build ECharts Nodes and Links
        // Suffixes:
        // Left: " [L]"
        // Middle: " [M]"
        // Right: " [R]"
        $nodes = [];
        $nodeSet = [];

        $addNode = function ($name, $suffix) use (&$nodes, &$nodeSet) {
            $uniqueName = $name . ' ' . $suffix;
            if (!isset($nodeSet[$uniqueName])) {
                $nodeSet[$uniqueName] = true;
                $nodes[] = ['name' => $uniqueName];
            }
            return $uniqueName;
        };

        $links = [];

        // Left -> Middle links
        foreach ($leftMiddleLinks as $key => $weight) {
            if ($weight < $minWeight) continue;
            [$l, $m] = explode('||', $key);
            $source = $addNode($l, '[L]');
            $target = $addNode($m, '[M]');
            $links[] = [
                'source' => $source,
                'target' => $target,
                'value'  => $weight
            ];
        }

        // Middle -> Right links
        foreach ($middleRightLinks as $key => $weight) {
            if ($weight < $minWeight) continue;
            [$m, $r] = explode('||', $key);
            $source = $addNode($m, '[M]');
            $target = $addNode($r, '[R]');
            $links[] = [
                'source' => $source,
                'target' => $target,
                'value'  => $weight
            ];
        }

        return [
            'nodes' => $nodes,
            'links' => $links,
            'meta'  => [
                'left'   => $leftField,
                'middle' => $middleField,
                'right'  => $rightField,
            ]
        ];
    }

    // ── Private Helpers ───────────────────────────────────────────────────────

    private function getTopItems(int $projectId, string $field, int $limit): array
    {
        if ($field === 'author') {
            return $this->conn->fetchFirstColumn(
                'SELECT a.name
                 FROM author a
                 JOIN document_author da ON a.id = da.author_id
                 JOIN document d         ON d.id = da.document_id AND d.project_id = ?
                 GROUP BY a.id, a.name
                 ORDER BY COUNT(DISTINCT da.document_id) DESC
                 LIMIT ' . (int)$limit,
                [$projectId]
            );
        }

        if ($field === 'keyword_author' || $field === 'keyword_indexed') {
            $type = $field === 'keyword_author' ? 'author' : 'indexed';
            return $this->conn->fetchFirstColumn(
                'SELECT k.term
                 FROM keyword k
                 JOIN document_keyword dk ON k.id = dk.keyword_id
                 JOIN document d          ON d.id = dk.document_id AND d.project_id = ?
                 WHERE k.type = ?
                 GROUP BY k.id, k.term
                 ORDER BY COUNT(DISTINCT dk.document_id) DESC
                 LIMIT ' . (int)$limit,
                [$projectId, $type]
            );
        }

        if ($field === 'source') {
            return $this->conn->fetchFirstColumn(
                'SELECT source_title
                 FROM document
                 WHERE project_id = ? AND source_title IS NOT NULL AND source_title != \'\'
                 GROUP BY source_title
                 ORDER BY COUNT(*) DESC
                 LIMIT ' . (int)$limit,
                [$projectId]
            );
        }

        if ($field === 'country' || $field === 'institution') {
            $column = $field === 'country' ? 'countries' : 'institutions';
            $rows = $this->conn->fetchFirstColumn(
                "SELECT $column FROM document WHERE project_id = ? AND $column IS NOT NULL",
                [$projectId]
            );

            $counts = [];
            foreach ($rows as $json) {
                $arr = json_decode($json, true);
                if (is_array($arr)) {
                    foreach ($arr as $item) {
                        $item = trim($item);
                        if ($item !== '') {
                            $counts[$item] = ($counts[$item] ?? 0) + 1;
                        }
                    }
                }
            }

            arsort($counts);
            return array_slice(array_keys($counts), 0, $limit);
        }

        return [];
    }

    private function extractFieldValues(
        array $doc,
        int $docId,
        string $field,
        array $authorsByDoc,
        array $kwsByDoc
    ): array {
        if ($field === 'author') {
            return $authorsByDoc[$docId] ?? [];
        }

        if ($field === 'keyword_author') {
            return $kwsByDoc[$docId]['author'] ?? [];
        }

        if ($field === 'keyword_indexed') {
            return $kwsByDoc[$docId]['indexed'] ?? [];
        }

        if ($field === 'source') {
            return $doc['source_title'] ? [$doc['source_title']] : [];
        }

        if ($field === 'country') {
            return $doc['countries'] ? (json_decode($doc['countries'], true) ?: []) : [];
        }

        if ($field === 'institution') {
            return $doc['institutions'] ? (json_decode($doc['institutions'], true) ?: []) : [];
        }

        return [];
    }
}
