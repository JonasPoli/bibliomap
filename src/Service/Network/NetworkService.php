<?php

namespace App\Service\Network;

use Doctrine\ORM\EntityManagerInterface;

class NetworkService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Build Co-authorship Network.
     */
    public function coauthorship(int $projectId, int $minWeight = 1, int $maxNodes = 150): array
    {
        $conn = $this->em->getConnection();

        // 1. Fetch raw nodes (authors) with their doc count and citations, limited to top 1000
        $rawNodes = $conn->fetchAllAssociative('
            SELECT 
                a.id, 
                a.preferred_name AS label, 
                COUNT(da.document_id) AS doc_count,
                SUM(COALESCE(d.cited_by, 0)) AS total_citations
            FROM author_identity a
            JOIN document_author da ON a.id = da.author_identity_id
            JOIN document d ON da.document_id = d.id
            WHERE d.project_id = ?
            GROUP BY a.id, a.preferred_name
            ORDER BY doc_count DESC
            LIMIT 1000
        ', [$projectId]);

        if (empty($rawNodes)) {
            return $this->formatGraph([], [], $maxNodes);
        }

        $authorIds = array_column($rawNodes, 'id');
        $inList = implode(',', array_map('intval', $authorIds));

        // 2. Fetch raw edges restricted only to top 1000 authors
        $rawEdges = $conn->fetchAllAssociative("
            SELECT 
                da1.author_identity_id AS source, 
                da2.author_identity_id AS target, 
                COUNT(da1.document_id) AS weight
            FROM document_author da1
            JOIN document_author da2 ON da1.document_id = da2.document_id AND da1.author_identity_id < da2.author_identity_id
            JOIN document d ON da1.document_id = d.id
            WHERE d.project_id = ?
              AND da1.author_identity_id IN ($inList)
              AND da2.author_identity_id IN ($inList)
            GROUP BY da1.author_identity_id, da2.author_identity_id
            HAVING weight >= ?
            ORDER BY weight DESC
        ", [$projectId, $minWeight]);

        return $this->formatGraph($rawNodes, $rawEdges, $maxNodes);
    }

    /**
     * Build Keyword Co-occurrence Network.
     */
    public function keywords(int $projectId, string $type = 'author', int $minWeight = 1, int $maxNodes = 150): array
    {
        $conn = $this->em->getConnection();
        $mappedType = $type === 'author' ? 'author_keyword' : ($type === 'indexed' ? 'indexed_keyword' : $type);

        // 1. Fetch raw nodes (keywords grouped by concept) limited to top 1000 concepts
        $rawNodes = $conn->fetchAllAssociative('
            SELECT 
                COALESCE(k.thesaurus_concept_id, k.keyword_concept_id, k.id) AS id, 
                COALESCE(tc.preferred_label, kc.keyword_display, k.keyword_display) AS label, 
                COUNT(DISTINCT dk.document_id) AS doc_count,
                0 AS total_citations
            FROM keyword k
            LEFT JOIN thesaurus_concept tc ON tc.id = k.thesaurus_concept_id
            LEFT JOIN keyword kc ON k.keyword_concept_id = kc.id
            JOIN document_keyword dk ON k.id = dk.keyword_id
            JOIN document d ON dk.document_id = d.id
            WHERE d.project_id = ? AND k.keyword_type = ?
            GROUP BY COALESCE(k.thesaurus_concept_id, k.keyword_concept_id, k.id), COALESCE(tc.preferred_label, kc.keyword_display, k.keyword_display)
            ORDER BY doc_count DESC
            LIMIT 1000
        ', [$projectId, $mappedType]);

        if (empty($rawNodes)) {
            return $this->formatGraph([], [], $maxNodes);
        }

        $conceptIds = array_column($rawNodes, 'id');
        $inList = implode(',', array_map('intval', $conceptIds));

        // 2. Fetch raw edges (grouped by source and target concept IDs) restricted to top 1000 concepts
        $rawEdges = $conn->fetchAllAssociative("
            SELECT 
                CASE WHEN COALESCE(k1.thesaurus_concept_id, k1.keyword_concept_id, k1.id) < COALESCE(k2.thesaurus_concept_id, k2.keyword_concept_id, k2.id) 
                     THEN COALESCE(k1.thesaurus_concept_id, k1.keyword_concept_id, k1.id) 
                     ELSE COALESCE(k2.thesaurus_concept_id, k2.keyword_concept_id, k2.id) 
                END AS source,
                CASE WHEN COALESCE(k1.thesaurus_concept_id, k1.keyword_concept_id, k1.id) < COALESCE(k2.thesaurus_concept_id, k2.keyword_concept_id, k2.id) 
                     THEN COALESCE(k2.thesaurus_concept_id, k2.keyword_concept_id, k2.id) 
                     ELSE COALESCE(k1.thesaurus_concept_id, k1.keyword_concept_id, k1.id) 
                END AS target,
                COUNT(DISTINCT dk1.document_id) AS weight
            FROM document_keyword dk1
            JOIN document_keyword dk2 ON dk1.document_id = dk2.document_id
            JOIN document d ON dk1.document_id = d.id
            JOIN keyword k1 ON dk1.keyword_id = k1.id
            JOIN keyword k2 ON dk2.keyword_id = k2.id
            WHERE d.project_id = ? AND k1.keyword_type = ? AND k2.keyword_type = ?
              AND COALESCE(k1.thesaurus_concept_id, k1.keyword_concept_id, k1.id) IN ($inList)
              AND COALESCE(k2.thesaurus_concept_id, k2.keyword_concept_id, k2.id) IN ($inList)
              AND COALESCE(k1.thesaurus_concept_id, k1.keyword_concept_id, k1.id) != COALESCE(k2.thesaurus_concept_id, k2.keyword_concept_id, k2.id)
            GROUP BY source, target
            HAVING weight >= ?
            ORDER BY weight DESC
        ", [$projectId, $mappedType, $mappedType, $minWeight]);

        return $this->formatGraph($rawNodes, $rawEdges, $maxNodes);
    }

    /**
     * Build Country Collaboration Network from canonical mapped countries.
     */
    public function countries(int $projectId, int $minWeight = 1, int $maxNodes = 100): array
    {
        $conn = $this->em->getConnection();

        // Fetch all synchronized countries linked to documents in this project
        $rows = $conn->fetchAllAssociative('
            SELECT dp.document_id, p.common_name AS country
            FROM documento_paises dp
            JOIN paises p ON dp.country_id = p.id
            JOIN document d ON dp.document_id = d.id
            WHERE d.project_id = ?
        ', [$projectId]);

        $docCountries = [];
        foreach ($rows as $row) {
            $docCountries[$row['document_id']][] = $row['country'];
        }

        $countryCounts = [];
        $edgeWeights   = [];

        foreach ($docCountries as $docId => $countries) {
            $countries = array_map('trim', $countries);
            $countries = array_unique(array_filter($countries));

            // Count frequencies
            foreach ($countries as $c) {
                $countryCounts[$c] = ($countryCounts[$c] ?? 0) + 1;
            }

            // Generate pairs
            $count = count($countries);
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $c1 = $countries[$i];
                    $c2 = $countries[$j];

                    // Standardize ordering
                    if (strcmp($c1, $c2) > 0) {
                        $tmp = $c1; $c1 = $c2; $c2 = $tmp;
                    }

                    $key = $c1 . '||' . $c2;
                    $edgeWeights[$key] = ($edgeWeights[$key] ?? 0) + 1;
                }
            }
        }

        // Map country names to arbitrary IDs
        $countryIds = [];
        $idCounter  = 1;
        $nodes      = [];

        // Sort countries by document count desc
        arsort($countryCounts);

        foreach ($countryCounts as $name => $count) {
            $countryIds[$name] = $idCounter;
            $nodes[] = [
                'id'              => $idCounter,
                'label'           => $name,
                'doc_count'       => $count,
                'total_citations' => 0
            ];
            $idCounter++;
        }

        $edges = [];
        foreach ($edgeWeights as $key => $weight) {
            if ($weight < $minWeight) continue;

            [$c1, $c2] = explode('||', $key);
            if (isset($countryIds[$c1]) && isset($countryIds[$c2])) {
                $edges[] = [
                    'source' => $countryIds[$c1],
                    'target' => $countryIds[$c2],
                    'weight' => $weight
                ];
            }
        }

        return $this->formatGraph($nodes, $edges, $maxNodes);
    }

    // ── Helper: Format Graph and Run Analytics Heuristics ─────────────────────

    private function formatGraph(array $allNodes, array $allEdges, int $maxNodes): array
    {
        // 1. Limit nodes to the top ones based on frequency
        $nodesList = array_slice($allNodes, 0, $maxNodes);
        $nodeIds   = [];
        foreach ($nodesList as $n) {
            $nodeIds[$n['id']] = true;
        }

        // 2. Filter edges to only connect our selected top nodes
        $edgesList = [];
        foreach ($allEdges as $e) {
            if (isset($nodeIds[$e['source']]) && isset($nodeIds[$e['target']])) {
                $edgesList[] = [
                    'from'   => (int)$e['source'],
                    'to'     => (int)$e['target'],
                    'weight' => (int)$e['weight']
                ];
            }
        }

        // 3. Compute basic degree metrics
        $degrees = [];
        $weightedDegrees = [];
        foreach ($nodesList as $n) {
            $degrees[$n['id']] = 0;
            $weightedDegrees[$n['id']] = 0;
        }

        foreach ($edgesList as $e) {
            $degrees[$e['from']]++;
            $degrees[$e['to']]++;
            $weightedDegrees[$e['from']] += $e['weight'];
            $weightedDegrees[$e['to']] += $e['weight'];
        }

        // 4. Run Label Propagation Community Detection for visual clustering
        $communities = $this->detectCommunities($nodesList, $edgesList);

        // 5. Build final node objects with metrics
        $formattedNodes = [];
        foreach ($nodesList as $n) {
            $id = (int)$n['id'];
            $deg = $degrees[$id] ?? 0;
            $wdeg = $weightedDegrees[$id] ?? 0;

            $formattedNodes[] = [
                'id'              => $id,
                'label'           => $n['label'],
                'doc_count'       => (int)$n['doc_count'],
                'total_citations' => (int)$n['total_citations'],
                'degree'          => $deg,
                'weighted_degree' => $wdeg,
                'cluster'         => $communities[$id] ?? 0,
            ];
        }

        // Calculate general graph metrics
        $nodeCount = count($formattedNodes);
        $edgeCount = count($edgesList);
        $density   = $nodeCount > 1 ? (2 * $edgeCount) / ($nodeCount * ($nodeCount - 1)) : 0;
        $uniqueClusters = count(array_unique(array_values($communities)));

        return [
            'nodes'   => $formattedNodes,
            'edges'   => $edgesList,
            'metrics' => [
                'node_count'   => $nodeCount,
                'edge_count'   => $edgeCount,
                'density'      => round($density, 4),
                'communities'  => $uniqueClusters,
            ]
        ];
    }

    /**
     * Label Propagation Algorithm (LPA) for Community Detection.
     *
     * Uses Callon's Equivalence Index (EI = co² / occ_i × occ_j) as edge weight
     * instead of raw co-occurrence counts. This prevents high-frequency hub nodes
     * (e.g. "United States", "Artificial Intelligence") from collapsing the entire
     * graph into one giant community during the first propagation step.
     *
     * EI ranges from 0 to 1, measuring the *relative* strength of a link
     * independent of how frequent each node is individually.
     */
    private function detectCommunities(array $nodes, array $edges): array
    {
        $labels   = [];
        $adj      = [];
        $docCount = [];

        // Initialise: each node is its own community
        foreach ($nodes as $n) {
            $id          = (int)$n['id'];
            $labels[$id] = $id;
            $adj[$id]    = [];
            $docCount[$id] = max((int)($n['doc_count'] ?? 1), 1);
        }

        // Build adjacency using Equivalence Index:  EI(i,j) = co² / (occ_i × occ_j)
        foreach ($edges as $e) {
            $src = (int)$e['from'];
            $tgt = (int)$e['to'];
            $co  = (int)$e['weight'];

            $oi = $docCount[$src] ?? 1;
            $oj = $docCount[$tgt] ?? 1;
            $ei = ($co * $co) / ($oi * $oj); // Equivalence Index [0..1]

            $adj[$src][] = ['node' => $tgt, 'weight' => $ei];
            $adj[$tgt][] = ['node' => $src, 'weight' => $ei];
        }

        // LPA iterations — 10 gives stable results even on dense graphs
        $iterations = 10;
        for ($it = 0; $it < $iterations; $it++) {
            // Shuffle to prevent order bias
            $shuffledIds = array_keys($labels);
            shuffle($shuffledIds);

            $changed = false;

            foreach ($shuffledIds as $nodeId) {
                $neighbors = $adj[$nodeId] ?? [];
                if (empty($neighbors)) {
                    continue; // isolated node keeps its own label
                }

                // Sum EI weights per neighbouring label
                $labelWeights = [];
                foreach ($neighbors as $neighbor) {
                    $l = $labels[$neighbor['node']];
                    $labelWeights[$l] = ($labelWeights[$l] ?? 0.0) + $neighbor['weight'];
                }

                arsort($labelWeights);
                $bestLabel = key($labelWeights);

                if ($bestLabel !== $labels[$nodeId]) {
                    $labels[$nodeId] = $bestLabel;
                    $changed         = true;
                }
            }

            if (!$changed) {
                break; // converged early
            }
        }

        // Remap to sequential 0-based indices
        $uniqueLabels = array_unique(array_values($labels));
        $labelMap     = array_flip(array_values($uniqueLabels));

        $normalizedLabels = [];
        foreach ($labels as $nodeId => $l) {
            $normalizedLabels[$nodeId] = $labelMap[$l];
        }

        return $normalizedLabels;
    }
}

