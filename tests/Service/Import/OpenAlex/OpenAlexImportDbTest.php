<?php

namespace App\Tests\Service\Import\OpenAlex;

use App\Service\Import\OpenAlex\OpenAlexCsvImporter;
use App\Service\Import\OpenAlex\OpenAlexJsonImporter;
use App\Service\Import\OpenAlex\OpenAlexRisImporter;
use App\Tests\Service\Import\ImportDbTestCase;

class OpenAlexImportDbTest extends ImportDbTestCase
{
    private const DIR = __DIR__ . '/../../../fixtures/import/openalex/';

    public function testPaginaDaApi(): void
    {
        $first = $this->newDataset('openalex');
        $stats = $this->import((new OpenAlexJsonImporter())->parseStream(self::DIR . 'api-pagina.json'), $first);
        // 10 works: os dois de mesmo título e ano entram; o segundo com o mesmo DOI é duplicado
        $this->assertSame(['read' => 10, 'imported' => 9, 'skipped' => 1, 'errors' => 0], $stats);
        $skip = $this->skips($first)[0];
        $this->assertSame('doi', $skip['reason']);
        $this->assertSame('W10', $skip['external_id']);

        $again = $this->newDataset('openalex');
        $this->assertSame(10, $this->import((new OpenAlexJsonImporter())->parseStream(self::DIR . 'api-pagina.json'), $again)['skipped']);
    }

    public function testMesmoWorkPorCsvEDepoisPorRisENaOrdemInversa(): void
    {
        // CSV primeiro (com Work ID), RIS depois (sem): o mesmo work sem DOI é reconhecido pelo título e ano
        $this->import([self::dto('openalex', 'Tese sem DOI', 2021, 'W77', null, true)], $this->newDataset('openalex'));
        $stats = $this->import((new OpenAlexRisImporter())->parseStream(self::DIR . 'openalex.ris'), $this->newDataset('openalex'));
        $this->assertSame(['read' => 3, 'imported' => 2, 'skipped' => 1, 'errors' => 0], $stats);
    }

    public function testRisAntesDoCsv(): void
    {
        $this->import((new OpenAlexRisImporter())->parseStream(self::DIR . 'openalex.ris'), $this->newDataset('openalex'));
        $stats = $this->import([self::dto('openalex', 'Tese sem DOI', 2021, 'W77', null, true), self::dto('openalex', 'Computer vision in agriculture', 2018, 'W78', '10.1016/j.compag.2018.08.001', true)], $this->newDataset('openalex'));
        $this->assertSame(2, $stats['skipped']);
    }

    public function testCsvComTitulosIguais(): void
    {
        $stats = $this->import((new OpenAlexCsvImporter())->parseStream(self::DIR . 'interface-completo.csv'), $this->newDataset('openalex'));
        $this->assertSame(['read' => 5, 'imported' => 5, 'skipped' => 0, 'errors' => 0], $stats);
    }
}
