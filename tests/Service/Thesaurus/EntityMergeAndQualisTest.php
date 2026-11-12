<?php

namespace App\Tests\Service\Thesaurus;

use App\Command\QualisResolveMissingCommand;
use App\Entity\Keyword;
use App\Service\Keyword\KeywordThesaurusMatcherService;
use App\Service\KeywordTreatment\KeywordFuzzyMatcherService;
use App\Service\Qualis\SucupiraScraperService;
use App\Service\Thesaurus\EntityMergeService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Lógica sem banco da revisão de 12/11/2026: grafias recriadas na fusão, chave do ISSN na
 * rotina do Qualis, leitura da tabela da Sucupira e correspondência exata do tesauro.
 */
class EntityMergeAndQualisTest extends TestCase
{
    public function testNewVariationStringsKeepsSourceSpellingsOnce(): void
    {
        $new = EntityMergeService::newVariationStrings(
            ['UFSCar', 'Univ Fed Sao Carlos', 'UNIV FED SAO CARLOS', '  ', 'Federal University of São Carlos'],
            ['ufscar' => true]
        );

        // O que o destino já tem e a grafia repetida não entram; as demais são recriadas no destino
        $this->assertSame(['Univ Fed Sao Carlos', 'Federal University of São Carlos'], array_values($new));
    }

    public function testIssnKeyIsLowercaseAndSameIssnIsCollectedOnce(): void
    {
        $issns = QualisResolveMissingCommand::collectIssns(['1234-567X', '1234567x', ' 0001-0002 ', '-', null, '123']);

        $this->assertSame(['1234567x' => '1234-567X', '00010002' => '0001-0002'], $issns);
        foreach (array_keys($issns) as $key) {
            $this->assertSame(EntityMergeService::normalizeIssn((string) $key), (string) $key);
        }
    }

    public function testSucupiraRowsOnlyWithValidQualis(): void
    {
        $html = '<table><tr><th>ISSN</th></tr>'
            . '<tr><td>1234-567X</td><td>Revista A</td><td>EDUCAÇÃO</td><td> a2 </td><td>EDUCAÇÃO</td></tr>'
            . '<tr><td>1234-567X</td><td>Revista A</td><td>ENSINO</td><td>-</td></tr></table>';

        $rows = SucupiraScraperService::parseRows($html);

        $this->assertCount(1, $rows);
        $this->assertSame('A2', $rows[0]['qualis']);
        $this->assertSame('Revista A', $rows[0]['title']);
    }

    public function testMatchExactAgreesWithMatchAndSkipsFuzzy(): void
    {
        $conn = $this->createStub(Connection::class);
        $conn->method('fetchOne')->willReturn(10);
        $conn->method('fetchAllAssociative')->willReturnCallback(fn(string $sql) => str_contains($sql, 'thesaurus_label')
            ? [['normalized_label' => 'ai', 'label' => 'AI', 'concept_id' => 1]]
            : [['id' => 1, 'preferred_label' => 'Artificial Intelligence', 'normalized_label' => 'artificial intelligence']]);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);
        $matcher = new KeywordThesaurusMatcherService($em, new KeywordFuzzyMatcherService());

        $label = (new Keyword())->setKeywordNormalized('ai')->setKeywordDisplay('AI');
        $concept = (new Keyword())->setKeywordNormalized('artificial intelligence')->setKeywordDisplay('Artificial intelligence');
        $near = (new Keyword())->setKeywordNormalized('artificial inteligence')->setKeywordDisplay('Artificial inteligence');

        $this->assertSame('exact_label', $matcher->matchExact($label)['method']);
        $this->assertSame('exact_concept', $matcher->matchExact($concept)['method']);
        $this->assertSame($matcher->matchExact($concept), $matcher->match($concept));
        $this->assertNull($matcher->matchExact($near));
        $this->assertSame(1, $matcher->match($near)['conceptId']);
        $this->assertContains($matcher->match($near)['method'], ['fuzzy', 'fuzzy_auto']);
    }
}
