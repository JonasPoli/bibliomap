<?php

namespace App\Tests\Service\Normalize;

use App\Service\Normalize\NormalizationService;
use PHPUnit\Framework\TestCase;

/** Tarefa 5.3: `references` é palavra reservada do MySQL; o UPDATE da mescla precisa das crases. */
class NormalizationUpdateStatementTest extends TestCase
{
    public function testColunasEntreCrases(): void
    {
        [$sql, $params] = NormalizationService::buildUpdateStatement(
            'document',
            ['references' => '["a"]', 'doi' => '10.1/x', 'cited_by' => 7],
            'id',
            42
        );

        $this->assertSame('UPDATE `document` SET `references` = ?, `doi` = ?, `cited_by` = ? WHERE `id` = ?', $sql);
        $this->assertSame(['["a"]', '10.1/x', 7, 42], $params);
    }

    public function testCrasesNoNomeDaColunaSaoEscapadas(): void
    {
        [$sql] = NormalizationService::buildUpdateStatement('t', ['a`b' => 1], 'id', 1);
        $this->assertStringContainsString('`a``b` = ?', $sql);
    }

    /** Tarefa 1.21: o CSV exportado com apóstrofo antes de = + - @ volta ao texto original na importação. */
    public function testExportEscapaEImportacaoDesfaz(): void
    {
        $csv = \League\Csv\Writer::createFromString('');
        $csv->addFormatter((new \League\Csv\EscapeFormula("'"))->escapeRecord(...));
        $cells = ['=SUM(A1)', '+1', '-x', '@a', 'normal', "'quoted"];
        $csv->insertOne($cells);
        $line = trim($csv->toString());
        $this->assertSame("'=SUM(A1),'+1,'-x,'@a,normal,'quoted", $line);

        $back = array_map([NormalizationService::class, 'unescapeCsvCell'], str_getcsv($line, ',', '"', ''));
        // "'quoted" não foi alterado pela exportação a não ser pelo formatter; só prefixos = + - @ voltam
        $this->assertSame(['=SUM(A1)', '+1', '-x', '@a', 'normal', "'quoted"], $back);
    }
}
