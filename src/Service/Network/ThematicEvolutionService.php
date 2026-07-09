<?php

namespace App\Service\Network;

use Doctrine\ORM\EntityManagerInterface;

class ThematicEvolutionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Build Thematic Evolution Sankey data structure.
     */
    public function buildThematicEvolution(int $projectId, string $kwType = 'author', int $cutoffYear = 2024, int $minOccur = 2, int $maxKeywords = 100): array
    {
        $conn = $this->em->getConnection();
        $mappedType = $kwType === 'author' ? 'author_keyword' : ($kwType === 'indexed' ? 'indexed_keyword' : $kwType);

        // 1. Fetch top keywords for Period 1 (year <= cutoffYear)
        $rawNodesP1 = $conn->fetchAllAssociative('
            SELECT 
                COALESCE(k.thesaurus_concept_id, k.keyword_concept_id, k.id) AS id, 
                COALESCE(tc.preferred_label, kc.keyword_display, k.keyword_display) AS label, 
                COUNT(DISTINCT dk.document_id) AS doc_count
            FROM keyword k
            LEFT JOIN thesaurus_concept tc ON tc.id = k.thesaurus_concept_id
            LEFT JOIN keyword kc ON k.keyword_concept_id = kc.id
            JOIN document_keyword dk ON k.id = dk.keyword_id
            JOIN document d ON dk.document_id = d.id
            WHERE d.project_id = ? AND k.keyword_type = ? AND d.year <= ?
            GROUP BY COALESCE(k.thesaurus_concept_id, k.keyword_concept_id, k.id), COALESCE(tc.preferred_label, kc.keyword_display, k.keyword_display)
            HAVING COUNT(DISTINCT dk.document_id) >= ?
            ORDER BY doc_count DESC
            LIMIT ' . (int)$maxKeywords, [$projectId, $mappedType, $cutoffYear, $minOccur]);

        // 2. Fetch top keywords for Period 2 (year > cutoffYear)
        $rawNodesP2 = $conn->fetchAllAssociative('
            SELECT 
                COALESCE(k.thesaurus_concept_id, k.keyword_concept_id, k.id) AS id, 
                COALESCE(tc.preferred_label, kc.keyword_display, k.keyword_display) AS label, 
                COUNT(DISTINCT dk.document_id) AS doc_count
            FROM keyword k
            LEFT JOIN thesaurus_concept tc ON tc.id = k.thesaurus_concept_id
            LEFT JOIN keyword kc ON k.keyword_concept_id = kc.id
            JOIN document_keyword dk ON k.id = dk.keyword_id
            JOIN document d ON dk.document_id = d.id
            WHERE d.project_id = ? AND k.keyword_type = ? AND d.year > ?
            GROUP BY COALESCE(k.thesaurus_concept_id, k.keyword_concept_id, k.id), COALESCE(tc.preferred_label, kc.keyword_display, k.keyword_display)
            HAVING COUNT(DISTINCT dk.document_id) >= ?
            ORDER BY doc_count DESC
            LIMIT ' . (int)$maxKeywords, [$projectId, $mappedType, $cutoffYear, $minOccur]);

        if (empty($rawNodesP1) || empty($rawNodesP2)) {
            return [
                'nodes' => [],
                'links' => [],
                'details' => [],
                'meta' => [
                    'p1_size' => count($rawNodesP1),
                    'p2_size' => count($rawNodesP2),
                ]
            ];
        }

        // Map P1 Nodes
        $p1Ids = [];
        $p1NodeDetails = [];
        $p1MaxDoc = 1;
        foreach ($rawNodesP1 as $node) {
            $id = (int)$node['id'];
            $p1Ids[$id] = true;
            $docCount = (int)$node['doc_count'];
            $p1MaxDoc = max($p1MaxDoc, $docCount);
            $p1NodeDetails[$id] = [
                'id' => $id,
                'label' => $node['label'] ?? 'Sem rótulo',
                'doc_count' => $docCount
            ];
        }
        $p1Placeholders = implode(',', array_keys($p1Ids));
        $edgeMinWeightP1 = max(2, (int)round($p1MaxDoc / 100));

        // Map P2 Nodes
        $p2Ids = [];
        $p2NodeDetails = [];
        $p2MaxDoc = 1;
        foreach ($rawNodesP2 as $node) {
            $id = (int)$node['id'];
            $p2Ids[$id] = true;
            $docCount = (int)$node['doc_count'];
            $p2MaxDoc = max($p2MaxDoc, $docCount);
            $p2NodeDetails[$id] = [
                'id' => $id,
                'label' => $node['label'] ?? 'Sem rótulo',
                'doc_count' => $docCount
            ];
        }
        $p2Placeholders = implode(',', array_keys($p2Ids));
        $edgeMinWeightP2 = max(2, (int)round($p2MaxDoc / 100));

        // 3. Fetch co-occurrences for P1
        $rawEdgesP1 = $conn->fetchAllAssociative("
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
            JOIN keyword k1 ON dk1.keyword_id = k1.id
            JOIN keyword k2 ON dk2.keyword_id = k2.id
            JOIN document d ON dk1.document_id = d.id
            WHERE d.project_id = ? AND d.year <= ?
              AND COALESCE(k1.thesaurus_concept_id, k1.keyword_concept_id, k1.id) IN ($p1Placeholders)
              AND COALESCE(k2.thesaurus_concept_id, k2.keyword_concept_id, k2.id) IN ($p1Placeholders)
              AND COALESCE(k1.thesaurus_concept_id, k1.keyword_concept_id, k1.id) != COALESCE(k2.thesaurus_concept_id, k2.keyword_concept_id, k2.id)
            GROUP BY source, target
            HAVING COUNT(DISTINCT dk1.document_id) >= ?
            ORDER BY weight DESC
        ", [$projectId, $cutoffYear, $edgeMinWeightP1]);

        // 4. Fetch co-occurrences for P2
        $rawEdgesP2 = $conn->fetchAllAssociative("
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
            JOIN keyword k1 ON dk1.keyword_id = k1.id
            JOIN keyword k2 ON dk2.keyword_id = k2.id
            JOIN document d ON dk1.document_id = d.id
            WHERE d.project_id = ? AND d.year > ?
              AND COALESCE(k1.thesaurus_concept_id, k1.keyword_concept_id, k1.id) IN ($p2Placeholders)
              AND COALESCE(k2.thesaurus_concept_id, k2.keyword_concept_id, k2.id) IN ($p2Placeholders)
              AND COALESCE(k1.thesaurus_concept_id, k1.keyword_concept_id, k1.id) != COALESCE(k2.thesaurus_concept_id, k2.keyword_concept_id, k2.id)
            GROUP BY source, target
            HAVING COUNT(DISTINCT dk1.document_id) >= ?
            ORDER BY weight DESC
        ", [$projectId, $cutoffYear, $edgeMinWeightP2]);

        // 5. Cluster P1 and P2 independently using AGNES
        $communitiesP1 = $this->agglomerativeClustering($rawNodesP1, $rawEdgesP1, 8);
        $communitiesP2 = $this->agglomerativeClustering($rawNodesP2, $rawEdgesP2, 8);

        // Group P1 keywords by cluster
        $p1ClusterGroups = [];
        foreach ($rawNodesP1 as $node) {
            $id = (int)$node['id'];
            $cId = $communitiesP1[$id] ?? 0;
            $p1ClusterGroups[$cId][] = $p1NodeDetails[$id];
        }

        // Group P2 keywords by cluster
        $p2ClusterGroups = [];
        foreach ($rawNodesP2 as $node) {
            $id = (int)$node['id'];
            $cId = $communitiesP2[$id] ?? 0;
            $p2ClusterGroups[$cId][] = $p2NodeDetails[$id];
        }

        // Build Cluster Data structures for P1 and P2
        $p1Clusters = [];
        foreach ($p1ClusterGroups as $cId => $keywordsList) {
            usort($keywordsList, fn($a, $b) => $b['doc_count'] <=> $a['doc_count']);
            $p1Clusters[$cId] = [
                'label' => $keywordsList[0]['label'],
                'keywords' => $keywordsList
            ];
        }

        $p2Clusters = [];
        foreach ($p2ClusterGroups as $cId => $keywordsList) {
            usort($keywordsList, fn($a, $b) => $b['doc_count'] <=> $a['doc_count']);
            $p2Clusters[$cId] = [
                'label' => $keywordsList[0]['label'],
                'keywords' => $keywordsList
            ];
        }

        // 6. Compute flows between P1 and P2 based on keyword intersections
        $nodesSankey = [];
        $linksSankey = [];
        $details = [];

        // Build unique node names
        $registeredNodes = [];
        foreach ($p1Clusters as $c) {
            $nodeName = $c['label'] . " (P1)";
            $nodesSankey[] = ['name' => $nodeName];
            $registeredNodes[$nodeName] = true;
        }
        foreach ($p2Clusters as $c) {
            $nodeName = $c['label'] . " (P2)";
            $nodesSankey[] = ['name' => $nodeName];
            $registeredNodes[$nodeName] = true;
        }

        // Compute intersections and create links
        foreach ($p1Clusters as $c1Id => $p1Cluster) {
            $p1NodeIds = array_column($p1Cluster['keywords'], 'id');
            $p1NodeIdSet = array_flip($p1NodeIds);

            foreach ($p2Clusters as $c2Id => $p2Cluster) {
                $overlappingKeywords = [];
                foreach ($p2Cluster['keywords'] as $kw) {
                    if (isset($p1NodeIdSet[$kw['id']])) {
                        $overlappingKeywords[] = $kw;
                    }
                }

                $overlapCount = count($overlappingKeywords);
                if ($overlapCount > 0) {
                    $flowWeight = $overlapCount;
                    
                    $sourceNodeName = $p1Cluster['label'] . " (P1)";
                    $targetNodeName = $p2Cluster['label'] . " (P2)";

                    $linksSankey[] = [
                        'source' => $sourceNodeName,
                        'target' => $targetNodeName,
                        'value' => $flowWeight,
                    ];

                    $details[] = [
                        'p1_label' => $p1Cluster['label'],
                        'p2_label' => $p2Cluster['label'],
                        'value' => $flowWeight,
                        'keywords' => array_column($overlappingKeywords, 'label'),
                    ];
                }
            }
        }

        return [
            'nodes' => $nodesSankey,
            'links' => $linksSankey,
            'details' => $details,
            'meta' => [
                'p1_size' => count($rawNodesP1),
                'p2_size' => count($rawNodesP2),
            ]
        ];
    }

    /**
     * Hierarchical Cosine Similarity Agglomerative Clustering (AGNES).
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
}
