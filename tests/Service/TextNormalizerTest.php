<?php

namespace App\Tests\Service;

use App\Service\Import\TextNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TextNormalizerTest extends TestCase
{
    #[DataProvider('edgeCases')]
    public function testCleanDisplayValueKeepsBalancedBrackets(string $in, string $expected): void
    {
        $this->assertSame($expected, (new TextNormalizer())->cleanDisplayValue($in));
    }

    public function testKeywordThatLooksLikeAuthorIsFlaggedForReview(): void
    {
        $n = new TextNormalizer();
        $this->assertContains('looks_like_author', $n->normalizeKeyword('Araújo, Ítalo')['review_reasons']);
        $this->assertContains('looks_like_author', $n->normalizeKeyword('Silva, J.')['review_reasons']);
        $this->assertNotContains('looks_like_author', $n->normalizeKeyword('machine learning')['review_reasons']);
    }

    public static function edgeCases(): array
    {
        return [
            'acrônimo final'        => ['Artificial Intelligence (AI)', 'Artificial Intelligence (AI)'],
            'acrônimo + ponto'      => ['Internet of Things (IoT).', 'Internet of Things (IoT)'],
            'colchete final'        => ['deep learning [DL]', 'deep learning [DL]'],
            'parêntese sem par'     => ['machine learning)', 'machine learning'],
            'abre sem fechar'       => ['(machine learning', 'machine learning'],
            'termo todo envolvido'  => ['(machine learning)', 'machine learning'],
            'dois grupos'           => ['(AI) and (ML)', '(AI) and (ML)'],
            'sujeira nas bordas'    => [' _"Summer Camp";', 'Summer Camp'],
            'abre no fim'           => ['precision agriculture (', 'precision agriculture'],
        ];
    }
}
