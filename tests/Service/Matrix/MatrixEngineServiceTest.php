<?php

namespace App\Tests\Service\Matrix;

use App\Service\Matrix\MatrixEngineService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MatrixEngineServiceTest extends TestCase
{
    #[DataProvider('keywordLabels')]
    public function testKeywordLabel(bool $thesaurus, ?string $preferred, ?string $concept, ?string $display, ?string $original, ?string $expected): void
    {
        $this->assertSame($expected, MatrixEngineService::keywordLabel($thesaurus, $preferred, $concept, $display, $original));
    }

    public static function keywordLabels(): array
    {
        return [
            'rótulo preferido do tesauro vence' => [true, 'Agricultura de precisão', 'precision agriculture', 'precision agr', 'PA', 'Agricultura de precisão'],
            'sem tesauro: forma da palavra-chave conceito' => [true, null, 'precision agriculture', 'precision agr', 'PA', 'precision agriculture'],
            'conceito vazio cai na própria palavra' => [true, '', '', 'precision agr', 'PA', 'precision agr'],
            'sem tesauro ligado: forma tratada' => [false, 'Agricultura de precisão', 'precision agriculture', 'precision agr', 'PA', 'precision agr'],
            'sem forma tratada: termo original' => [false, null, null, '', 'PA', 'PA'],
            'tudo vazio' => [true, null, null, null, null, null],
        ];
    }

    /**
     * Empate de frequência: a ordem é alfabética, não a ordem em que o banco devolveu os documentos.
     * Quatro autores com a mesma frequência, só dois cabem na linha: sempre os dois primeiros do alfabeto.
     */
    public function testTieBreakIsAlphabeticalWhateverTheRowOrder(): void
    {
        $orders = [
            [['Zeta', 'Beta'], ['Alfa', 'Omega']],
            [['Omega', 'Alfa'], ['Beta', 'Zeta']],
        ];
        $results = [];
        foreach ($orders as $docsAuthors) {
            $matrix = $this->generate($docsAuthors);
            $results[] = $matrix['rows'];
        }
        $this->assertSame(['Alfa', 'Beta'], $results[0]);
        $this->assertSame($results[0], $results[1]);
    }

    public function testCellsCountOnlyDocumentsWithBothItems(): void
    {
        $matrix = $this->generate([['Alfa', 'Beta'], ['Alfa', 'Beta'], ['Alfa', 'Gama']], 3);
        $this->assertSame(2, $matrix['matrix']['Alfa']['Beta']);
        $this->assertSame(1, $matrix['matrix']['Alfa']['Gama']);
        $this->assertSame(0, $matrix['matrix']['Alfa']['Alfa'], 'diagonal zerada');
        $this->assertSame(3, $matrix['totalDocuments']);
    }

    /** O modo esparso (usado na tela e no CSV) tem os mesmos totais e só omite as células zeradas. */
    public function testSparseModeKeepsTotalsAndDropsZeroCells(): void
    {
        $docs = [['Alfa', 'Beta'], ['Alfa', 'Beta'], ['Alfa', 'Gama'], ['Delta']];
        $dense  = $this->generate($docs, 4);
        $sparse = $this->generate($docs, 4, true);

        $this->assertSame($dense['rows'], $sparse['rows']);
        $this->assertSame($dense['rowTotals'], $sparse['rowTotals']);
        $this->assertSame($dense['colTotals'], $sparse['colTotals']);
        foreach ($dense['matrix'] as $r => $cells) {
            $this->assertEquals(array_filter($cells, fn($v) => $v > 0), $sparse["matrix"][$r]);
        }
        foreach (['totalPairs', 'activeCells', 'density', 'totalDocuments'] as $k) {
            $this->assertSame($dense[$k], $sparse[$k], $k);
        }
        $this->assertArrayNotHasKey('Gama', $sparse['matrix']['Beta'], 'célula zerada não vai na resposta');
    }

    /** @param list<list<string>> $docsAuthors autores de cada documento (ids 1..n) */
    private function generate(array $docsAuthors, int $maxRows = 2, bool $sparse = false): array
    {
        $docs = [];
        $authorRows = [];
        foreach ($docsAuthors as $i => $authors) {
            $id = $i + 1;
            $docs[] = ['id' => $id, 'year' => '2020', 'issn' => null];
            foreach ($authors as $a) {
                $authorRows[] = ['document_id' => $id, 'author_name_raw' => $a, 'canonical_name' => $a];
            }
        }
        $conn = $this->createStub(Connection::class);
        $conn->method('fetchAllAssociative')->willReturnOnConsecutiveCalls($docs, []);
        $conn->method('iterateAssociative')->willReturn(new \ArrayIterator($authorRows));
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        return (new MatrixEngineService($em))->generateMatrix(1, 'author', 'author', 1, $maxRows, $maxRows, true, $sparse);
    }
}
