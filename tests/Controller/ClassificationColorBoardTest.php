<?php

namespace App\Tests\Controller;

use App\Controller\ClassificationController;
use App\Entity\ClassificationGroup;
use PHPUnit\Framework\TestCase;

class ClassificationColorBoardTest extends TestCase
{
    private function makeGroup(int $id, string $name, string $type, string $color, int $position, ?string $description = null): ClassificationGroup
    {
        $g = new ClassificationGroup();
        $ref = new \ReflectionProperty(ClassificationGroup::class, 'id');
        $ref->setValue($g, $id);
        $g->setName($name)
            ->setType($type)
            ->setColor($color)
            ->setPosition($position)
            ->setDescription($description);

        return $g;
    }

    public function testAgrupaApenasGruposNormaisPorCorNormalizadaEOrdem(): void
    {
        $groups = [
            $this->makeGroup(1, 'Validador', ClassificationGroup::TYPE_VALIDATOR, '#f59e0b', 0),
            $this->makeGroup(2, 'Ruído', ClassificationGroup::TYPE_NOISE, '#ef4444', 1),
            $this->makeGroup(3, '1.1. Monitoramento', ClassificationGroup::TYPE_NORMAL, '#2563EB', 10, 'Macrodimensão 1 | Categoria 1: IA para agricultura de precisão'),
            $this->makeGroup(4, '1.2. Insumos', ClassificationGroup::TYPE_NORMAL, '#2563eb', 11, 'Macrodimensão 1 | Categoria 1: IA para agricultura de precisão'),
            $this->makeGroup(5, '2.1. Produtividade', ClassificationGroup::TYPE_NORMAL, '#2563eb', 12, 'Macrodimensão 1 | Categoria 2: IA para previsão e tomada de decisão'),
            $this->makeGroup(6, '4.1. Saúde animal', ClassificationGroup::TYPE_NORMAL, '#16a34a', 20, null),
            $this->makeGroup(7, 'Sem Classificação', ClassificationGroup::TYPE_UNCLASSIFIED, '#6b7280', 99),
        ];

        $counts = [3 => 100, 4 => 20, 5 => 80, 6 => 50];
        $labels = ['#2563EB' => '1. Inteligência aplicada aos processos produtivos'];

        $board = ClassificationController::buildColorGroups($groups, $counts, $labels);

        $this->assertCount(2, $board);

        $this->assertSame('#2563eb', $board[0]['color']);
        $this->assertSame('1. Inteligência aplicada aos processos produtivos', $board[0]['label']);
        $this->assertSame(3, $board[0]['groupsCount']);
        $this->assertSame(200, $board[0]['docsSum']);
        $this->assertCount(2, $board[0]['sections']);
        $this->assertSame('1. IA para agricultura de precisão', $board[0]['sections'][0]['title']);
        $this->assertCount(2, $board[0]['sections'][0]['groups']);
        $this->assertSame('2. IA para previsão e tomada de decisão', $board[0]['sections'][1]['title']);
        $this->assertCount(1, $board[0]['sections'][1]['groups']);

        $this->assertSame('#16a34a', $board[1]['color']);
        $this->assertSame('', $board[1]['label']);
        $this->assertSame('Agrupamento 2', $board[1]['defaultLabel']);
        $this->assertSame(1, $board[1]['groupsCount']);
        $this->assertSame(50, $board[1]['docsSum']);
        $this->assertNull($board[1]['sections'][0]['title']);
    }
}
