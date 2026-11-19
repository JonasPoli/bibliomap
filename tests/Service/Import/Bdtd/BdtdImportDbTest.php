<?php

namespace App\Tests\Service\Import\Bdtd;

use App\Service\Import\Bdtd\BdtdCsvImporter;
use App\Service\Import\Bdtd\BdtdRisImporter;
use App\Tests\Service\Import\ImportDbTestCase;

class BdtdImportDbTest extends ImportDbTestCase
{
    private const DIR = __DIR__ . '/../../../fixtures/import/bdtd/';

    public function testCsvReimportacaoRisEOutraBase(): void
    {
        // Scopus já tem, sem DOI, o mesmo título e ano de uma das teses
        $scopus = $this->newDataset('scopus');
        $this->import([self::dto('scopus', 'Com ORCID', 2022)], $scopus);

        $csv = $this->newDataset('bdtd');
        $stats = $this->import((new BdtdCsvImporter())->parseStream(self::DIR . 'busca.csv'), $csv);
        $this->assertSame(['read' => 12, 'imported' => 11, 'skipped' => 1, 'errors' => 0], $stats);
        $this->assertSame('hash', $this->skips($csv)[0]['reason']);

        $again = $this->newDataset('bdtd');
        $this->assertSame(12, $this->import((new BdtdCsvImporter())->parseStream(self::DIR . 'busca.csv'), $again)['skipped']);

        $ris = $this->newDataset('bdtd');
        $this->assertSame(12, $this->import((new BdtdRisImporter())->parseStream(self::DIR . 'busca.ris'), $ris)['skipped']);
    }
}
