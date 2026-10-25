<?php

namespace App\Service\Network;

use Doctrine\ORM\EntityManagerInterface;

class ThematicMapService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Mapa temático (diagrama estratégico) de Callon (1991) / Cobo et al. (2011),
     * calculado como o thematicMap() do Bibliometrix:
     *  - nós: os N termos mais frequentes com ocorrência >= $minOccur (o limite vale só
     *    para os nós; qualquer co-ocorrência >= 1 vira ligação, como edges.min = 1);
     *  - pesos: força de associação a_ij = c_ij / (c_i × c_j) (normalize = "association");
     *  - temas: comunidades (Louvain, padrão do Bibliometrix) do grafo ponderado por a_ij,
     *    sem nós isolados (remove.isolates = TRUE);
     *  - centralidade = Σ a_ij das ligações entre termos do tema e termos de outros temas;
     *  - densidade = 100 × Σ a_ij das ligações internas ÷ nº de termos do tema.
     *
     * $yearFrom/$yearTo (inclusivos) restringem os documentos: a Evolução Temática monta o
     * mapa de cada período com este mesmo método.
     */
    public function buildThematicMap(int $projectId, string $kwType = 'author', int $minOccur = 2, int $maxKeywords = 100, ?int $yearFrom = null, ?int $yearTo = null): array
    {
        $conn = $this->em->getConnection();
        $mappedType = $kwType === 'author' ? 'author_keyword' : ($kwType === 'indexed' ? 'indexed_keyword' : $kwType);

        $yearSql    = '';
        $yearParams = [];
        if ($yearFrom !== null) { $yearSql .= ' AND d.year >= ?'; $yearParams[] = $yearFrom; }
        if ($yearTo !== null)   { $yearSql .= ' AND d.year <= ?'; $yearParams[] = $yearTo; }

        $empty = [
            'clusters' => [],
            'meta'     => [
                'avgCentrality'    => 0,
                'avgDensity'       => 0,
                'medianCentrality' => 0,
                'medianDensity'    => 0,
            ]
        ];

        // ── 1. Fetch top keywords (nodes) ────────────────────────────────────
        $rawNodes = $conn->fetchAllAssociative('
            SELECT
                IF(k.thesaurus_concept_id IS NULL, COALESCE(k.keyword_concept_id, k.id), -k.thesaurus_concept_id) AS id,
                COALESCE(tc.preferred_label, kc.keyword_display, k.keyword_display, k.keyword_original) AS label,
                COUNT(DISTINCT dk.document_id) AS doc_count
            FROM keyword k
            LEFT JOIN thesaurus_concept tc ON tc.id = k.thesaurus_concept_id
            LEFT JOIN keyword kc ON k.keyword_concept_id = kc.id
            JOIN document_keyword dk ON k.id = dk.keyword_id
            JOIN document d ON dk.document_id = d.id
            WHERE d.project_id = ? AND k.keyword_type = ?' . $yearSql . '
            GROUP BY IF(k.thesaurus_concept_id IS NULL, COALESCE(k.keyword_concept_id, k.id), -k.thesaurus_concept_id),
                     COALESCE(tc.preferred_label, kc.keyword_display, k.keyword_display, k.keyword_original)
            HAVING COUNT(DISTINCT dk.document_id) >= ?
            ORDER BY doc_count DESC, id
            LIMIT ' . (int)$maxKeywords, [$projectId, $mappedType, ...$yearParams, $minOccur]);

        if (empty($rawNodes)) {
            return $empty;
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
        // Sem limite de peso: no Bibliometrix a frequência mínima vale só para os nós.
        // Aliases src_kw/tgt_kw: "GROUP BY source" agrupava pela coluna document.source
        // (o periódico), não pelo alias, e embaralhava as co-ocorrências.
        $rawEdges = $conn->fetchAllAssociative("
            SELECT
                IF(k1.thesaurus_concept_id IS NULL, COALESCE(k1.keyword_concept_id, k1.id), -k1.thesaurus_concept_id) AS src_kw,
                IF(k2.thesaurus_concept_id IS NULL, COALESCE(k2.keyword_concept_id, k2.id), -k2.thesaurus_concept_id) AS tgt_kw,
                COUNT(DISTINCT dk1.document_id) AS weight
            FROM document_keyword dk1
            JOIN document_keyword dk2
                ON dk1.document_id = dk2.document_id
            JOIN keyword k1 ON dk1.keyword_id = k1.id
            JOIN keyword k2 ON dk2.keyword_id = k2.id
            JOIN document d ON dk1.document_id = d.id
            WHERE d.project_id = ? AND k1.keyword_type = ? AND k2.keyword_type = ?{$yearSql}
              AND IF(k1.thesaurus_concept_id IS NULL, COALESCE(k1.keyword_concept_id, k1.id), -k1.thesaurus_concept_id) < IF(k2.thesaurus_concept_id IS NULL, COALESCE(k2.keyword_concept_id, k2.id), -k2.thesaurus_concept_id)
              AND IF(k1.thesaurus_concept_id IS NULL, COALESCE(k1.keyword_concept_id, k1.id), -k1.thesaurus_concept_id) IN ($nodeIdPlaceholders)
              AND IF(k2.thesaurus_concept_id IS NULL, COALESCE(k2.keyword_concept_id, k2.id), -k2.thesaurus_concept_id) IN ($nodeIdPlaceholders)
            GROUP BY src_kw, tgt_kw
            ORDER BY weight DESC
        ", [$projectId, $mappedType, $mappedType, ...$yearParams]);

        // ── 3. Association strength edges; isolated nodes are dropped ─────────
        $edges     = [];
        $connected = [];
        foreach ($rawEdges as $edge) {
            $src = (int)$edge['src_kw'];
            $tgt = (int)$edge['tgt_kw'];
            $w   = self::associationStrength((int)$edge['weight'], $nodeDetails[$src]['doc_count'], $nodeDetails[$tgt]['doc_count']);
            if ($w <= 0.0) continue;

            $edges[] = ['source' => $src, 'target' => $tgt, 'weight' => $w];
            $connected[$src] = true;
            $connected[$tgt] = true;
        }

        $rawNodes = array_values(array_filter($rawNodes, fn($n) => isset($connected[(int)$n['id']])));
        if (empty($rawNodes)) {
            return $empty;
        }

        // ── 4. Detect communities (Louvain) on the association graph ──────────
        $communities = self::louvain(array_map(fn($n) => (int)$n['id'], $rawNodes), $edges);

        // Group keywords by cluster id
        $clusterGroups = [];
        foreach ($rawNodes as $node) {
            $id        = (int)$node['id'];
            $clusterId = $communities[$id] ?? 0;
            $clusterGroups[$clusterId][] = $nodeDetails[$id];
        }

        // ── 5. Callon centrality & density per cluster ───────────────────────
        $metrics = self::themeMetrics($communities, $edges);

        $clustersData = [];
        foreach ($clusterGroups as $clusterId => $keywordsList) {
            // Sort by frequency desc, pick main label
            usort($keywordsList, fn($a, $b) => $b['doc_count'] <=> $a['doc_count']);

            $clustersData[] = [
                'id'         => $clusterId,
                'label'      => $keywordsList[0]['label'],
                'size'       => count($keywordsList),
                'doc_count'  => array_sum(array_column($keywordsList, 'doc_count')),
                'centrality' => round($metrics[$clusterId]['centrality'] ?? 0.0, 6),
                'density'    => round($metrics[$clusterId]['density'] ?? 0.0, 6),
                'keywords'   => $keywordsList,
            ];
        }

        // Sort clusters by doc_count desc for a stable display order
        usort($clustersData, fn($a, $b) => $b['doc_count'] <=> $a['doc_count']);

        // ── 6. Meta averages and medians (axes mid-points for quadrants) ───────
        // Mediana dos valores = média dos postos usada pelo Bibliometrix (rcentrality/rdensity).
        $centralities = array_column($clustersData, 'centrality');
        $densities    = array_column($clustersData, 'density');

        $avgCentrality    = count($centralities) > 0 ? array_sum($centralities) / count($centralities) : 0;
        $avgDensity       = count($densities)    > 0 ? array_sum($densities)    / count($densities)    : 0;
        $medianCentrality = $this->calculateMedian($centralities);
        $medianDensity    = $this->calculateMedian($densities);

        return [
            'clusters' => $clustersData,
            'meta'     => [
                'avgCentrality'    => round($avgCentrality, 6),
                'avgDensity'       => round($avgDensity, 6),
                'medianCentrality' => round($medianCentrality, 6),
                'medianDensity'    => round($medianDensity, 6),
            ]
        ];
    }

    /**
     * Força de associação (Van Eck & Waltman, 2009; normalizeSimilarity type="association"
     * do Bibliometrix): co-ocorrências ÷ (ocorrências de i × ocorrências de j).
     */
    public static function associationStrength(int $cooc, int $occI, int $occJ): float
    {
        return ($cooc > 0 && $occI > 0 && $occJ > 0) ? $cooc / ($occI * $occJ) : 0.0;
    }

    /**
     * Centralidade e densidade de Callon por tema.
     *
     * @param array<int,int> $clusterOf nó => tema
     * @param array<array{source:int,target:int,weight:float}> $edges ligações não direcionadas, uma por par
     * @return array<int,array{centrality:float,density:float,size:int}>
     *
     * centralidade = Σ pesos das ligações com termos de outros temas (uma ligação externa
     * conta para os dois temas que liga); densidade = 100 × Σ pesos internos ÷ nº de termos.
     */
    public static function themeMetrics(array $clusterOf, array $edges): array
    {
        $out = [];
        foreach ($clusterOf as $clusterId) {
            $out[$clusterId] ??= ['centrality' => 0.0, 'internal' => 0.0, 'size' => 0];
            $out[$clusterId]['size']++;
        }

        foreach ($edges as $e) {
            $ca = $clusterOf[$e['source']] ?? null;
            $cb = $clusterOf[$e['target']] ?? null;
            if ($ca === null || $cb === null || $e['source'] === $e['target']) continue;

            if ($ca === $cb) {
                $out[$ca]['internal'] += $e['weight'];
            } else {
                $out[$ca]['centrality'] += $e['weight'];
                $out[$cb]['centrality'] += $e['weight'];
            }
        }

        foreach ($out as $id => $m) {
            $out[$id] = [
                'centrality' => $m['centrality'],
                'density'    => $m['size'] > 0 ? 100 * $m['internal'] / $m['size'] : 0.0,
                'size'       => $m['size'],
            ];
        }

        return $out;
    }

    // ── Louvain ───────────────────────────────────────────────────────────────
    //
    // Mesmo agrupamento padrão do thematicMap() do Bibliometrix: Louvain (Blondel et al., 2008,
    // resolução 1) sobre o grafo de força de associação, 10 execuções com ordens de visita
    // diferentes (semente fixa) e fica a de maior modularidade.
    //
    /**
     * @param int[] $nodeIds
     * @param array<array{source:int,target:int,weight:float}> $edges
     * @return array<int,int> nó => tema (0..k-1, na ordem de $nodeIds)
     */
    public static function louvain(array $nodeIds, array $edges, int $runs = 10, int $seed = 42): array
    {
        $n = count($nodeIds);
        if ($n === 0) return [];

        $idx = array_flip(array_values($nodeIds));
        $maxW = 0.0;
        foreach ($edges as $e) $maxW = max($maxW, (float)$e['weight']);

        // Pesos reescalados (máx. = 1): a modularidade não muda e evita números ínfimos
        $adj = array_fill(0, $n, []);
        foreach ($edges as $e) {
            $a = $idx[$e['source']] ?? null;
            $b = $idx[$e['target']] ?? null;
            if ($a === null || $b === null || $a === $b || $maxW <= 0) continue;
            $w = $e['weight'] / $maxW;
            $adj[$a][$b] = ($adj[$a][$b] ?? 0.0) + $w;
            $adj[$b][$a] = ($adj[$b][$a] ?? 0.0) + $w;
        }

        $best = null;
        $bestQ = -INF;
        for ($r = 0; $r < max(1, $runs); $r++) {
            $randomizer = new \Random\Randomizer(new \Random\Engine\Mt19937($seed + $r));
            $membership = self::louvainRun($adj, $randomizer);
            $q = self::modularity($adj, $membership);
            if ($best === null || $q > $bestQ + 1e-12) {
                $best  = $membership;
                $bestQ = $q;
            }
        }

        // Rótulos sequenciais pela ordem de $nodeIds
        $map = [];
        $out = [];
        foreach (array_values($nodeIds) as $i => $id) {
            $c = $best[$i];
            $map[$c] ??= count($map);
            $out[$id] = $map[$c];
        }
        return $out;
    }

    /** Modularidade de Newman (resolução 1) de uma partição; $adj simétrico, laços em $adj[i][i]. */
    public static function modularity(array $adj, array $membership): float
    {
        $m2 = 0.0;
        $in = [];
        $tot = [];
        foreach ($adj as $i => $nbrs) {
            $ci = $membership[$i];
            foreach ($nbrs as $j => $w) {
                $m2 += $w;
                $tot[$ci] = ($tot[$ci] ?? 0.0) + $w;
                if ($membership[$j] === $ci) {
                    $in[$ci] = ($in[$ci] ?? 0.0) + $w;
                }
            }
        }
        if ($m2 <= 0) return 0.0;

        $q = 0.0;
        foreach ($tot as $c => $t) {
            $q += ($in[$c] ?? 0.0) / $m2 - ($t / $m2) ** 2;
        }
        return $q;
    }

    /** Uma execução do Louvain: devolve o tema de cada índice 0..n-1. */
    private static function louvainRun(array $adj, \Random\Randomizer $randomizer): array
    {
        $n = count($adj);
        $membership = range(0, $n - 1); // nó original => tema
        $graph = $adj;

        while (true) {
            $level = self::louvainLevel($graph, $randomizer);
            $k = count(array_unique($level));
            if ($k === count($graph)) break; // nenhum nó mudou de tema

            // Renumera os temas e agrega o grafo (cada tema vira um nó, com laço interno)
            $renum = [];
            foreach ($level as $c) $renum[$c] ??= count($renum);
            foreach ($membership as $i => $c) $membership[$i] = $renum[$level[$c]];

            $agg = array_fill(0, $k, []);
            foreach ($graph as $i => $nbrs) {
                $ci = $renum[$level[$i]];
                foreach ($nbrs as $j => $w) {
                    $cj = $renum[$level[$j]];
                    $agg[$ci][$cj] = ($agg[$ci][$cj] ?? 0.0) + $w;
                }
            }
            $graph = $agg;
            if ($k === 1) break;
        }

        return $membership;
    }

    /** Fase local do Louvain: move nós para o tema vizinho de maior ganho de modularidade. */
    private static function louvainLevel(array $graph, \Random\Randomizer $randomizer): array
    {
        $n = count($graph);
        $comm = range(0, $n - 1);
        $deg = [];
        $m2 = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $deg[$i] = array_sum($graph[$i]);
            $m2 += $deg[$i];
        }
        if ($m2 <= 0) return $comm;
        $tot = $deg;

        $order = $randomizer->shuffleArray(range(0, $n - 1));
        $passes = 0;
        do {
            $moved = false;
            foreach ($order as $i) {
                $ci = $comm[$i];

                // Peso de i para cada tema vizinho (sem o laço de i)
                $toComm = [];
                foreach ($graph[$i] as $j => $w) {
                    if ($j === $i) continue;
                    $toComm[$comm[$j]] = ($toComm[$comm[$j]] ?? 0.0) + $w;
                }

                $tot[$ci] -= $deg[$i];
                $bestC = $ci;
                $bestGain = ($toComm[$ci] ?? 0.0) - $tot[$ci] * $deg[$i] / $m2;
                foreach ($toComm as $c => $kin) {
                    $gain = $kin - $tot[$c] * $deg[$i] / $m2;
                    if ($gain > $bestGain + 1e-12) {
                        $bestGain = $gain;
                        $bestC = $c;
                    }
                }
                $tot[$bestC] += $deg[$i];

                if ($bestC !== $ci) {
                    $comm[$i] = $bestC;
                    $moved = true;
                }
            }
        } while ($moved && ++$passes < 100);

        return $comm;
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
