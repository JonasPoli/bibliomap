<?php

namespace App\Tests\Service\Network;

use App\Service\Network\ThematicEvolutionService;
use App\Service\Network\ThematicMapService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ThematicMapServiceTest extends TestCase
{
    #[DataProvider('associationCases')]
    public function testAssociationStrength(int $cooc, int $occI, int $occJ, float $expected): void
    {
        $this->assertEqualsWithDelta($expected, ThematicMapService::associationStrength($cooc, $occI, $occJ), 1e-12);
    }

    public static function associationCases(): array
    {
        return [
            'caso do projeto 13'    => [14, 53, 46, 14 / (53 * 46)],
            'sempre juntos'         => [10, 10, 10, 0.1],
            'sem co-ocorrência'     => [0, 10, 10, 0.0],
            'ocorrência zero'       => [3, 0, 10, 0.0],
        ];
    }

    public function testThemeMetricsFollowsCallonWithAssociationWeights(): void
    {
        // Tema 0 = {1,2,3}, tema 1 = {4,5}
        $clusterOf = [1 => 0, 2 => 0, 3 => 0, 4 => 1, 5 => 1];
        $edges = [
            ['source' => 1, 'target' => 2, 'weight' => 0.3],
            ['source' => 2, 'target' => 3, 'weight' => 0.3],
            ['source' => 4, 'target' => 5, 'weight' => 0.5],
            ['source' => 3, 'target' => 4, 'weight' => 0.1], // externa: conta para os dois temas
            ['source' => 1, 'target' => 5, 'weight' => 0.05],
        ];

        $m = ThematicMapService::themeMetrics($clusterOf, $edges);

        $this->assertSame(3, $m[0]['size']);
        $this->assertEqualsWithDelta(0.15, $m[0]['centrality'], 1e-12);
        $this->assertEqualsWithDelta(100 * 0.6 / 3, $m[0]['density'], 1e-12);
        $this->assertEqualsWithDelta(0.15, $m[1]['centrality'], 1e-12);
        $this->assertEqualsWithDelta(100 * 0.5 / 2, $m[1]['density'], 1e-12);
    }

    public function testDensityOfTwoTermThemeIsBounded(): void
    {
        // Antes: 117 co-ocorrências brutas ÷ 2² × 100 = 2.925. Agora: força de associação ≤ 1.
        $w = ThematicMapService::associationStrength(117, 120, 130);
        $m = ThematicMapService::themeMetrics([1 => 0, 2 => 0], [['source' => 1, 'target' => 2, 'weight' => $w]]);

        $this->assertEqualsWithDelta(100 * $w / 2, $m[0]['density'], 1e-12);
        $this->assertLessThan(1.0, $m[0]['density']);
    }

    public function testLouvainSplitsTwoCliquesJoinedByWeakBridge(): void
    {
        $edges = [];
        foreach ([[1, 2, 3, 4], [5, 6, 7, 8]] as $clique) {
            foreach ($clique as $i => $a) {
                foreach (array_slice($clique, $i + 1) as $b) {
                    $edges[] = ['source' => $a, 'target' => $b, 'weight' => 1.0];
                }
            }
        }
        $edges[] = ['source' => 4, 'target' => 5, 'weight' => 0.1];

        $p = ThematicMapService::louvain([1, 2, 3, 4, 5, 6, 7, 8], $edges);

        $this->assertSame([1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 1, 6 => 1, 7 => 1, 8 => 1], $p);
        // Determinístico
        $this->assertSame($p, ThematicMapService::louvain([1, 2, 3, 4, 5, 6, 7, 8], $edges));
    }

    public function testModularityOfPerfectSplit(): void
    {
        // Dois pares desconectados: Q = 2 × (1/2 − (1/2)²) = 0,5
        $adj = [0 => [1 => 1.0], 1 => [0 => 1.0], 2 => [3 => 1.0], 3 => [2 => 1.0]];
        $this->assertEqualsWithDelta(0.5, ThematicMapService::modularity($adj, [0, 0, 1, 1]), 1e-12);
        $this->assertEqualsWithDelta(0.0, ThematicMapService::modularity($adj, [0, 0, 0, 0]), 1e-12);
    }

    #[DataProvider('inclusionCases')]
    public function testInclusionIndex(int $shared, int $a, int $b, float $expected): void
    {
        $this->assertEqualsWithDelta($expected, ThematicEvolutionService::inclusionIndex($shared, $a, $b), 1e-12);
    }

    public static function inclusionCases(): array
    {
        return [
            'exemplo da ajuda'       => [4, 5, 20, 0.8],
            'menor contido no maior' => [3, 3, 10, 1.0],
            'projeto 13'             => [16, 24, 31, 16 / 24],
            'tema vazio'             => [0, 0, 5, 0.0],
        ];
    }

    public function testEvolutionGivesUniqueNodeNamesAndInclusionFlows(): void
    {
        $kw = fn(int $id) => ['id' => $id, 'label' => "t$id", 'doc_count' => 1];
        $cluster = fn(int $id, string $label, array $ids) => [
            'id' => $id, 'label' => $label, 'size' => count($ids), 'keywords' => array_map($kw, $ids),
        ];

        $map = $this->createStub(ThematicMapService::class);
        $map->method('buildThematicMap')->willReturnOnConsecutiveCalls(
            ['clusters' => [$cluster(0, 'ai', [1, 2, 3, 4]), $cluster(1, 'ai', [5, 6])]],
            ['clusters' => [$cluster(0, 'ai', [1, 2, 5, 9, 10])]],
        );

        $r = (new ThematicEvolutionService($map))->buildThematicEvolution(1, 'author', 2020);

        $names = array_column($r['nodes'], 'name');
        $this->assertSame(['ai (P1)', 'ai #2 (P1)', 'ai (P2)'], $names);
        $this->assertSame(['P1', 'P1', 'P2'], array_column($r['nodes'], 'period'));

        // {1,2,3,4} ∩ {1,2,5,9,10} = 2 termos; menor tema = 4 → 0,5. {5,6} ∩ ... = 1; menor = 2 → 0,5
        $this->assertSame([
            ['source' => 'ai (P1)', 'target' => 'ai (P2)', 'value' => 0.5],
            ['source' => 'ai #2 (P1)', 'target' => 'ai (P2)', 'value' => 0.5],
        ], $r['links']);
        $this->assertSame([2, 1], array_column($r['details'], 'shared'));
    }
}
