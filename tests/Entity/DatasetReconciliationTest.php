<?php

namespace App\Tests\Entity;

use App\Entity\Dataset;
use PHPUnit\Framework\TestCase;

class DatasetReconciliationTest extends TestCase
{
    private function ds(int $records, ?int $read, int $imp, int $dup, int $err, ?int $expected = null): Dataset
    {
        return (new Dataset())->setRecordsCount($records)->setReadCount($read)->setImportedCount($imp)
            ->setDuplicatedCount($dup)->setErrorCount($err)->setExpectedCount($expected);
    }

    public function testContaFechada(): void
    {
        $r = $this->ds(7193, 7193, 7100, 90, 3)->getReconciliation();
        $this->assertTrue($r['closes']);
        $this->assertSame(0, $r['previewDiff']);
        $this->assertNull($r['expectedDiff']);
    }

    public function testContaQueNaoFecha(): void
    {
        $r = $this->ds(100, 100, 90, 5, 2)->getReconciliation();
        $this->assertFalse($r['closes']);
        $this->assertSame(97, $r['sum']);
    }

    public function testDatasetAntigoUsaASoma(): void
    {
        $r = $this->ds(500, null, 300, 199, 1)->getReconciliation();
        $this->assertSame(500, $r['read']);
        $this->assertTrue($r['closes']);
        $this->assertNull($r['previewDiff']);
    }

    public function testDiferencaParaAPrevia(): void
    {
        $this->assertSame(2, $this->ds(500, 498, 498, 0, 0)->getReconciliation()['previewDiff']);
    }

    public function testTotalInformadoPelaBase(): void
    {
        $this->assertSame(482, $this->ds(1000, 1000, 1000, 0, 0, 1482)->getReconciliation()['expectedDiff']);
        $this->assertSame(0, $this->ds(884, 884, 884, 0, 0, 884)->getReconciliation()['expectedDiff']);
    }
}
