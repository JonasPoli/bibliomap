<?php

namespace App\Service\Analytics;

use Doctrine\DBAL\Connection;

class ThreeFieldsService
{
    /** Documento → nome padronizado do país / da instituição. */
    private const GEO_SQL = [
        'country' => 'SELECT DISTINCT dp.document_id, p.common_name AS name
                      FROM documento_paises dp
                      JOIN paises p ON p.id = dp.country_id
                      JOIN document d ON d.id = dp.document_id
                      WHERE d.project_id = ?',
        'institution' => 'SELECT DISTINCT di.document_id, i.official_name AS name
                          FROM documento_instituicoes di
                          JOIN instituicoes_ensino i ON i.id = di.institution_id
                          JOIN document d ON d.id = di.document_id
                          WHERE d.project_id = ?',
    ];

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

        // 2. Busca só os campos pedidos e só os itens do top (o resto nunca entra nos links).
        // Os mesmos filtros são reaplicados no PHP, então o resultado não muda.
        $docs = $this->conn->fetchAllAssociative(
            'SELECT id, source_title FROM document WHERE project_id = ? ORDER BY id',
            [$projectId]
        );

        // Países e instituições vêm do cadastro padronizado (documento_paises / documento_instituicoes),
        // não do texto bruto da afiliação (document.countries traz "IA 50010 USA", "MI 48824 USA"...).
        $fields = [$leftField, $middleField, $rightField];
        $topByField = [
            $leftField   => $topLeft,
        ];
        foreach ([[$middleField, $topMiddle], [$rightField, $topRight]] as [$f, $top]) {
            $topByField[$f] = array_values(array_unique(array_merge($topByField[$f] ?? [], $top)));
        }

        $geoByDoc = [];
        foreach (['country', 'institution'] as $geoField) {
            if (!in_array($geoField, $fields, true)) continue;
            foreach ($this->conn->fetchAllAssociative(self::GEO_SQL[$geoField], [$projectId]) as $row) {
                $geoByDoc[$geoField][(int)$row['document_id']][] = $row['name'];
            }
        }

        $authorsByDoc = [];
        if (in_array('author', $fields, true) && !empty($topByField['author'])) {
            $authorRows = $this->conn->fetchAllAssociative(
                'SELECT da.document_id, a.preferred_name AS name
                 FROM document_author da
                 JOIN author_identity a ON a.id = da.author_identity_id
                 JOIN document d ON d.id = da.document_id
                 WHERE d.project_id = ? AND a.preferred_name IN (?)
                 ORDER BY da.document_id, da.id',
                [$projectId, $topByField['author']],
                [\Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ArrayParameterType::STRING]
            );
            foreach ($authorRows as $row) {
                $authorsByDoc[(int)$row['document_id']][] = $row['name'];
            }
        }

        $kwsByDoc = [];
        $kwTypes = [];
        $kwTerms = [];
        foreach (['keyword_author' => 'author_keyword', 'keyword_indexed' => 'indexed_keyword'] as $f => $type) {
            if (in_array($f, $fields, true) && !empty($topByField[$f])) {
                $kwTypes[] = $type;
                $kwTerms = array_merge($kwTerms, $topByField[$f]);
            }
        }
        if ($kwTypes) {
            $kwRows = $this->conn->fetchAllAssociative(
                'SELECT dk.document_id, COALESCE(tc.preferred_label, kc.keyword_display, k.keyword_display) AS term, k.keyword_type AS type
                 FROM document_keyword dk
                 JOIN keyword k ON k.id = dk.keyword_id
                 LEFT JOIN thesaurus_concept tc ON tc.id = k.thesaurus_concept_id
                 LEFT JOIN keyword kc ON k.keyword_concept_id = kc.id
                 JOIN document d ON d.id = dk.document_id
                 WHERE d.project_id = ? AND k.keyword_type IN (?)
                   AND COALESCE(tc.preferred_label, kc.keyword_display, k.keyword_display) IN (?)
                 ORDER BY dk.document_id, dk.id',
                [$projectId, $kwTypes, array_values(array_unique($kwTerms))],
                [\Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ArrayParameterType::STRING, \Doctrine\DBAL\ArrayParameterType::STRING]
            );
            foreach ($kwRows as $row) {
                // Map types from author_keyword/indexed_keyword to author/indexed for compatibility with frontend code expectations
                $legacyType = str_replace('_keyword', '', $row['type']);
                $kwsByDoc[(int)$row['document_id']][$legacyType][] = $row['term'];
            }
        }

        // 3. Compute Links
        $leftMiddleLinks = [];
        $middleRightLinks = [];

        foreach ($docs as $doc) {
            $docId = (int)$doc['id'];

            // Extract values for Left, Middle, and Right
            $leftValues   = $this->extractFieldValues($doc, $docId, $leftField, $authorsByDoc, $kwsByDoc, $geoByDoc);
            $middleValues = $this->extractFieldValues($doc, $docId, $middleField, $authorsByDoc, $kwsByDoc, $geoByDoc);
            $rightValues  = $this->extractFieldValues($doc, $docId, $rightField, $authorsByDoc, $kwsByDoc, $geoByDoc);

            // Filter to only include Top Items (cada item conta uma vez por documento)
            $leftFiltered   = array_unique(array_intersect($leftValues, $topLeft));
            $middleFiltered = array_unique(array_intersect($middleValues, $topMiddle));
            $rightFiltered  = array_unique(array_intersect($rightValues, $topRight));

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
                'SELECT a.preferred_name AS name
                 FROM author_identity a
                 JOIN document_author da ON a.id = da.author_identity_id
                 JOIN document d         ON d.id = da.document_id AND d.project_id = ?
                 GROUP BY a.id, a.preferred_name
                 ORDER BY COUNT(DISTINCT da.document_id) DESC, a.id ASC
                 LIMIT ' . (int)$limit,
                [$projectId]
            );
        }

        if ($field === 'keyword_author' || $field === 'keyword_indexed') {
            $type = $field === 'keyword_author' ? 'author_keyword' : 'indexed_keyword';
            $rows = $this->conn->fetchAllAssociative(
                'SELECT COALESCE(tc.preferred_label, kc.keyword_display, k.keyword_display) AS term
                 FROM keyword k
                 LEFT JOIN thesaurus_concept tc ON tc.id = k.thesaurus_concept_id
            LEFT JOIN keyword kc ON k.keyword_concept_id = kc.id
                 JOIN document_keyword dk ON k.id = dk.keyword_id
                 JOIN document d ON d.id = dk.document_id AND d.project_id = ?
                 WHERE k.keyword_type = ?
                 GROUP BY IF(k.thesaurus_concept_id IS NULL, COALESCE(k.keyword_concept_id, k.id), -k.thesaurus_concept_id), COALESCE(tc.preferred_label, kc.keyword_display, k.keyword_display)
                 ORDER BY COUNT(DISTINCT dk.document_id) DESC, term ASC
                 LIMIT ' . (int)$limit,
                [$projectId, $type]
            );
            return array_column($rows, 'term');
        }

        if ($field === 'source') {
            return $this->conn->fetchFirstColumn(
                'SELECT source_title
                 FROM document
                 WHERE project_id = ? AND source_title IS NOT NULL AND source_title != \'\'
                 GROUP BY source_title
                 ORDER BY COUNT(*) DESC, source_title ASC
                 LIMIT ' . (int)$limit,
                [$projectId]
            );
        }

        if ($field === 'country' || $field === 'institution') {
            // Ranking por nº de documentos (não por menções nas afiliações)
            return $this->conn->fetchFirstColumn(
                'SELECT name FROM (' . self::GEO_SQL[$field] . ') g
                 GROUP BY name
                 ORDER BY COUNT(DISTINCT document_id) DESC, name
                 LIMIT ' . (int)$limit,
                [$projectId]
            );
        }

        return [];
    }

    private function extractFieldValues(
        array $doc,
        int $docId,
        string $field,
        array $authorsByDoc,
        array $kwsByDoc,
        array $geoByDoc
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

        if ($field === 'country' || $field === 'institution') {
            return $geoByDoc[$field][$docId] ?? [];
        }

        return [];
    }
}
