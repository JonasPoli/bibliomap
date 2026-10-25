<?php

namespace App\Tests\Service;

use App\Entity\Keyword;
use App\Service\Import\TextNormalizer;
use App\Service\Keyword\KeywordThesaurusMatcherService;
use App\Service\KeywordTreatment\KeywordFuzzyMatcherService;
use App\Service\KeywordTreatment\KeywordTreatmentOptions;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class KeywordTreatmentFixesTest extends TestCase
{
    public function testAcronymProtectionUsesDisplayForm(): void
    {
        $m = new KeywordFuzzyMatcherService();

        // Formas normalizadas são minúsculas: sem a grafia de exibição a sigla passava
        $this->assertGreaterThan(0.0, $m->getSimilarityScore('ufmg', 'ufmt'));
        $this->assertSame(0.0, $m->getSimilarityScore('ufmg', 'ufmt', 'UFMG', 'UFMT'));
        $this->assertSame(0.0, $m->getSimilarityScore('nasa', 'nasal', 'NASA', 'nasal'));
        // Termo comum continua comparável
        $this->assertGreaterThan(90.0, $m->getSimilarityScore('artificial inteligence', 'artificial intelligence', 'artificial inteligence', 'Artificial Intelligence'));
    }

    public function testLevenshteinCountsCharactersNotBytes(): void
    {
        $m = new KeywordFuzzyMatcherService();

        // 2 substituições em 11 caracteres → 1 - 2/11 = 81,8 (por bytes dava 63,6)
        $score = $m->getSimilarityScore('ação social', 'acao social');
        $this->assertEqualsWithDelta(81.82, $score, 0.01);

        // Alfabeto não latino: 1 caractere diferente em 8
        $this->assertEqualsWithDelta(87.5, $m->getSimilarityScore('мышление', 'мышлениа'), 0.01);
    }

    /** @return iterable<string, array{array<string, string>, bool, bool}> */
    public static function stageFlagProvider(): iterable
    {
        yield 'formulário antigo sem etapas: padrão true' => [['mode' => 'dry_run'], true, true];
        yield 'checkbox desmarcado não enviado' => [['stages_submitted' => '1', 'process_exact' => '1'], false, true];
        yield 'string "false" é false' => [['process_invalids' => 'false', 'process_exact' => '0'], false, false];
        yield 'todas marcadas' => [['stages_submitted' => '1', 'process_invalids' => '1', 'process_exact' => '1'], true, true];
    }

    /** @param array<string, string> $params */
    #[DataProvider('stageFlagProvider')]
    public function testOptionsStageFlags(array $params, bool $invalids, bool $exact): void
    {
        $o = KeywordTreatmentOptions::fromRequest($params);
        $this->assertSame($invalids, $o->processInvalids);
        $this->assertSame($exact, $o->processExact);
    }

    public function testNormalizerExposesSuspiciousAsNeedsReview(): void
    {
        // KeywordTreatmentService conta suspeitos por 'needs_review' (a chave 'suspicious' não existe)
        $res = (new TextNormalizer())->normalizeKeyword('COVID-19');
        $this->assertTrue($res['valid']);
        $this->assertTrue($res['needs_review']);
        $this->assertSame(['contains_number'], $res['review_reasons']);
        $this->assertArrayNotHasKey('suspicious', $res);
    }

    public function testTwoLabelsOfSameConceptAreNotAmbiguous(): void
    {
        $matcher = $this->matcherWith(
            [['id' => 1, 'preferred_label' => 'Behavior change', 'normalized_label' => 'behavior change']],
            [
                ['normalized_label' => 'behavior change', 'label' => 'Behavior change', 'concept_id' => 1],
                ['normalized_label' => 'behaviour change', 'label' => 'Behaviour change', 'concept_id' => 1],
            ]
        );

        $kw = (new Keyword())->setKeywordNormalized('behavoir change')->setKeywordDisplay('behavoir change');
        $res = $matcher->match($kw, 95.0, 75.0);

        $this->assertSame(1, $res['conceptId']);
        $this->assertFalse($res['ambiguous']);
    }

    public function testDifferentConceptsWithCloseScoresAreAmbiguous(): void
    {
        $matcher = $this->matcherWith(
            [
                ['id' => 1, 'preferred_label' => 'Learning analytics', 'normalized_label' => 'learning analytics'],
                ['id' => 2, 'preferred_label' => 'Learning analytica', 'normalized_label' => 'learning analytica'],
            ],
            []
        );

        $kw = (new Keyword())->setKeywordNormalized('learning analytic')->setKeywordDisplay('learning analytic');
        $res = $matcher->match($kw, 95.0, 75.0);

        $this->assertTrue($res['ambiguous']);
    }

    /**
     * @param list<array<string, mixed>> $concepts
     * @param list<array<string, mixed>> $labels
     */
    private function matcherWith(array $concepts, array $labels): KeywordThesaurusMatcherService
    {
        $conn = $this->createStub(Connection::class);
        $conn->method('fetchOne')->willReturn(10);
        $conn->method('fetchAllAssociative')->willReturnCallback(
            fn(string $sql) => str_contains($sql, 'thesaurus_label') ? $labels : $concepts
        );
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        return new KeywordThesaurusMatcherService($em, new KeywordFuzzyMatcherService());
    }
}
