<?php

namespace App\Service\Network;

use Doctrine\ORM\EntityManagerInterface;

class ThematicMapService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Build Thematic Map coordinates and clusters using Callon's methodology.
     *
     * Fixes applied:
     *  - Bug #1: Edge threshold now uses $minOccur directly (no dynamic scaling).
     *  - Bug #2: Clustering uses Label Propagation (handles sparse graphs correctly).
     *  - Bug #3: Density uses Callon's formula: (Σinternal_weights / n²) × 100.
     */
    public function buildThematicMap(int $projectId, string $kwType = 'author', int $minOccur = 2, int $maxKeywords = 100): array
    {
        $conn = $this->em->getConnection();
        $mappedType = $kwType === 'author' ? 'author_keyword' : ($kwType === 'indexed' ? 'indexed_keyword' : $kwType);

        // ── 1. Fetch top keywords (nodes) ────────────────────────────────────
        $rawNodes = $conn->fetchAllAssociative('
            SELECT
                k.id,
                k.keyword_display AS label,
                COUNT(dk.document_id) AS doc_count
            FROM keyword k
            JOIN document_keyword dk ON k.id = dk.keyword_id
            JOIN document d ON dk.document_id = d.id
            WHERE d.project_id = ? AND k.keyword_type = ?
            GROUP BY k.id, k.keyword_display
            HAVING COUNT(dk.document_id) >= ?
            ORDER BY doc_count DESC
            LIMIT ' . (int)$maxKeywords, [$projectId, $mappedType, $minOccur]);

        if (empty($rawNodes)) {
            return [
                'clusters' => [],
                'meta'     => [
                    'avgCentrality'    => 0,
                    'avgDensity'       => 0,
                    'medianCentrality' => 0,
                    'medianDensity'    => 0,
                ]
            ];
        }

        // Index node details by id
        $selectedNodeIds = [];
        $nodeDetails     = [];
        foreach ($rawNodes as $node) {
            $id = (int)$node['id'];
            $selectedNodeIds[$id] = true;
            $nodeDetails[$id] = [
                'id'        => $id,
                'label'     => $node['label'] ?? 'Sem rótulo',
                'doc_count' => (int)$node['doc_count'],
            ];
        }

        $nodeIdPlaceholders = implode(',', array_keys($selectedNodeIds));

        // ── 2. Fetch co-occurrence edges between selected keywords ─────────────
        // Bug #1 fix: use $minOccur directly — no dynamic scaling.
        $rawEdges = $conn->fetchAllAssociative("
            SELECT
                dk1.keyword_id AS source,
                dk2.keyword_id AS target,
                COUNT(dk1.document_id) AS weight
            FROM document_keyword dk1
            JOIN document_keyword dk2
                ON dk1.document_id = dk2.document_id
               AND dk1.keyword_id < dk2.keyword_id
            JOIN document d ON dk1.document_id = d.id
            WHERE d.project_id = ?
              AND dk1.keyword_id IN ($nodeIdPlaceholders)
              AND dk2.keyword_id IN ($nodeIdPlaceholders)
            GROUP BY dk1.keyword_id, dk2.keyword_id
            HAVING COUNT(dk1.document_id) >= ?
            ORDER BY weight DESC
        ", [$projectId, $minOccur]);

        // ── 3. Build adjacency index ──────────────────────────────────────────
        $adjacency  = [];
        $edgeWeights = [];
        foreach ($rawEdges as $edge) {
            $src = (int)$edge['source'];
            $tgt = (int)$edge['target'];
            $w   = (int)$edge['weight'];

            $edgeWeights["{$src}_{$tgt}"] = $w;
            $edgeWeights["{$tgt}_{$src}"] = $w;

            $adjacency[$src][$tgt] = $w;
            $adjacency[$tgt][$src] = $w;
        }

        // ── 4. Detect communities via Label Propagation (Bug #2 fix) ─────────
        // LPA works well on sparse graphs and produces balanced clusters.
        $communities = $this->labelPropagation($rawNodes, $rawEdges);

        // Group keywords by cluster id
        $clusterGroups = [];
        foreach ($rawNodes as $node) {
            $id        = (int)$node['id'];
            $clusterId = $communities[$id] ?? 0;
            $clusterGroups[$clusterId][] = $nodeDetails[$id];
        }

        // ── 5. Calculate Callon Centrality & Density per cluster ──────────────
        $totalExternalWeights = 0; // used later for normalisation

        $clustersRaw = [];
        foreach ($clusterGroups as $clusterId => $keywordsList) {
            $n = count($keywordsList);
            if ($n === 0) continue;

            // Sort by frequency desc, pick main label
            usort($keywordsList, fn($a, $b) => $b['doc_count'] <=> $a['doc_count']);
            $mainLabel     = $keywordsList[0]['label'];
            $totalDocCount = array_sum(array_column($keywordsList, 'doc_count'));

            $clusterNodeIds    = array_column($keywordsList, 'id');
            $clusterNodeIdSet  = array_flip($clusterNodeIds);

            $sumInternal = 0; // Σ co-occurrence weights inside the cluster
            $sumExternal = 0; // Σ co-occurrence weights outside the cluster

            foreach ($keywordsList as $kw) {
                $nodeId    = $kw['id'];
                $neighbors = $adjacency[$nodeId] ?? [];

                foreach ($neighbors as $neighborId => $weight) {
                    if (isset($clusterNodeIdSet[$neighborId])) {
                        // Internal edge — count once (only when nodeId < neighborId)
                        if ($nodeId < $neighborId) {
                            $sumInternal += $weight;
                        }
                    } else {
                        // External edge — counts for centrality
                        $sumExternal += $weight;
                    }
                }
            }

            // Bug #3 fix: Callon (1991) density formula — normalise by n²
            // density = (Σ internal_weights / n²) × 100
            $density = $n > 0 ? round(($sumInternal / ($n * $n)) * 100, 3) : 0;

            $totalExternalWeights += $sumExternal;

            $clustersRaw[] = [
                'id'          => $clusterId,
                'label'       => $mainLabel,
                'size'        => $n,
                'doc_count'   => $totalDocCount,
                'density'     => $density,
                '_extWeight'  => $sumExternal,   // raw, normalised below
                'keywords'    => $keywordsList,
            ];
        }

        // ── 6. Normalise centrality ────────────────────────────────────────────
        // Scale centrality relative to the cluster with the most external links,
        // producing values in a comparable range across clusters.
        $extWeights  = array_column($clustersRaw, '_extWeight');
        $maxExternal = !empty($extWeights) ? max($extWeights) : 0;
        $maxExternal = max($maxExternal, 1); // avoid division by zero

        $clustersData = [];
        foreach ($clustersRaw as $c) {
            $centrality = $maxExternal > 0
                ? round(($c['_extWeight'] / $maxExternal) * 100, 3)
                : 0;

            $clustersData[] = [
                'id'         => $c['id'],
                'label'      => $c['label'],
                'size'       => $c['size'],
                'doc_count'  => $c['doc_count'],
                'centrality' => $centrality,
                'density'    => $c['density'],
                'keywords'   => $c['keywords'],
            ];
        }

        // Sort clusters by doc_count desc for a stable display order
        usort($clustersData, fn($a, $b) => $b['doc_count'] <=> $a['doc_count']);

        // ── 7. Meta averages and medians (axes mid-points for quadrants) ───────
        $centralities = array_column($clustersData, 'centrality');
        $densities    = array_column($clustersData, 'density');

        $avgCentrality    = count($centralities) > 0 ? array_sum($centralities) / count($centralities) : 0;
        $avgDensity       = count($densities)    > 0 ? array_sum($densities)    / count($densities)    : 0;
        $medianCentrality = $this->calculateMedian($centralities);
        $medianDensity    = $this->calculateMedian($densities);

        return [
            'clusters' => $clustersData,
            'meta'     => [
                'avgCentrality'    => round($avgCentrality, 3),
                'avgDensity'       => round($avgDensity, 3),
                'medianCentrality' => round($medianCentrality, 3),
                'medianDensity'    => round($medianDensity, 3),
            ]
        ];
    }

    // ── Label Propagation Algorithm ───────────────────────────────────────────
    //
    // Uses Callon's Equivalence Index (EI = co² / occ_i × occ_j) as edge weight.
    // Raw co-occurrence weights cause dense-graph collapse: high-frequency keywords
    // pull all neighbours into a single cluster in the first iteration.
    // EI normalization measures *relative* connection strength, producing balanced
    // thematic clusters that reflect genuine intellectual proximity.
    //
    private function labelPropagation(array $nodes, array $edges, int $iterations = 10): array
    {
        $labels   = [];
        $adj      = [];
        $docCount = [];

        // Initialise: each node is its own community
        foreach ($nodes as $node) {
            $id          = (int)$node['id'];
            $labels[$id] = $id;
            $adj[$id]    = [];
            $docCount[$id] = max((int)($node['doc_count'] ?? 1), 1);
        }

        // Build adjacency using Equivalence Index:  EI(i,j) = co² / (occ_i × occ_j)
        foreach ($edges as $edge) {
            $src = (int)$edge['source'];
            $tgt = (int)$edge['target'];
            $co  = (int)$edge['weight'];

            $oi = $docCount[$src] ?? 1;
            $oj = $docCount[$tgt] ?? 1;
            $ei = ($co * $co) / ($oi * $oj); // Equivalence Index [0..1]

            $adj[$src][] = ['node' => $tgt, 'weight' => $ei];
            $adj[$tgt][] = ['node' => $src, 'weight' => $ei];
        }

        // Propagate labels
        for ($it = 0; $it < $iterations; $it++) {
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

        // Remap labels to sequential 0-based integers
        $uniqueLabels = array_values(array_unique($labels));
        $labelMap     = array_flip($uniqueLabels);

        $result = [];
        foreach ($labels as $nodeId => $l) {
            $result[$nodeId] = $labelMap[$l];
        }

        return $result;
    }


    // ── Median helper ─────────────────────────────────────────────────────────

    private function calculateMedian(array $numbers): float
    {
        if (empty($numbers)) {
            return 0;
        }

        sort($numbers);
        $count  = count($numbers);
        $middle = (int)($count / 2);

        if ($count % 2 === 0) {
            return ($numbers[$middle - 1] + $numbers[$middle]) / 2;
        }

        return $numbers[$middle];
    }
}
