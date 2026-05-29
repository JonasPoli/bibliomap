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

        // 1. Fetch raw nodes (authors) with their doc count and citations
        $rawNodes = $conn->fetchAllAssociative('
            SELECT 
                a.id, 
                a.name AS label, 
                COUNT(da.document_id) AS doc_count,
                SUM(COALESCE(d.cited_by, 0)) AS total_citations
            FROM author a
            JOIN document_author da ON a.id = da.author_id
            JOIN document d ON da.document_id = d.id
            WHERE d.project_id = ?
            GROUP BY a.id, a.name
            ORDER BY doc_count DESC
        ', [$projectId]);

        // 2. Fetch raw edges
        $rawEdges = $conn->fetchAllAssociative('
            SELECT 
                da1.author_id AS source, 
                da2.author_id AS target, 
                COUNT(da1.document_id) AS weight
            FROM document_author da1
            JOIN document_author da2 ON da1.document_id = da2.document_id AND da1.author_id < da2.author_id
            JOIN document d ON da1.document_id = d.id
            WHERE d.project_id = ?
            GROUP BY da1.author_id, da2.author_id
            HAVING weight >= ?
            ORDER BY weight DESC
        ', [$projectId, $minWeight]);

        return $this->formatGraph($rawNodes, $rawEdges, $maxNodes);
    }

    /**
     * Build Keyword Co-occurrence Network.
     */
    public function keywords(int $projectId, string $type = 'author', int $minWeight = 1, int $maxNodes = 150): array
    {
        $conn = $this->em->getConnection();

        // 1. Fetch raw nodes (keywords)
        $rawNodes = $conn->fetchAllAssociative('
            SELECT 
                k.id, 
                k.term AS label, 
                COUNT(dk.document_id) AS doc_count,
                0 AS total_citations
            FROM keyword k
            JOIN document_keyword dk ON k.id = dk.keyword_id
            JOIN document d ON dk.document_id = d.id
            WHERE d.project_id = ? AND k.type = ?
            GROUP BY k.id, k.term
            ORDER BY doc_count DESC
        ', [$projectId, $type]);

        // 2. Fetch raw edges
        $rawEdges = $conn->fetchAllAssociative('
            SELECT 
                dk1.keyword_id AS source, 
                dk2.keyword_id AS target, 
                COUNT(dk1.document_id) AS weight
            FROM document_keyword dk1
            JOIN document_keyword dk2 ON dk1.document_id = dk2.document_id AND dk1.keyword_id < dk2.keyword_id
            JOIN document d ON dk1.document_id = d.id
            JOIN keyword k1 ON dk1.keyword_id = k1.id
            JOIN keyword k2 ON dk2.keyword_id = k2.id
            WHERE d.project_id = ? AND k1.type = ? AND k2.type = ?
            GROUP BY dk1.keyword_id, dk2.keyword_id
            HAVING weight >= ?
            ORDER BY weight DESC
        ', [$projectId, $type, $type, $minWeight]);

        return $this->formatGraph($rawNodes, $rawEdges, $maxNodes);
    }

    /**
     * Build Country Collaboration Network from JSON.
     */
    public function countries(int $projectId, int $minWeight = 1, int $maxNodes = 100): array
    {
        $conn = $this->em->getConnection();

        // Fetch all countries arrays
        $docs = $conn->fetchAllAssociative('
            SELECT countries 
            FROM document 
            WHERE project_id = ? AND countries IS NOT NULL
        ', [$projectId]);

        $countryCounts = [];
        $edgeWeights   = [];

        foreach ($docs as $doc) {
            $countries = json_decode($doc['countries'], true);
            if (!is_array($countries)) continue;

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
     * Simple, fast, and fits beautifully in PHP.
     */
    private function detectCommunities(array $nodes, array $edges): array
    {
        $labels = [];
        $adj    = [];

        // Initialize label for each node with its own ID
        foreach ($nodes as $n) {
            $id = (int)$n['id'];
            $labels[$id] = $id;
            $adj[$id]    = [];
        }

        // Build adjacency list
        foreach ($edges as $e) {
            $adj[$e['from']][] = ['node' => $e['to'],   'weight' => $e['weight']];
            $adj[$e['to']][]   = ['node' => $e['from'], 'weight' => $e['weight']];
        }

        // LPA Iterations (5 is generally highly effective for stability)
        $iterations = 5;
        for ($it = 0; $it < $iterations; $it++) {
            // Shuffle nodes to prevent order bias
            $shuffledIds = array_keys($labels);
            shuffle($shuffledIds);

            $changed = false;

            foreach ($shuffledIds as $nodeId) {
                $neighbors = $adj[$nodeId] ?? [];
                if (empty($neighbors)) continue;

                // Sum weighted label frequencies among neighbors
                $labelWeights = [];
                foreach ($neighbors as $neighbor) {
                    $neighborId = $neighbor['node'];
                    $weight     = $neighbor['weight'];
                    $l          = $labels[$neighborId];

                    $labelWeights[$l] = ($labelWeights[$l] ?? 0) + $weight;
                }

                // Find the label with the max weight
                arsort($labelWeights);
                $bestLabel = key($labelWeights);

                if ($bestLabel !== $labels[$nodeId]) {
                    $labels[$nodeId] = $bestLabel;
                    $changed = true;
                }
            }

            if (!$changed) break; // early stopping if converged
        }

        // Normalize labels to a sequential 0, 1, 2, ... index
        $uniqueLabels = array_unique(array_values($labels));
        $labelMap     = array_flip(array_values($uniqueLabels));

        $normalizedLabels = [];
        foreach ($labels as $nodeId => $l) {
            $normalizedLabels[$nodeId] = $labelMap[$l];
        }

        return $normalizedLabels;
    }
}
