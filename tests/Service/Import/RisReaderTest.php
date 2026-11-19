<?php

namespace App\Tests\Service\Import;

use App\DTO\BibliographicRecordDTO;
use App\DTO\UnreadableRecord;
use App\Service\Import\GenericRisImporter;
use App\Service\Import\Ris\RisReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RisReaderTest extends TestCase
{
    private const DIR = __DIR__ . '/../../fixtures/import/ris/';

    public static function sameContent(): array
    {
        return [['lf.ris'], ['crlf.ris'], ['cr-only.ris'], ['bom.ris']];
    }

    #[DataProvider('sameContent')]
    public function testQuebrasDeLinhaEBomDaoOMesmoResultado(string $file): void
    {
        $reader = new RisReader();
        $recs = iterator_to_array($reader->records(self::DIR . $file), false);
        $this->assertCount(3, $recs);
        $this->assertSame(3, $reader->count(self::DIR . $file));
        $this->assertSame(['JOUR'], $recs[0]['fields']['TY']);
        $this->assertSame(['Silva, Ana', 'Souza, Rui'], $recs[0]['fields']['AU']);
        $this->assertSame(["Resumo em\nduas linhas"], $recs[0]['fields']['AB']);
        $this->assertSame([1, 2, 3], array_column($recs, 'position'));
    }

    public function testContinuacaoERegistroSemErNoFim(): void
    {
        $recs = iterator_to_array((new RisReader())->records(self::DIR . 'continuacao.ris'), false);
        $this->assertCount(2, $recs);
        $this->assertSame(["linha 1\nlinha 2\nlinha 3"], $recs[0]['fields']['AB']);
        $this->assertSame(['B'], $recs[1]['fields']['TI']);
    }

    public function testTySemErFechaORegistroAnterior(): void
    {
        $recs = iterator_to_array((new RisReader())->records(self::DIR . 'ty-sem-er.ris'), false);
        $this->assertSame(['A', 'B'], [$recs[0]['fields']['TI'][0], $recs[1]['fields']['TI'][0]]);
    }

    public function testByteInvalidoNaoDerrubaORegistro(): void
    {
        $recs = iterator_to_array((new RisReader())->records(self::DIR . 'byte-invalido.ris'), false);
        $this->assertCount(1, $recs);
        $this->assertTrue(mb_check_encoding($recs[0]['fields']['TI'][0], 'UTF-8'));
        $this->assertStringStartsWith('Caf', $recs[0]['fields']['TI'][0]);
    }

    public function testRisGenerico(): void
    {
        $imp = new GenericRisImporter();
        $this->assertSame(0.55, $imp->detect(self::DIR . 'lf.ris'));
        $this->assertSame(0.0, $imp->detect(__FILE__));
        $this->assertTrue($imp->supports('ris', 'generic'));
        $this->assertFalse($imp->supports('ris', 'scopus'));

        $items = iterator_to_array($imp->parseStream(self::DIR . 'lf.ris'), false);
        $this->assertCount(3, $items);
        [$a, $b, $c] = $items;
        $this->assertInstanceOf(BibliographicRecordDTO::class, $a);
        $this->assertSame('Primeiro artigo', $a->title);
        $this->assertSame(['Silva, Ana', 'Souza, Rui'], $a->authorNames);
        $this->assertSame(2019, $a->year);
        $this->assertSame('Revista Teste', $a->sourceTitle);
        $this->assertSame('1234-567X', $a->issn);
        $this->assertSame('10.1234/abc.1', $a->doi);
        $this->assertSame('Resumo em duas linhas', $a->abstractText);
        $this->assertSame(['bibliometria', 'redes'], $a->authorKeywords);
        $this->assertSame('generic', $a->source);
        $this->assertFalse($a->strictIdentity);
        $this->assertSame(1, $a->position);

        $this->assertSame('Segundo artigo', $b->title);
        $this->assertSame(2014, $b->year);
        $this->assertNull($b->url, 'endereço com esquema inválido é descartado');

        $this->assertNull($c->issn);
        $this->assertSame('9788512345678', $c->isbn);
        $this->assertSame(3, $imp->countRows(self::DIR . 'lf.ris'));
    }

    public function testRegistroSemCamposViraErroComPosicao(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'ris');
        file_put_contents($file, "TY  - JOUR\nTI  - Ok\nER  - \nTY  - JOUR\nER  - \n");
        $imp = new class extends GenericRisImporter {
            protected function mapFields(array $fields, int $position): ?BibliographicRecordDTO
            {
                return isset($fields['TI']) ? parent::mapFields($fields, $position) : null;
            }
        };
        $items = iterator_to_array($imp->parseStream($file), false);
        unlink($file);
        $this->assertInstanceOf(BibliographicRecordDTO::class, $items[0]);
        $this->assertInstanceOf(UnreadableRecord::class, $items[1]);
        $this->assertSame(2, $items[1]->position);
    }

    public function testArquivoRealDaBdtdEDaBrapci(): void
    {
        $bdtdZip = dirname(__DIR__, 3) . '/var/exemplos/bdtb/search_result--1705555757-ris.zip';
        $brapci = dirname(__DIR__, 3) . '/var/exemplos/pesquisa/brapci-todos-formatos/brapci_export_ris_73reg_bibliometria.ris';
        if (!is_file($bdtdZip) || !is_file($brapci)) {
            $this->markTestSkipped('Sem os arquivos reais em var/exemplos.');
        }
        $this->assertSame(73, (new RisReader())->count($brapci));
        $dir = sys_get_temp_dir() . '/bm_ris_' . uniqid();
        $x = (new \App\Service\Import\ZipUploadExtractor())->extract($bdtdZip, $dir);
        $this->assertSame(7193, (new RisReader())->count($x['path']));
        unlink($x['path']);
        rmdir($dir);
    }
}
