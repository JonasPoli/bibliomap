<?php

namespace App\Tests\Service\Import;

use App\DTO\UnreadableRecord;

/**
 * Identidade pelo identificador da base e conferência dos registros lidos (mudança importacao-base-novos-formatos).
 */
class StrictIdentityDbTest extends ImportDbTestCase
{
    public function testMesmaBaseComIdsDiferentesEMesmoTituloEAnoViramDoisDocumentos(): void
    {
        $ds = $this->newDataset('brapci');
        $stats = $this->import([
            self::dto('brapci', 'Bibliometria no Brasil', 2020, '100', null, true),
            self::dto('brapci', 'Bibliometria no Brasil', 2020, '200', null, true),
        ], $ds);

        $this->assertSame(['read' => 2, 'imported' => 2, 'skipped' => 0, 'errors' => 0], $stats);
        $this->assertSame(2, $this->docCount($ds));
    }

    public function testRegistroDeOutraBaseComMesmoTituloEAnoEhDuplicado(): void
    {
        $scopus = $this->newDataset('scopus');
        $this->import([self::dto('scopus', 'Uso de metadados em bibliotecas digitais', 2025)], $scopus);
        $scopusDoc = (int) $this->conn->fetchOne('SELECT id FROM document WHERE dataset_id = ?', [$scopus->getId()]);

        $brapci = $this->newDataset('brapci');
        $stats = $this->import([self::dto('brapci', 'Uso de metadados em bibliotecas digitais', 2025, '55', null, true)], $brapci);

        $this->assertSame(1, $stats['skipped']);
        $skip = $this->skips($brapci)[0];
        $this->assertSame('hash', $skip['reason']);
        $this->assertSame($scopusDoc, (int) $skip['matched_document_id']);
        $this->assertSame('55', $skip['external_id']);
        $this->assertSame(1, (int) $skip['position']);
    }

    public function testOrdemInversaTambemUne(): void
    {
        $brapci = $this->newDataset('brapci');
        $this->import([self::dto('brapci', 'Uso de metadados em bibliotecas digitais', 2025, '55', null, true)], $brapci);

        $scopus = $this->newDataset('scopus');
        $stats = $this->import([self::dto('scopus', 'Uso de metadados em bibliotecas digitais', 2025)], $scopus);

        $this->assertSame(1, $stats['skipped']);
        $this->assertSame(0, $this->docCount($scopus));
    }

    public function testDoisLadosComDoisDoisDiferentesNaoSaoUnidos(): void
    {
        $scopus = $this->newDataset('scopus');
        $this->import([self::dto('scopus', 'Título igual', 2021, null, '10.1234/a')], $scopus);
        $oa = $this->newDataset('openalex');
        $stats = $this->import([self::dto('openalex', 'Título igual', 2021, 'W1', '10.1234/b', true)], $oa);

        $this->assertSame(1, $stats['imported']);
    }

    public function testMesmaBaseIdentificadaSoPeloTituloEDepoisPeloIdentificador(): void
    {
        // RIS do OpenAlex (sem Work ID) e depois o CSV (com Work ID), sem DOI: o mesmo work
        $ris = $this->newDataset('openalex');
        $this->import([self::dto('openalex', 'Inteligência artificial e agricultura', 2025)], $ris);
        $csv = $this->newDataset('openalex');
        $stats = $this->import([self::dto('openalex', 'Inteligência artificial e agricultura', 2025, 'W99', null, true)], $csv);
        $this->assertSame(1, $stats['skipped']);

        // Ordem inversa, noutro título
        $csv2 = $this->newDataset('openalex');
        $this->import([self::dto('openalex', 'Agricultura digital', 2024, 'W98', null, true)], $csv2);
        $ris2 = $this->newDataset('openalex');
        $stats = $this->import([self::dto('openalex', 'Agricultura digital', 2024)], $ris2);
        $this->assertSame(1, $stats['skipped']);
    }

    public function testMesmoArquivoDuasVezes(): void
    {
        $records = fn () => [
            self::dto('brapci', 'A', 2020, '1', null, true),
            self::dto('brapci', 'A', 2020, '2', null, true),
            self::dto('brapci', 'B', 2021, '3', '10.5555/x', true),
        ];
        $first = $this->newDataset('brapci');
        $this->assertSame(3, $this->import($records(), $first)['imported']);
        $second = $this->newDataset('brapci');
        $stats = $this->import($records(), $second);
        $this->assertSame(['read' => 3, 'imported' => 0, 'skipped' => 3, 'errors' => 0], $stats);
    }

    public function testErrosFicamListadosComPosicaoEMotivo(): void
    {
        $ds = $this->newDataset('brapci');
        $stats = $this->import((function () {
            yield self::dto('brapci', 'Válido', 2020, '1', null, true);
            yield null;
            yield new UnreadableRecord(3, 'linha com 5 campos', '77', 'Título da linha');
            yield self::dto('brapci', null, 2020, null, null, true);
        })(), $ds);

        $this->assertSame(['read' => 4, 'imported' => 1, 'skipped' => 0, 'errors' => 3], $stats);
        $skips = $this->skips($ds);
        $this->assertSame(['error_parse', 'error_parse', 'error_invalid'], array_column($skips, 'reason'));
        $this->assertSame([2, 3, 4], array_map('intval', array_column($skips, 'position')));
        $this->assertSame('linha com 5 campos', $skips[1]['detail']);
        $this->assertSame('77', $skips[1]['external_id']);
        $this->assertSame($stats['read'], $stats['imported'] + $stats['skipped'] + $stats['errors']);
    }

    public function testBasesAtuaisContinuamComOMesmoComportamento(): void
    {
        // Dois registros do Scopus sem DOI, mesmo título e ano: o segundo é duplicado (como sempre foi)
        $ds = $this->newDataset('scopus');
        $stats = $this->import([self::dto('scopus', 'X', 2019), self::dto('scopus', 'X', 2019)], $ds);
        $this->assertSame(1, $stats['imported']);
        $this->assertSame(1, $stats['skipped']);
    }
}
