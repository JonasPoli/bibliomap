<?php

namespace App\Tests\Service\Normalize;

use App\Service\Normalize\NormalizationService;
use PHPUnit\Framework\TestCase;

/**
 * Tarefa 5.23: descartar pares pela diferença de tamanho não pode mudar nenhuma sugestão.
 * Referência: a regra original, calculada par a par sem descarte.
 */
class SimilarityPruningTest extends TestCase
{
    private function original(string $s1, string $s2): float
    {
        $s1 = strtolower(trim($s1));
        $s2 = strtolower(trim($s2));
        if ($s1 === $s2) return 1.0;
        $clean1 = preg_replace('/[^a-z0-9 ]/', '', $s1);
        $clean2 = preg_replace('/[^a-z0-9 ]/', '', $s2);
        if ($clean1 === $clean2) return 0.98;
        $maxLen = max(strlen($s1), strlen($s2));
        if ($maxLen === 0) return 1.0;
        if ($maxLen > 255) {
            similar_text($s1, $s2, $percent);
            return $percent / 100;
        }
        return 1 - (levenshtein($s1, $s2) / $maxLen);
    }

    private function randomText(int $len): string
    {
        $alphabet = 'abcdefghij -.!XYZ0123';
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= $alphabet[mt_rand(0, strlen($alphabet) - 1)];
        }
        return $out;
    }

    public function testPruningNeverChangesWhichPairsReachTheThreshold(): void
    {
        mt_srand(20261112);
        $pairs = 0;
        $pruned = 0;
        foreach ([0.5, 0.85, 0.9, 0.99] as $threshold) {
            for ($n = 0; $n < 1500; $n++) {
                $base = $this->randomText(mt_rand(0, 40));
                // metade dos pares é parecida (uma edição), metade é sorteada
                $other = mt_rand(0, 1) ? $base . $this->randomText(mt_rand(0, 6)) : $this->randomText(mt_rand(0, 40));
                $p1 = NormalizationService::prepareSimilarity($base);
                $p2 = NormalizationService::prepareSimilarity($other);

                $expected = $this->original($base, $other);
                $full = NormalizationService::similarityPrepared($p1, $p2, 0.0);
                $this->assertSame($expected, $full);

                $got = NormalizationService::similarityPrepared($p1, $p2, $threshold);
                $pairs++;
                if ($got === null) {
                    $pruned++;
                    $this->assertLessThan($threshold, $expected, "descartado mas chegaria ao limiar: '$base' x '$other' ($threshold)");
                } else {
                    $this->assertSame($expected, $got);
                }
            }
        }
        $this->assertGreaterThan(100, $pruned, 'o teste precisa exercitar o descarte');
        $this->assertGreaterThan($pruned, $pairs);
    }

    public function testBoundaryAtExactThreshold(): void
    {
        // 20 caracteres, 3 de diferença de tamanho: 1 - 3/20 = 0,85 exato no limiar (aritmética decimal)
        $a = str_repeat('a', 17);
        $b = str_repeat('a', 17) . 'bcd';
        $p1 = NormalizationService::prepareSimilarity($a);
        $p2 = NormalizationService::prepareSimilarity($b);
        $expected = $this->original($a, $b);
        $got = NormalizationService::similarityPrepared($p1, $p2, 0.85);
        $this->assertSame($expected >= 0.85, $got !== null && $got >= 0.85);
    }

    public function testCleanEqualPairsAreNeverPruned(): void
    {
        // forma limpa igual (0,98) mesmo com tamanhos muito diferentes
        $p1 = NormalizationService::prepareSimilarity('!!!!!!!!!!abcd');
        $p2 = NormalizationService::prepareSimilarity('abcd');
        $this->assertSame(0.98, NormalizationService::similarityPrepared($p1, $p2, 0.85));
    }
}
