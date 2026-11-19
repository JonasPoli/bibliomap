<?php

namespace App\Tests\Service\Import\Ebsco;

use App\DTO\BibliographicRecordDTO;
use App\Service\Import\Bdtd\BdtdCsvImporter;
use App\Service\Import\Brapci\BrapciCsvImporter;
use App\Service\Import\Ebsco\EbscoCsvImporter;
use App\Service\Import\LensCsvImporter;
use App\Service\Import\PubmedCsvImporter;
use App\Service\Import\ScopusCsvImporter;
use PHPUnit\Framework\TestCase;

class EbscoCsvImporterTest extends TestCase
{
    private const DIR = __DIR__ . '/../../../fixtures/import/ebsco/';

    /** @return list<BibliographicRecordDTO> */
    private function read(string $file): array
    {
        $out = iterator_to_array((new EbscoCsvImporter())->parseStream($file), false);
        foreach ($out as $d) {
            $this->assertInstanceOf(BibliographicRecordDTO::class, $d);
        }

        return $out;
    }

    public function testDeteccao(): void
    {
        $imp = new EbscoCsvImporter();
        $this->assertSame(1.0, $imp->detect(self::DIR . 'busca.csv'));
        $this->assertSame(1.0, $imp->detect(self::DIR . 'busca-sem-bom.csv'));
        foreach ([new ScopusCsvImporter(), new LensCsvImporter(), new PubmedCsvImporter(), new BrapciCsvImporter(), new BdtdCsvImporter()] as $other) {
            $this->assertLessThan(0.5, $other->detect(self::DIR . 'busca.csv'), $other::class);
        }
        $this->assertSame(13, $imp->countRows(self::DIR . 'busca.csv'));
        $this->assertTrue($imp->supports('csv', 'lista'));
    }

    public function testCampos(): void
    {
        $r = $this->read(self::DIR . 'busca.csv');
        $this->assertCount(13, $r);
        $a = $r[0];
        $this->assertSame('lista', $a->source);
        $this->assertSame('lih:100001', $a->externalId);
        $this->assertTrue($a->strictIdentity);
        $this->assertSame('Large language models na ciência da informação: perspectivas técnicas', $a->title);
        $this->assertSame(2026, $a->year);
        $this->assertSame('2026-01-02', $a->publicationDate);
        $this->assertSame(['Silva, Ana', 'Souza, Rui'], $a->authorNames);
        $this->assertSame('Revista Y', $a->sourceTitle);
        $this->assertSame(['31', '193', '211'], [$a->volume, $a->pageStart, $a->pageEnd]);
        $this->assertSame('1368-1613', $a->issn);
        $this->assertSame('por', $a->language);
        $this->assertCount(7, $a->indexedKeywords);
        $this->assertSame('10.47989/ir31iconf64289', $a->doi);
        $this->assertSame('https://research.ebsco.com/plink/00000000-0000-0000-0000-000000000001', $a->url);
        $this->assertSame(1, $a->position);

        $this->assertSame('Inteligência Artificial (IA): o que temos conseguido com isso?', $r[1]->title);
        $this->assertSame('Reticências no fim...', $r[2]->title);
        $this->assertSame('Título com colchete [Special Issue]', $r[3]->title);
        $this->assertSame('Resumo, com vírgula e "aspas".', $r[3]->abstractText);
        $this->assertSame('1678-4561', $r[4]->issn);
        $this->assertSame('Journal Article', $r[4]->documentType);
        $this->assertSame('por', $r[4]->language);
        $this->assertSame('2628-3875', $r[5]->issn);
        $this->assertSame('Scoping Review', $r[5]->documentType);
        $this->assertNull($r[5]->pmid);
        $this->assertSame('1646-0758', $r[6]->issn);
        $this->assertSame('e23892025', $r[6]->pageStart);
        $this->assertNull($r[6]->pageEnd);
        $this->assertSame('42385079', $r[6]->pmid);
        $this->assertNull($r[7]->abstractText);
        $this->assertNull($r[7]->doi);
        $this->assertSame([], $r[7]->indexedKeywords);
        $this->assertSame('Book Article', $r[8]->documentType);
        $this->assertSame('978-3-11-157986-3', $r[8]->isbn);
        $this->assertNull($r[8]->issn);
        $this->assertSame('aph:100001', $r[9]->externalId, 'mesmo número de acesso noutra base é outro registro');
        $this->assertSame('plink:00000000-0000-0000-0000-000000000013', $r[12]->externalId);
    }

    public function testAvisoDeOutrasBases(): void
    {
        $imp = new EbscoCsvImporter();
        $this->assertNull($imp->previewNotice(self::DIR . 'parte-a.csv'));
        $notice = $imp->previewNotice(self::DIR . 'varias-bases.csv');
        $this->assertStringStartsWith('4 dos 6 registros não são da LISTA: Academic Search Premier (3), MEDLINE Complete (1).', $notice);
    }

    public function testArquivosReais(): void
    {
        $dir = dirname(__DIR__, 4) . '/var/exemplos/lisa/';
        if (!is_file($dir . 'EBSCO-Metadata-11_19_2026.csv')) {
            $this->markTestSkipped('Sem os arquivos reais em var/exemplos.');
        }
        $ids = [];
        $withDoi = [];
        foreach (['EBSCO-Metadata-11_19_2026.csv' => 10, 'EBSCO-Metadata-11_19_2026 (1).csv' => 50, 'EBSCO-Metadata-11_19_2026 (2).csv' => 50] as $f => $n) {
            $r = $this->read($dir . $f);
            $this->assertCount($n, $r, $f);
            $withDoi[] = count(array_filter($r, fn ($d) => $d->doi !== null));
            foreach ($r as $d) {
                $ids[$d->externalId] = true;
                $this->assertFalse(str_ends_with((string) $d->title, '.') && !str_ends_with((string) $d->title, '..'), $d->title);
                $this->assertTrue($d->pmid === null || ctype_digit($d->pmid));
            }
        }
        $this->assertSame([6, 41, 44], $withDoi);
        $this->assertCount(94, $ids);
        $this->assertNull((new EbscoCsvImporter())->previewNotice($dir . 'EBSCO-Metadata-11_19_2026 (1).csv'));
        $this->assertStringStartsWith('44 dos 50 registros não são da LISTA', (new EbscoCsvImporter())->previewNotice($dir . 'EBSCO-Metadata-11_19_2026 (2).csv'));
    }
}
