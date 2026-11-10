<?php

namespace App\Tests\Service\Classification;

use App\Entity\BibliometricProject;
use App\Entity\ClassificationGroup;
use App\Entity\ClassificationRule;
use App\Repository\ClassificationGroupRepository;
use App\Repository\DocumentClassificationRepository;
use App\Service\Classification\ClassificationEngine;
use App\Service\Classification\ClassificationTermExpander;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/** Expansão pelo tesauro com as variações das palavras-chave, e falha que não apaga resultados. */
class ClassificationExpansionTest extends TestCase
{
    /** @var list<array{string, array}> */
    private array $queries = [];

    public function testNewModeAddsThesaurusLabelsAndActiveVariations(): void
    {
        $expander = $this->expander(
            labels: ['Remote Sensing', 'RS'],
            variations: ['Remote-Sensing', ' remote-sensing ', 'Sensoriamento Remoto'],
        );

        $terms = $expander->expand(['Remote sensing'], ClassificationTermExpander::MODE_WITH_VARIATIONS);

        $this->assertSame(['remote sensing', 'rs', 'remote-sensing', 'sensoriamento remoto'], $terms);
    }

    public function testOldModeOnlyThesaurusLabels(): void
    {
        $expander = $this->expander(labels: ['Remote Sensing', 'RS'], variations: ['remote-sensing']);

        $terms = $expander->expand(['remote sensing'], ClassificationTermExpander::MODE_THESAURUS_ONLY);

        $this->assertSame(['remote sensing', 'rs'], $terms);
        $this->assertEmpty(array_filter($this->queries, fn($q) => str_contains($q[0], 'palavra_chave_variacoes_nome')));
    }

    public function testTermWithOtherPunctuationUsesNormalizedFormAndBothStatuses(): void
    {
        $expander = $this->expander(labels: [], variations: ['elearning']);

        $terms = $expander->expand(['E-learning'], ClassificationTermExpander::MODE_WITH_VARIATIONS);

        $this->assertSame(['e-learning', 'elearning'], $terms);
        $variationQueries = array_values(array_filter($this->queries, fn($q) => str_contains($q[0], 'palavra_chave_variacoes_nome')));
        $this->assertCount(1, $variationQueries);
        [$sql, $params] = $variationQueries[0];
        $this->assertSame(['e learning'], $params, 'procura pela forma normalizada do termo');
        $this->assertStringContainsString('k.keyword_normalized = ?', $sql);
        $this->assertStringContainsString('k.status = 1', $sql, 'só palavras-chave ativas');
        $this->assertStringContainsString('v.status = 1', $sql, 'variação inativa não entra');
        $this->assertStringContainsString('v.variation_name', $sql);
    }

    public function testVariationQueryErrorIsNotSwallowed(): void
    {
        $conn = $this->createStub(Connection::class);
        $conn->method('fetchFirstColumn')->willReturnCallback(function (string $sql) {
            if (str_contains($sql, 'palavra_chave_variacoes_nome')) {
                throw new \RuntimeException('Table palavra_chave_variacoes_nome doesn\'t exist');
            }
            return [];
        });

        $this->expectException(\RuntimeException::class);
        (new ClassificationTermExpander($conn))->expand(['remote sensing']);
    }

    public function testExpansionErrorStopsRunBeforeDeletingResults(): void
    {
        $project = new BibliometricProject();
        (new \ReflectionProperty($project, 'id'))->setValue($project, 12);

        $group = (new ClassificationGroup())->setName('Hídrico')->setType(ClassificationGroup::TYPE_NORMAL)->setUseThesaurus(true);
        $group->addRule((new ClassificationRule())->setTerm('irrigation'));

        $groupRepo = $this->createStub(ClassificationGroupRepository::class);
        $groupRepo->method('findByProjectOrdered')->willReturn([$group]);

        $classRepo = $this->createMock(DocumentClassificationRepository::class);
        $classRepo->expects($this->never())->method('deleteByProject');

        $expander = $this->createStub(ClassificationTermExpander::class);
        $expander->method('expand')->willThrowException(new \RuntimeException('Erro no banco durante a expansão'));

        $engine = new ClassificationEngine($this->createStub(EntityManagerInterface::class), $groupRepo, $classRepo, $expander);

        $this->expectExceptionMessage('Erro no banco durante a expansão');
        $engine->run($project);
    }

    /**
     * @param string[] $labels rótulos do conceito encontrado (vazio = sem conceito)
     * @param string[] $variations variation_name devolvidos pela consulta das variações
     */
    private function expander(array $labels, array $variations): ClassificationTermExpander
    {
        $this->queries = [];
        $conn = $this->createStub(Connection::class);
        $conn->method('fetchFirstColumn')->willReturnCallback(function (string $sql, array $params = []) use ($labels, $variations) {
            $this->queries[] = [$sql, $params];
            return match (true) {
                str_contains($sql, 'palavra_chave_variacoes_nome') => $variations,
                str_contains($sql, 'SELECT label FROM thesaurus_label') => $labels,
                str_contains($sql, 'SELECT concept_id FROM thesaurus_label') => $labels === [] ? [] : [7],
                default => [],
            };
        });

        return new ClassificationTermExpander($conn);
    }
}
