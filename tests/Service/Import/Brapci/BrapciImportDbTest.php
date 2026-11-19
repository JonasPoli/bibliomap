<?php

namespace App\Tests\Service\Import\Brapci;

use App\Service\Import\Brapci\BrapciCsvImporter;
use App\Service\Import\Brapci\BrapciXlsImporter;
use App\Tests\Service\Import\ImportDbTestCase;

class BrapciImportDbTest extends ImportDbTestCase
{
    private const DIR = __DIR__ . '/../../../fixtures/import/brapci/';

    public function testCestaInteiraReimportacaoEXls(): void
    {
        $first = $this->newDataset('brapci');
        $stats = $this->import((new BrapciCsvImporter())->parseStream(self::DIR . 'cesta.csv'), $first);
        $this->assertSame(['read' => 8, 'imported' => 8, 'skipped' => 0, 'errors' => 0], $stats);
        // Os dois IDs com o mesmo título e ano viraram dois documentos, e os dois com o mesmo DOI ficaram sem DOI
        $this->assertSame(2, (int) $this->conn->fetchOne("SELECT COUNT(*) FROM document WHERE dataset_id = ? AND title = 'Título repetido'", [$first->getId()]));

        $again = $this->newDataset('brapci');
        $this->assertSame(['read' => 8, 'imported' => 0, 'skipped' => 8, 'errors' => 0], $this->import((new BrapciCsvImporter())->parseStream(self::DIR . 'cesta.csv'), $again));

        $xls = $this->newDataset('brapci');
        $this->assertSame(8, $this->import((new BrapciXlsImporter())->parseStream(self::DIR . 'cesta.xls'), $xls)['skipped']);
    }
}
