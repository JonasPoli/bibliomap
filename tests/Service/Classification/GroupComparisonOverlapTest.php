<?php

namespace App\Tests\Service\Classification;

use App\Service\Classification\GroupComparisonService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class GroupComparisonOverlapTest extends TestCase
{
    private function service(array $rows): GroupComparisonService
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAllAssociative')->willReturn($rows);
        // Os nomes dos grupos vêm das próprias linhas: nenhuma consulta por par de grupos
        $conn->expects($this->never())->method('fetchOne');
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        return new GroupComparisonService($em);
    }

    private function rows(): array
    {
        return [
            ['group_id' => 1, 'group_name' => 'Lavoura', 'concept_label' => 'soil', 'freq' => 5],
            ['group_id' => 1, 'group_name' => 'Lavoura', 'concept_label' => 'crops', 'freq' => 3],
            ['group_id' => 2, 'group_name' => 'Insumos', 'concept_label' => 'soil', 'freq' => 4],
            ['group_id' => 2, 'group_name' => 'Insumos', 'concept_label' => 'drones', 'freq' => 2],
            ['group_id' => 3, 'group_name' => 'Outro', 'concept_label' => 'soil', 'freq' => 2],
        ];
    }

    public function testPairwiseJaccardUsesNamesFromRows(): void
    {
        $result = $this->service($this->rows())->getSharedKeywordOverlap(1, [1, 2, 3]);

        $this->assertCount(3, $result['pairwiseJaccard']);
        $first = $result['pairwiseJaccard'][0];
        $this->assertSame('Lavoura', $first['groupA']);
        $this->assertSame('Insumos', $first['groupB']);
        $this->assertSame(1, $first['intersection']);
        $this->assertSame(3, $first['union']);
        $this->assertSame(0.333, $first['jaccard']);
        $this->assertSame(['soil'], $first['topShared']);
        $this->assertArrayHasKey('groupKeywords', $result, 'o padrão continua devolvendo as palavras-chave por grupo');
    }

    public function testGroupKeywordsLeftOutWhenNotRequested(): void
    {
        $result = $this->service($this->rows())->getSharedKeywordOverlap(1, [1, 2, 3], false);

        $this->assertSame(['pairwiseJaccard'], array_keys($result));
        $this->assertCount(3, $result['pairwiseJaccard']);
    }
}
