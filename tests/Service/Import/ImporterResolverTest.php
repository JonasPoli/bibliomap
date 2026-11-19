<?php

namespace App\Tests\Service\Import;

use App\Service\Import\GenericRisImporter;
use App\Service\Import\ImporterResolver;
use App\Service\Import\LensCsvImporter;
use App\Service\Import\PubmedCsvImporter;
use App\Service\Import\PubmedNbibImporter;
use App\Service\Import\ScopusCsvImporter;
use App\Service\Import\WosImporter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class ImporterResolverTest extends TestCase
{
    private array $files = [];

    protected function tearDown(): void
    {
        array_map('unlink', $this->files);
    }

    private function file(string $ext, string $content): string
    {
        $p = tempnam(sys_get_temp_dir(), 'bm_res_') . '.' . $ext;
        file_put_contents($p, $content);
        $this->files[] = $p;

        return $p;
    }

    private function resolver(): ImporterResolver
    {
        return new ImporterResolver([
            new ScopusCsvImporter(), new WosImporter(), new LensCsvImporter(), new PubmedCsvImporter(),
            new PubmedNbibImporter(), new GenericRisImporter(),
        ], $this->createStub(EntityManagerInterface::class));
    }

    private const SCOPUS = "Authors,Title,Year,EID,Cited by,Author Keywords\n\"Silva J.\",\"T1\",2020,2-s2.0-1,3,a\n";
    private const LENS = "Lens ID,Title,Publication Year,Author/s,Citing Works Count\n001-1,T1,2020,Ana,1\n";

    public function testDetectAllDevolveAChaveDaBase(): void
    {
        $this->assertSame('lens', $this->resolver()->detectAll($this->file('csv', self::LENS))[0]['sourceKey']);
        $this->assertSame('scopus', $this->resolver()->detectAll($this->file('csv', self::SCOPUS))[0]['sourceKey']);
    }

    public function testBaseDeclaradaQueNaoCombinaComOArquivo(): void
    {
        $scopus = $this->file('csv', self::SCOPUS);
        $this->assertInstanceOf(ScopusCsvImporter::class, $this->resolver()->resolve($scopus, 'csv', 'lens'));
        $this->assertInstanceOf(ScopusCsvImporter::class, $this->resolver()->resolve($scopus, 'csv', 'scopus'));
        $this->assertInstanceOf(LensCsvImporter::class, $this->resolver()->resolve($this->file('csv', self::LENS), 'csv', 'lens'));
    }

    public function testRisDeOutraBaseVaiParaOGenerico(): void
    {
        $ris = $this->file('ris', "TY  - JOUR\nTI  - A\nER  - \n");
        $this->assertInstanceOf(GenericRisImporter::class, $this->resolver()->resolve($ris, 'ris', 'generic'));
        $this->assertInstanceOf(GenericRisImporter::class, $this->resolver()->resolve($ris, 'ris', 'scopus'));
    }

    public function testCsvDesconhecido(): void
    {
        $this->assertNull($this->resolver()->resolve($this->file('csv', "a,b\n1,2\n"), 'csv', null));
    }
}
