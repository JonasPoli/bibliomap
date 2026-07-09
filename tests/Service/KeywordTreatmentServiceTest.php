<?php

namespace App\Tests\Service;

use App\Service\Import\TextNormalizer;
use App\Service\KeywordTreatment\KeywordFuzzyMatcherService;
use PHPUnit\Framework\TestCase;

class KeywordTreatmentServiceTest extends TestCase
{
    private TextNormalizer $normalizer;
    private KeywordFuzzyMatcherService $fuzzyMatcher;

    protected function setUp(): void
    {
        $this->normalizer = new TextNormalizer();
        $this->fuzzyMatcher = new KeywordFuzzyMatcherService();
    }

    public function testCleanDisplayValueAndNormalizeKeyword(): void
    {
        // 1. " Artificial intelligence" → Artificial intelligence / artificial intelligence
        $res1 = $this->normalizer->normalizeKeyword(" Artificial intelligence");
        $this->assertTrue($res1['valid']);
        $this->assertEquals("Artificial intelligence", $res1['display']);
        $this->assertEquals("artificial intelligence", $res1['normalized']);

        // 2. "_Summer Camp" → Summer Camp / summer camp
        $res2 = $this->normalizer->normalizeKeyword("_Summer Camp");
        $this->assertTrue($res2['valid']);
        $this->assertEquals("Summer Camp", $res2['display']);
        $this->assertEquals("summer camp", $res2['normalized']);

        // 3. "'Art Beyond Mechanical Reproduction'" → Art Beyond Mechanical Reproduction
        $res3 = $this->normalizer->cleanDisplayValue("'Art Beyond Mechanical Reproduction'");
        $this->assertEquals("Art Beyond Mechanical Reproduction", $res3);

        // 4. "[67]" → inválido
        $res4 = $this->normalizer->normalizeKeyword("[67]");
        $this->assertFalse($res4['valid']);
        $this->assertEquals("numeric_reference", $res4['reason']);

        // 5. "]+ catalyst" → catalyst
        $res5 = $this->normalizer->cleanDisplayValue("]+ catalyst");
        $this->assertEquals("catalyst", $res5);
    }

    public function testFuzzyMatcherAndAcronyms(): void
    {
        // 6. "Artificial inteligence" deve sugerir Artificial Intelligence com fuzzy alto
        $scoreFuzzy = $this->fuzzyMatcher->getSimilarityScore("artificial inteligence", "artificial intelligence");
        $this->assertGreaterThan(90.0, $scoreFuzzy);

        // 7. "AI" não deve ser fuzzy automático; deve depender de tesauro (score = 0)
        $scoreAcronym = $this->fuzzyMatcher->getSimilarityScore("ai", "artificial intelligence");
        $this->assertEquals(0.0, $scoreAcronym);

        // 8. "deep learning" não deve ser mesclado automaticamente com "machine learning"
        $scoreDifferent = $this->fuzzyMatcher->getSimilarityScore("deep learning", "machine learning");
        $this->assertLessThan(75.0, $scoreDifferent);
    }
}
