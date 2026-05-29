<?php

namespace App\Service\Network;

use Doctrine\ORM\EntityManagerInterface;

class ThematicMapService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Build Thematic Map coordinates and clusters.
     */
    public function buildThematicMap(int $projectId, string $kwType = 'author', int $minOccur = 2, int $maxKeywords = 100): array
    {
        $conn = $this->em->getConnection();

        // 1. Fetch top keywords (nodes) of the given type
        $rawNodes = $conn->fetchAllAssociative('
            SELECT 
                k.id, 
                k.term AS label, 
                COUNT(dk.document_id) AS doc_count
            FROM keyword k
            JOIN document_keyword dk ON k.id = dk.keyword_id
            JOIN document d ON dk.document_id = d.id
            WHERE d.project_id = ? AND k.type = ?
            GROUP BY k.id, k.term
            HAVING COUNT(dk.document_id) >= ?
            ORDER BY doc_count DESC
            LIMIT ' . (int)$maxKeywords, [$projectId, $kwType, $minOccur]);

        if (empty($rawNodes)) {
            return [
                'clusters' => [],
                'meta' => [
                    'avgCentrality' => 0,
                    'avgDensity' => 0,
                    'medianCentrality' => 0,
                    'medianDensity' => 0,
                ]
            ];
        }

        // Map nodes by ID for fast lookup
        $selectedNodeIds = [];
        $nodeDetails = [];
        $maxDocCount = 1;
        foreach ($rawNodes as $node) {
            $id = (int)$node['id'];
            $selectedNodeIds[$id] = true;
            $docCount = (int)$node['doc_count'];
            $maxDocCount = max($maxDocCount, $docCount);
            $nodeDetails[$id] = [
                'id' => $id,
                'label' => $node['label'] ?? 'Sem rótulo',
                'doc_count' => $docCount
            ];
        }

        // Dynamically scale min co-occurrence weight based on maximum keyword frequency to keep the density healthy
        $edgeMinWeight = max(2, (int)round($maxDocCount / 100));

        $nodeIdPlaceholders = implode(',', array_keys($selectedNodeIds));

        // 2. Fetch co-occurrence weights (edges) ONLY between selected top keywords
        $rawEdges = $conn->fetchAllAssociative("
            SELECT 
                dk1.keyword_id AS source, 
                dk2.keyword_id AS target, 
                COUNT(dk1.document_id) AS weight
            FROM document_keyword dk1
            JOIN document_keyword dk2 ON dk1.document_id = dk2.document_id AND dk1.keyword_id < dk2.keyword_id
            JOIN document d ON dk1.document_id = d.id
            WHERE d.project_id = ? 
              AND dk1.keyword_id IN ($nodeIdPlaceholders)
              AND dk2.keyword_id IN ($nodeIdPlaceholders)
            GROUP BY dk1.keyword_id, dk2.keyword_id
            HAVING COUNT(dk1.document_id) >= ?
            ORDER BY weight DESC
        ", [$projectId, $edgeMinWeight]);

        // 3. Detect clusters/communities using Hierarchical Cosine Similarity Agglomerative Clustering
        $communities = $this->agglomerativeClustering($rawNodes, $rawEdges);

        // Group keywords by cluster ID
        $clusterGroups = [];
        foreach ($rawNodes as $node) {
            $id = (int)$node['id'];
            $clusterId = $communities[$id] ?? 0;
            $clusterGroups[$clusterId][] = $nodeDetails[$id];
        }

        // Map of edges for quick weight lookups: source_target => weight
        $edgeWeights = [];
        $adjacency = [];
        foreach ($rawEdges as $edge) {
            $src = (int)$edge['source'];
            $tgt = (int)$edge['target'];
            $w = (int)$edge['weight'];
            
            $edgeWeights["{$src}_{$tgt}"] = $w;
            $edgeWeights["{$tgt}_{$src}"] = $w;
            
            $adjacency[$src][$tgt] = $w;
            $adjacency[$tgt][$src] = $w;
        }

        // 4. Calculate Centrality and Density for each cluster
        $clustersData = [];
        foreach ($clusterGroups as $clusterId => $keywordsList) {
            $clusterSize = count($keywordsList);
            if ($clusterSize === 0) continue;

            // Sort keywords inside this cluster by frequency (doc_count) desc
            usort($keywordsList, fn($a, $b) => $b['doc_count'] <=> $a['doc_count']);

            $mainLabel = $keywordsList[0]['label'];
            $totalDocCount = array_sum(array_column($keywordsList, 'doc_count'));

            // Create set of node IDs in this cluster
            $clusterNodeIds = array_column($keywordsList, 'id');
            $clusterNodeIdSet = array_flip($clusterNodeIds);

            // Compute Callon's Centrality and Density
            $centrality = 0;
            $density = 0;
            
            foreach ($keywordsList as $kw) {
                $nodeId = $kw['id'];
                
                // Get all neighbors of this node
                $neighbors = $adjacency[$nodeId] ?? [];
                
                foreach ($neighbors as $neighborId => $weight) {
                    if (isset($clusterNodeIdSet[$neighborId])) {
                        // Internal edge (Density)
                        // To avoid double counting, only count if nodeId < neighborId
                        if ($nodeId < $neighborId) {
                            $density += $weight;
                        }
                    } else {
                        // External edge (Centrality)
                        $centrality += $weight;
                    }
                }
            }

            // Normalization: Density is divided by cluster size
            $densityNormalized = $clusterSize > 1 ? round($density / $clusterSize, 3) : 0;
            $centrality = round($centrality, 3);

            $clustersData[] = [
                'id' => $clusterId,
                'label' => $mainLabel,
                'size' => $clusterSize,
                'doc_count' => $totalDocCount,
                'centrality' => $centrality,
                'density' => $densityNormalized,
                'keywords' => $keywordsList
            ];
        }

        // 5. Calculate averages and medians of Centrality and Density to center the quadrants
        $centralities = array_column($clustersData, 'centrality');
        $densities = array_column($clustersData, 'density');
        
        $avgCentrality = count($centralities) > 0 ? array_sum($centralities) / count($centralities) : 0;
        $avgDensity = count($densities) > 0 ? array_sum($densities) / count($densities) : 0;
        
        $medianCentrality = $this->calculateMedian($centralities);
        $medianDensity = $this->calculateMedian($densities);

        return [
            'clusters' => $clustersData,
            'meta' => [
                'avgCentrality' => round($avgCentrality, 3),
                'avgDensity' => round($avgDensity, 3),
                'medianCentrality' => round($medianCentrality, 3),
                'medianDensity' => round($medianDensity, 3),
            ]
        ];
    }

    /**
     * Hierarchical Cosine Similarity Agglomerative Clustering (AGNES).
     * Deterministic, beautiful, Ward/Average-linkage keyword clusterization.
     */
    private function agglomerativeClustering(array $nodes, array $edges, int $targetClusters = 8): array
    {
        $n = count($nodes);
        if ($n === 0) return [];

        // Map keyword IDs to indices 0..n-1
        $idToIdx = [];
        $idxToId = [];
        foreach ($nodes as $idx => $node) {
            $id = (int)$node['id'];
            $idToIdx[$id] = $idx;
            $idxToId[$idx] = $id;
        }

        // Build co-occurrence weights matrix
        $w = array_fill(0, $n, array_fill(0, $n, 0));
        foreach ($edges as $edge) {
            $src = (int)$edge['source'];
            $tgt = (int)$edge['target'];
            $weight = (int)$edge['weight'];
            
            if (isset($idToIdx[$src]) && isset($idToIdx[$tgt])) {
                $i = $idToIdx[$src];
                $j = $idToIdx[$tgt];
                $w[$i][$j] = $weight;
                $w[$j][$i] = $weight;
            }
        }

        // Calculate Cosine Similarity Matrix (Salton's Cosine)
        $sim = array_fill(0, $n, array_fill(0, $n, 0.0));
        for ($i = 0; $i < $n; $i++) {
            $ci = (int)$nodes[$i]['doc_count'];
            $sim[$i][$i] = 1.0;
            for ($j = $i + 1; $j < $n; $j++) {
                $cj = (int)$nodes[$j]['doc_count'];
                $cooc = $w[$i][$j];
                if ($cooc > 0 && $ci > 0 && $cj > 0) {
                    $s = $cooc / sqrt($ci * $cj);
                    $sim[$i][$j] = $s;
                    $sim[$j][$i] = $s;
                }
            }
        }

        // Initialize clusters: each node in its own cluster
        $clusters = [];
        for ($i = 0; $i < $n; $i++) {
            $clusters[$i] = [$i];
        }

        // Merge clusters until target is reached
        while (count($clusters) > $targetClusters) {
            $maxSim = -1.0;
            $mergeA = -1;
            $mergeB = -1;

            $keys = array_keys($clusters);
            $cCount = count($keys);

            for ($i = 0; $i < $cCount; $i++) {
                $keyA = $keys[$i];
                $clusterA = $clusters[$keyA];
                for ($j = $i + 1; $j < $cCount; $j++) {
                    $keyB = $keys[$j];
                    $clusterB = $clusters[$keyB];

                    // Average linkage similarity
                    $sum = 0.0;
                    foreach ($clusterA as $nodeA) {
                        foreach ($clusterB as $nodeB) {
                            $sum += $sim[$nodeA][$nodeB];
                        }
                    }
                    $avgSim = $sum / (count($clusterA) * count($clusterB));

                    if ($avgSim > $maxSim) {
                        $maxSim = $avgSim;
                        $mergeA = $keyA;
                        $mergeB = $keyB;
                    }
                }
            }

            // Stop merging if maximum similarity between remaining clusters is 0
            if ($maxSim <= 0.0) {
                break;
            }

            // Merge cluster B into cluster A
            $clusters[$mergeA] = array_merge($clusters[$mergeA], $clusters[$mergeB]);
            unset($clusters[$mergeB]);
        }

        // Map node IDs to sequential cluster index 0..k-1
        $nodeLabels = [];
        $clusterIdx = 0;
        foreach ($clusters as $key => $nodeIndices) {
            foreach ($nodeIndices as $nodeIdx) {
                $nodeId = $idxToId[$nodeIdx];
                $nodeLabels[$nodeId] = $clusterIdx;
            }
            $clusterIdx++;
        }

        return $nodeLabels;
    }

    /**
     * Compute median of a numeric array.
     */
    private function calculateMedian(array $numbers): float
    {
        if (empty($numbers)) return 0;
        
        sort($numbers);
        $count = count($numbers);
        $middle = (int)($count / 2);
        
        if ($count % 2 === 0) {
            return ($numbers[$middle - 1] + $numbers[$middle]) / 2;
        }
        
        return $numbers[$middle];
    }
}
