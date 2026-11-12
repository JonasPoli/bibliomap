<?php

namespace App\Tests\Service\Analytics;

use App\Service\Analytics\CsvCell;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CsvCellTest extends TestCase
{
    #[DataProvider('cells')]
    public function testEscape(mixed $value, string $expected): void
    {
        $this->assertSame($expected, CsvCell::escape($value));
    }

    public static function cells(): array
    {
        return [
            'fórmula com ='            => ['=HYPERLINK("http://x","clique")', "'=HYPERLINK(\"http://x\",\"clique\")"],
            'fórmula com +'            => ['+1+1', "'+1+1"],
            'fórmula com -'            => ['-2+3*cmd', "'-2+3*cmd"],
            'fórmula com @'            => ['@SUM(A1)', "'@SUM(A1)"],
            'começa com tabulação'     => ["\t=1", "'\t=1"],
            'texto comum'              => ['Agricultura de precisão', 'Agricultura de precisão'],
            'sigla com hífen no meio'  => ['COVID-19', 'COVID-19'],
            'número negativo'          => ['-5', '-5'],
            'decimal com vírgula'      => ['-1,5', '-1,5'],
            'número com sinal'         => ['+12', '+12'],
            'inteiro de verdade'       => [42, '42'],
            'nulo'                     => [null, ''],
            'vazio'                    => ['', ''],
        ];
    }

    public function testUnescapeUndoesEscape(): void
    {
        foreach (['=SOMA(A1)', '+x', '-x+1', '@a', 'normal', '-7'] as $text) {
            $this->assertSame($text, CsvCell::unescape(CsvCell::escape($text)));
        }
        $this->assertSame("'quote", CsvCell::unescape("'quote"), 'apóstrofo de texto comum é preservado');
    }
}
