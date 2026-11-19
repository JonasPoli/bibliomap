<?php

namespace App\Tests\Service\Import\Ebsco;

use App\Service\Import\Ebsco\EbscoCsvImporter;
use App\Tests\Service\Import\ImportDbTestCase;

class EbscoImportDbTest extends ImportDbTestCase
{
    private const DIR = __DIR__ . '/../../../fixtures/import/ebsco/';

    private function importFile(string $file): array
    {
        return $this->import((new EbscoCsvImporter())->parseStream(self::DIR . $file), $this->newDataset('lista'));
    }

    public function testPartesSobrepostas(): void
    {
        $this->assertSame(5, $this->importFile('parte-a.csv')['imported']);
        $this->assertSame(['read' => 5, 'imported' => 3, 'skipped' => 2, 'errors' => 0], $this->importFile('parte-b.csv'));
    }

    public function testMesmoNumeroEmBasesDiferentesEMesmoDoi(): void
    {
        // Scopus já tem, sem DOI, o artigo 2 (título sem o ponto final da EBSCO)
        $this->import([self::dto('scopus', 'Inteligência Artificial (IA): o que temos conseguido com isso?', 2025)], $this->newDataset('scopus'));
        $stats = $this->importFile('busca.csv');
        // 13 lidos: o artigo 2 já existe (título + ano de outra base); o 12 repete o DOI do 11
        $this->assertSame(['read' => 13, 'imported' => 11, 'skipped' => 2, 'errors' => 0], $stats);
        $this->assertSame(['read' => 13, 'imported' => 0, 'skipped' => 13, 'errors' => 0], $this->importFile('busca.csv'));
    }

    public function testTituloComPontoEncontraOMesmoArtigoDoScopus(): void
    {
        $this->import([self::dto('scopus', 'Artigo número 21', 2025)], $this->newDataset('scopus'));
        $this->assertSame(1, $this->importFile('parte-a.csv')['skipped']);
    }
}
