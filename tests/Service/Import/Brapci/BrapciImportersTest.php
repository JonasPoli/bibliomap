<?php

namespace App\Tests\Service\Import\Brapci;

use App\DTO\BibliographicRecordDTO;
use App\DTO\UnreadableRecord;
use App\Service\Import\Brapci\BrapciCsvImporter;
use App\Service\Import\Brapci\BrapciRecordMapper;
use App\Service\Import\Brapci\BrapciRisImporter;
use App\Service\Import\Brapci\BrapciXlsImporter;
use App\Service\Import\GenericRisImporter;
use App\Service\Import\LensCsvImporter;
use App\Service\Import\PubmedCsvImporter;
use App\Service\Import\ScopusCsvImporter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BrapciImportersTest extends TestCase
{
    private const DIR = __DIR__ . '/../../../fixtures/import/brapci/';

    /** @return array<string, BibliographicRecordDTO> */
    private function byId(iterable $items): array
    {
        $out = [];
        foreach ($items as $i) {
            $this->assertInstanceOf(BibliographicRecordDTO::class, $i);
            $out[$i->externalId] = $i;
        }

        return $out;
    }

    public static function legends(): array
    {
        return [
            ['BIBLOS, v. 31, n. 1, 2017', '31', '1'],
            ['Revista, n. esp., 2019', null, 'esp.'],
            ['Revista, v. 7, n. , 2026', '7', null],
            ['Revista, v. 12, n. no 12, 2010', '12', '12'],
            ['Revista, v. 12, n. nº 3, 2010', '12', '3'],
            ['Título do livro, 2015', null, null],
        ];
    }

    #[DataProvider('legends')]
    public function testVolumeENumeroDaLegenda(string $legend, ?string $vol, ?string $num): void
    {
        $this->assertSame([$vol, $num], BrapciRecordMapper::legend($legend));
    }

    public function testCsvComoABrapciEntrega(): void
    {
        $imp = new BrapciCsvImporter();
        $this->assertSame(1.0, $imp->detect(self::DIR . 'cesta.csv'));
        $this->assertSame(1.0, $imp->detect(self::DIR . 'cesta-crlf.csv'));
        $this->assertSame(8, $imp->countRows(self::DIR . 'cesta.csv'));
        $this->assertSame(8, $imp->countRows(self::DIR . 'cesta-crlf.csv'));
        foreach ([new ScopusCsvImporter(), new LensCsvImporter(), new PubmedCsvImporter()] as $other) {
            $this->assertLessThan(0.5, $other->detect(self::DIR . 'cesta.csv'), $other::class);
        }

        $r = $this->byId($imp->parseStream(self::DIR . 'cesta.csv'));
        $this->assertSame(['101', '102', '103', '104', '105', '106', '107', '108'], array_map('strval', array_keys($r)));
        $a = $r['101'];
        $this->assertSame('brapci', $a->source);
        $this->assertTrue($a->strictIdentity);
        $this->assertSame('Article', $a->documentType);
        $this->assertSame(2017, $a->year);
        $this->assertSame('Revista Alfa', $a->sourceTitle);
        $this->assertSame(['Ana Souza', 'Rui Lima'], $a->authorNames);
        $this->assertSame(['Bibliometria', 'Cientometria'], $a->authorKeywords);
        $this->assertSame('Resumo com espaço e "aspas" e p < 0,01', $a->abstractText);
        $this->assertSame(['31', '1'], [$a->volume, $a->issue]);
        $this->assertSame('10.1234/alfa.1', $a->doi);
        $this->assertSame('https://hdl.handle.net/20.500.11959/brapci/101', $a->url);
        $this->assertSame(1, $a->position);

        $this->assertNull($r['103']->doi, 'DOI malformado');
        $this->assertNull($r['104']->doi, 'DOI compartilhado por dois IDs');
        $this->assertNull($r['105']->doi, 'DOI compartilhado por dois IDs (um com ponto final)');
        $this->assertNull($r['106']->doi, 'DOI em livro');
        $this->assertSame('10.1234/delta.9', $r['108']->doi);
        $this->assertSame(['Maria Silva', 'João Souza'], $r['106']->authorNames);
        $this->assertNull($r['106']->sourceTitle);
        $this->assertNull($r['107']->title);
        $this->assertSame([], $r['107']->authorNames);
        $this->assertNull($r['108']->abstractText, '"null" sem aspas vira vazio');
        $this->assertSame(["Palavra 'citada'"], $r['108']->authorKeywords);
        $this->assertSame('Título repetido', $r['104']->title);
        $this->assertSame('Título repetido', $r['105']->title);
    }

    public function testCsvRegravadoDaOMesmoResultado(): void
    {
        $imp = new BrapciCsvImporter();
        $this->assertEquals($this->byId($imp->parseStream(self::DIR . 'cesta.csv')), $this->byId($imp->parseStream(self::DIR . 'cesta-crlf.csv')));
    }

    public function testLinhaQuebradaViraErroComPosicao(): void
    {
        $items = iterator_to_array((new BrapciCsvImporter())->parseStream(self::DIR . 'cesta-quebrada.csv'), false);
        $this->assertCount(2, $items);
        $this->assertInstanceOf(UnreadableRecord::class, $items[1]);
        $this->assertSame(2, $items[1]->position);
    }

    public function testContagemPorTipo(): void
    {
        $this->assertSame(
            ['Artigos' => 5, 'Trabalhos de eventos' => 1, 'Livros' => 1, 'Capítulos' => 1],
            (new BrapciCsvImporter())->countByType(self::DIR . 'cesta.csv')
        );
    }

    public function testXlsDaOMesmoResultadoQueOCsv(): void
    {
        $xls = new BrapciXlsImporter();
        $this->assertSame(1.0, $xls->detect(self::DIR . 'cesta.xls'));
        $this->assertSame(0.0, $xls->detect(self::DIR . 'cesta.csv'));
        $this->assertSame('Windows-1252', $xls->sourceEncoding(self::DIR . 'cesta.xls'));
        $this->assertEquals($this->byId((new BrapciCsvImporter())->parseStream(self::DIR . 'cesta.csv')), $this->byId($xls->parseStream(self::DIR . 'cesta.xls')));
        $this->assertSame('Ciência da informação e avaliação', $this->byId($xls->parseStream(self::DIR . 'cesta.xls'))['101']->title);
    }

    public function testRis(): void
    {
        $imp = new BrapciRisImporter();
        $this->assertSame(1.0, $imp->detect(self::DIR . 'cesta.ris'));
        $this->assertLessThan(1.0, (new GenericRisImporter())->detect(self::DIR . 'cesta.ris'));
        $items = iterator_to_array($imp->parseStream(self::DIR . 'cesta.ris'), false);
        $this->assertCount(3, $items);
        $this->assertSame('18014', $items[0]->externalId);
        $this->assertSame('Article', $items[0]->documentType);
        $this->assertNull($items[0]->doi);
        $this->assertInstanceOf(UnreadableRecord::class, $items[1]);
        $this->assertSame(2, $items[1]->position);
        $this->assertSame('106', $items[2]->externalId);
        $this->assertSame('O RIS da BRAPCI vem sem acentos e sem DOI. Prefira o CSV.', $imp->previewNotice(self::DIR . 'cesta.ris'));
    }

    public function testArquivosReais(): void
    {
        $root = dirname(__DIR__, 4) . '/var/exemplos/';
        $csv = $root . 'brapci/brapci_20261119-121526.csv';
        $xls = $root . 'brapci/brapci_20261119-121529.xls';
        $big = $root . 'pesquisa/brapci-todos-formatos/brapci_export_csv_1482reg_bibliometria_completo.csv';
        if (!is_file($csv) || !is_file($xls) || !is_file($big)) {
            $this->markTestSkipped('Sem os arquivos reais em var/exemplos.');
        }
        $c = $this->byId((new BrapciCsvImporter())->parseStream($csv));
        $x = $this->byId((new BrapciXlsImporter())->parseStream($xls));
        $this->assertCount(884, $c);
        $this->assertSame(array_keys($c), array_keys($x));
        $this->assertSame($c[4316]->title, $x[4316]->title);
        $this->assertSame(['Artigos' => 663, 'Trabalhos de eventos' => 211, 'Livros' => 7, 'Capítulos' => 3], (new BrapciCsvImporter())->countByType($csv));
        $this->assertCount(1482, $this->byId((new BrapciCsvImporter())->parseStream($big)));
        // Nenhum DOI repetido entre os aceitos
        $dois = array_filter(array_map(fn ($d) => $d->doi, $c));
        $this->assertSame(count($dois), count(array_unique($dois)));
    }
}
