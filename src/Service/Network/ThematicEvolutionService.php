<?php

namespace App\Service\Network;

class ThematicEvolutionService
{
    public function __construct(
        private readonly ThematicMapService $thematicMapService,
    ) {}

    /**
     * Build Thematic Evolution Sankey data structure.
     *
     * Como o thematicEvolution() do Bibliometrix: os temas de cada período são os do
     * Mapa Temático calculado só com os documentos do período (mesmo agrupamento, mesma
     * ocorrência mínima), e o fluxo entre dois temas é o índice de inclusão
     * |A ∩ B| ÷ min(|A|, |B|) (Cobo et al., 2011) sobre os termos em comum.
     */
    public function buildThematicEvolution(int $projectId, string $kwType = 'author', int $cutoffYear = 2024, int $minOccur = 2, int $maxKeywords = 100): array
    {
        $map1 = $this->thematicMapService->buildThematicMap($projectId, $kwType, $minOccur, $maxKeywords, null, $cutoffYear);
        $map2 = $this->thematicMapService->buildThematicMap($projectId, $kwType, $minOccur, $maxKeywords, $cutoffYear + 1, null);

        $p1Clusters = $map1['clusters'];
        $p2Clusters = $map2['clusters'];

        $meta = [
            'p1_size'   => array_sum(array_column($p1Clusters, 'size')),
            'p2_size'   => array_sum(array_column($p2Clusters, 'size')),
            'p1_themes' => count($p1Clusters),
            'p2_themes' => count($p2Clusters),
        ];

        if (empty($p1Clusters) || empty($p2Clusters)) {
            return [
                'nodes' => [],
                'links' => [],
                'details' => [],
                'meta' => $meta,
            ];
        }

        // Nomes únicos por nó: o ECharts Sankey falha com dois nós de mesmo nome
        // (dois temas podem ter o mesmo termo principal, ex.: conceitos com o mesmo rótulo).
        $nodesSankey = [];
        $usedNames   = [];
        $nameOf      = ['P1' => [], 'P2' => []];
        foreach (['P1' => $p1Clusters, 'P2' => $p2Clusters] as $period => $clusters) {
            foreach ($clusters as $c) {
                $name = $c['label'] . " ($period)";
                for ($i = 2; isset($usedNames[$name]); $i++) {
                    $name = $c['label'] . " #$i ($period)";
                }
                $usedNames[$name] = true;
                $nameOf[$period][$c['id']] = $name;
                $nodesSankey[] = ['name' => $name, 'label' => $c['label'], 'period' => $period, 'size' => $c['size']];
            }
        }

        // Fluxos: termos em comum entre um tema de P1 e um de P2
        $linksSankey = [];
        $details     = [];
        foreach ($p1Clusters as $c1) {
            $p1Ids = array_flip(array_column($c1['keywords'], 'id'));

            foreach ($p2Clusters as $c2) {
                $shared = array_values(array_filter($c2['keywords'], fn($kw) => isset($p1Ids[$kw['id']])));
                if (empty($shared)) continue;

                $inclusion = round(self::inclusionIndex(count($shared), $c1['size'], $c2['size']), 3);
                $source    = $nameOf['P1'][$c1['id']];
                $target    = $nameOf['P2'][$c2['id']];

                $linksSankey[] = [
                    'source' => $source,
                    'target' => $target,
                    'value'  => $inclusion,
                ];

                $details[] = [
                    'source'   => $source,
                    'target'   => $target,
                    'p1_label' => $c1['label'],
                    'p2_label' => $c2['label'],
                    'value'    => $inclusion,
                    'shared'   => count($shared),
                    'p1_size'  => $c1['size'],
                    'p2_size'  => $c2['size'],
                    'keywords' => array_column($shared, 'label'),
                ];
            }
        }

        return [
            'nodes' => $nodesSankey,
            'links' => $linksSankey,
            'details' => $details,
            'meta' => $meta,
        ];
    }

    /** Índice de inclusão (Simpson): termos em comum ÷ nº de termos do menor tema. */
    public static function inclusionIndex(int $shared, int $sizeA, int $sizeB): float
    {
        $min = min($sizeA, $sizeB);
        return $min > 0 ? $shared / $min : 0.0;
    }
}
