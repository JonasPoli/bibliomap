<?php

namespace App\Tests\Service\Import\OpenAlex;

use App\DTO\BibliographicRecordDTO;
use App\DTO\UnreadableRecord;
use App\Service\Import\Bdtd\BdtdCsvImporter;
use App\Service\Import\Bdtd\BdtdRisImporter;
use App\Service\Import\Brapci\BrapciCsvImporter;
use App\Service\Import\Brapci\BrapciRisImporter;
use App\Service\Import\Ebsco\EbscoCsvImporter;
use App\Service\Import\GenericRisImporter;
use App\Service\Import\LensCsvImporter;
use App\Service\Import\OpenAlex\OpenAlexCsvImporter;
use App\Service\Import\OpenAlex\OpenAlexJsonImporter;
use App\Service\Import\OpenAlex\OpenAlexRisImporter;
use App\Service\Import\OpenAlex\OpenAlexWorkMapper;
use App\Service\Import\OpenAlex\OpenAlexWosImporter;
use App\Service\Import\PubmedCsvImporter;
use App\Service\Import\ScopusCsvImporter;
use App\Service\Import\WosImporter;
use PHPUnit\Framework\TestCase;

class OpenAlexImportersTest extends TestCase
{
    private const DIR = __DIR__ . '/../../../fixtures/import/openalex/';
    private const REAL = __DIR__ . '/../../../../var/exemplos/';

    /** @return list<BibliographicRecordDTO|UnreadableRecord> */
    private function items(object $imp, string $file): array
    {
        return iterator_to_array($imp->parseStream($file), false);
    }

    // ── Mapeador e resumo ────────────────────────────────────────────────────

    public function testResumoReconstruido(): void
    {
        $this->assertSame('In this study of this', OpenAlexWorkMapper::rebuildAbstract(['In' => [0], 'this' => [1, 4], 'study' => [2], 'of' => [3]]));
        $this->assertNull(OpenAlexWorkMapper::rebuildAbstract(null));
        $this->assertSame('a b', OpenAlexWorkMapper::rebuildAbstract(['a' => [0], 'b' => [1], 'x' => [5000000]]), 'posição absurda é ignorada');
    }

    public function testJsonNasTresFormas(): void
    {
        $imp = new OpenAlexJsonImporter();
        $all = [];
        foreach (['api-pagina.json', 'lista.json', 'linhas.json'] as $f) {
            $this->assertSame(1.0, $imp->detect(self::DIR . $f), $f);
            $this->assertSame(10, $imp->countRows(self::DIR . $f), $f);
            $all[$f] = $this->items($imp, self::DIR . $f);
        }
        $this->assertEquals($all['api-pagina.json'], $all['lista.json']);
        $this->assertEquals($all['api-pagina.json'], $all['linhas.json']);

        $tmp = tempnam(sys_get_temp_dir(), 'oa') . '.json';
        file_put_contents($tmp, '{"records":[],"resultCount":0}');
        $this->assertSame(0.0, $imp->detect($tmp));
        file_put_contents($tmp, '{"works":[{"id":18014}]}');
        $this->assertSame(0.0, $imp->detect($tmp));
        unlink($tmp);
    }

    public function testCamposDoWork(): void
    {
        [$a, $b, $c, $d, $e, $f] = $this->items(new OpenAlexJsonImporter(), self::DIR . 'api-pagina.json');
        $this->assertSame('W1', $a->externalId);
        $this->assertTrue($a->strictIdentity);
        $this->assertSame('openalex', $a->source);
        $this->assertSame('10.1234/abc.1', $a->doi);
        $this->assertSame(1680, $a->citedBy);
        $this->assertSame(['12', '1', '11', '20'], [$a->volume, $a->issue, $a->pageStart, $a->pageEnd]);
        $this->assertSame('Em Questão', $a->sourceTitle);
        $this->assertSame('1807-8893', $a->issn);
        $this->assertSame('UFRGS', $a->publisher);
        $this->assertSame(['Ana Souza', 'John Smith'], $a->authorNames);
        $this->assertSame(['Universidade Federal de Minas Gerais', 'University of Toronto'], $a->institutions);
        $this->assertSame(['BR', 'CA'], $a->countries);
        $this->assertSame(['ECI -UFMG'], $a->affiliations, 'quebra de linha da afiliação vira espaço');
        $this->assertSame(['bibliometric analysis'], $a->indexedKeywords);
        $this->assertSame(['W111', 'W222'], $a->references);
        $this->assertSame('In this study of this', $a->abstractText);
        $this->assertSame('Diamond', $a->openAccessStatus);
        $this->assertSame(1, $a->position);

        $this->assertNull($b->doi);
        $this->assertNull($b->sourceTitle);
        $this->assertSame('https://openalex.org/W2', $b->url);
        $this->assertSame([], $c->authorNames);
        $this->assertSame(0, $d->citedBy);
        $this->assertNull($d->abstractText);
        $this->assertSame(['Maria Raw Name'], $e->authorNames, 'nome "," descartado; sem nome usa o raw_author_name');
        $this->assertSame(['291', 'e268'], [$f->pageStart, $f->pageEnd]);
    }

    public function testMetaCountEItensRuins(): void
    {
        $imp = new OpenAlexJsonImporter();
        $this->assertSame(12, $imp->expectedTotal(self::DIR . 'api-pagina.json'));
        $this->assertNull($imp->expectedTotal(self::DIR . 'lista.json'));
        $this->assertSame('A busca tem 12 resultados; este arquivo traz 10. Envie as outras páginas da busca para completar.', $imp->previewNotice(self::DIR . 'api-pagina.json'));
        $items = $this->items($imp, self::DIR . 'api-com-itens-ruins.json');
        $this->assertCount(5, $items);
        $this->assertInstanceOf(UnreadableRecord::class, $items[2]);
        $this->assertSame(3, $items[2]->position);
        $this->assertInstanceOf(UnreadableRecord::class, $items[3]);
    }

    public function testJsonGrandeEmFluxo(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'oa') . '.json';
        $work = json_decode(file_get_contents(self::DIR . 'lista.json'), true)[0];
        $h = fopen($tmp, 'w');
        fwrite($h, '{"meta":{"count":20000},"results":[');
        for ($i = 1; $i <= 20000; $i++) {
            $work['id'] = 'https://openalex.org/W' . (900000 + $i);
            fwrite($h, ($i > 1 ? ',' : '') . json_encode($work));
        }
        fwrite($h, ']}');
        fclose($h);
        $before = memory_get_usage();
        $n = 0;
        foreach ((new OpenAlexJsonImporter())->parseStream($tmp) as $d) {
            $n++;
        }
        $used = (memory_get_peak_usage() - $before) / 1048576;
        unlink($tmp);
        $this->assertSame(20000, $n);
        $this->assertLessThan(64, $used);
    }

    // ── CSV da interface ─────────────────────────────────────────────────────

    public function testCsvDaInterface(): void
    {
        $imp = new OpenAlexCsvImporter();
        $f = self::DIR . 'interface-completo.csv';
        $this->assertSame(1.0, $imp->detect($f));
        foreach ([new ScopusCsvImporter(), new LensCsvImporter(), new PubmedCsvImporter(), new BrapciCsvImporter(), new BdtdCsvImporter(), new EbscoCsvImporter()] as $other) {
            $this->assertLessThan(0.5, $other->detect($f), $other::class);
        }
        $this->assertSame(5, $imp->countRows($f));
        $r = $this->items($imp, $f);
        $this->assertCount(5, $r);
        $a = $r[0];
        $this->assertSame('W100', $a->externalId);
        $this->assertSame('10.29327/abc.1', $a->doi);
        $this->assertSame(['Ana Souza', 'John Smith'], $a->authorNames);
        $this->assertSame(['Universidade Federal de Campina Grande', 'Instituto Federal do Rio Grande do Norte'], $a->institutions);
        $this->assertSame(['BR'], $a->countries);
        $this->assertSame('2764-8265', $a->issn);
        $this->assertSame(['W111', 'W222', 'W333'], $a->references);
        $this->assertSame('Diamond', $a->openAccessStatus);
        $this->assertSame(['bibliometria', 'redes'], $a->indexedKeywords);
        $this->assertNull($a->volume);
        $this->assertSame(['Universidad Complutense de Madrid, España', 'Jacinto Gómez López'], $r[1]->authorNames);
        $this->assertSame('Closed', $r[1]->openAccessStatus);
        $this->assertSame('Sem DOI, com vírgula, e "aspas"', $r[1]->title);
        $this->assertSame([], $r[2]->authorNames);
        $this->assertSame("linha 1\nlinha 2", $r[2]->abstractText);
        $this->assertNull($imp->blockingNotice($f));
        $this->assertStringContainsString('não traz volume', $imp->previewNotice($f));
    }

    public function testCsvExcelComBomEReferenciaCortada(): void
    {
        $imp = new OpenAlexCsvImporter();
        $f = self::DIR . 'interface-excel.csv';
        $this->assertSame(1.0, $imp->detect($f));
        $r = $this->items($imp, $f);
        $this->assertCount(1, $r);
        $this->assertSame('W100', $r[0]->externalId);
        foreach ($r[0]->references as $ref) {
            $this->assertSame('W12345', $ref, 'o identificador cortado no fim da célula é descartado');
        }
    }

    public function testCsvSemIdentificadorEhRecusado(): void
    {
        $imp = new OpenAlexCsvImporter();
        $f = self::DIR . 'interface-padrao.csv';
        $this->assertSame(0.9, $imp->detect($f));
        $this->assertSame('Este CSV do OpenAlex não traz Work ID nem DOI. Exporte de novo acrescentando as colunas Work ID e DOI.', $imp->blockingNotice($f));
    }

    public function testGeracoesAntigas(): void
    {
        $imp = new OpenAlexCsvImporter();
        $v2 = $this->items($imp, self::DIR . 'v2.csv')[0];
        $this->assertSame(1.0, $imp->detect(self::DIR . 'v2.csv'));
        $this->assertSame(['W200', '10.1234/g2', 2021, ['Ana Lima', 'Rui Melo'], 5, '7', 'Revista'], [$v2->externalId, $v2->doi, $v2->year, $v2->authorNames, $v2->citedBy, $v2->volume, $v2->sourceTitle]);
        $v1 = $this->items($imp, self::DIR . 'v1.csv')[0];
        $this->assertSame(1.0, $imp->detect(self::DIR . 'v1.csv'));
        $this->assertSame(['W300', 'Geração 1', 2019, ['Eva Melo', 'Luis Paz'], '3', '4'], [$v1->externalId, $v1->title, $v1->year, $v1->authorNames, $v1->volume, $v1->issue]);
    }

    // ── TXT no formato Web of Science ────────────────────────────────────────

    public function testTxtDoOpenAlexNaoEhWebOfScience(): void
    {
        $f = self::DIR . 'wos.txt';
        $this->assertSame(0.0, (new WosImporter())->detect($f));
        $this->assertSame(1.0, (new OpenAlexWosImporter())->detect($f));
        $tmp = tempnam(sys_get_temp_dir(), 'wos');
        file_put_contents($tmp, "FN Clarivate Analytics Web of Science\nVR 1.0\nPT J\nAU X\nER\n");
        $this->assertSame(1.0, (new WosImporter())->detect($tmp));
        $this->assertSame(0.0, (new OpenAlexWosImporter())->detect($tmp));
        file_put_contents($tmp, "PT J\nAU X\nTI Y\nER\n");
        $this->assertSame(0.6, (new WosImporter())->detect($tmp));
        unlink($tmp);

        $r = $this->items(new OpenAlexWosImporter(), $f);
        $this->assertCount(3, $r);
        $this->assertSame(['openalex', 'W500', true, 7], [$r[0]->source, $r[0]->externalId, $r[0]->strictIdentity, $r[0]->citedBy]);
        $this->assertSame([null, false, 15, '10.1234/txt.2', null], [$r[1]->externalId, $r[1]->strictIdentity, $r[1]->citedBy, $r[1]->doi, $r[1]->volume]);
        $this->assertSame('W502', $r[2]->externalId);
        $this->assertSame(['Eva Melo', 'John Doe'], $r[2]->authorNames);
        $this->assertNotEmpty($r[2]->countries);
    }

    // ── RIS ──────────────────────────────────────────────────────────────────

    public function testRis(): void
    {
        $imp = new OpenAlexRisImporter();
        $f = self::DIR . 'openalex.ris';
        $this->assertSame(0.7, $imp->detect($f));
        $this->assertSame(0.55, (new GenericRisImporter())->detect($f));
        $this->assertSame(0.0, (new BrapciRisImporter())->detect($f));
        $this->assertSame(0.0, (new BdtdRisImporter())->detect($f));
        $this->assertSame(0.0, $imp->detect(__DIR__ . '/../../../fixtures/import/brapci/cesta.ris'));
        $this->assertSame(0.0, $imp->detect(__DIR__ . '/../../../fixtures/import/bdtd/busca.ris'));
        $this->assertSame(0.0, $imp->detect(__DIR__ . '/../../../fixtures/import/ris/lf.ris'));

        $r = $this->items($imp, $f);
        $this->assertCount(3, $r);
        [$a, $b, $c] = $r;
        $this->assertSame('openalex', $a->source);
        $this->assertNull($a->externalId);
        $this->assertFalse($a->strictIdentity);
        $this->assertSame('10.1016/j.compag.2018.08.001', $a->doi);
        $this->assertSame(['Patrício, Diego', 'Rieder, Rafael'], $a->authorNames);
        $this->assertSame([], $a->authorKeywords);
        $this->assertSame([], $a->indexedKeywords, 'KW traz conceitos: não são importados');
        $this->assertContains('University of Passo Fundo, Passo Fundo, Rio Grande do Sul, Brazil', $a->affiliations);
        foreach ($a->affiliations as $aff) {
            $this->assertStringNotContainsString("\r", $aff);
        }
        $this->assertContains('Brazil', $a->countries);
        $this->assertSame('article', $a->documentType);
        $this->assertTrue(mb_check_encoding((string) $a->abstractText, 'UTF-8'));
        $this->assertSame('book-chapter', $b->documentType);
        $this->assertSame('dissertation', $c->documentType);
        $this->assertNull($c->doi);
        $this->assertStringStartsWith('O RIS do OpenAlex não traz citações', $imp->previewNotice($f));
    }

    // ── Arquivos reais ───────────────────────────────────────────────────────

    public function testArquivosReais(): void
    {
        $api = self::REAL . 'pesquisa/openalex-api/';
        $ui = self::REAL . 'openalex/';
        if (!is_dir($api) || !is_dir($ui)) {
            $this->markTestSkipped('Sem os arquivos reais em var/exemplos.');
        }
        $json = new OpenAlexJsonImporter();
        $ids = [];
        $noDoi = 0;
        for ($p = 1; $p <= 7; $p++) {
            foreach ($this->items($json, $api . "openalex-api-page$p.json") as $d) {
                $this->assertInstanceOf(BibliographicRecordDTO::class, $d);
                $ids[$d->externalId] = true;
                $noDoi += $d->doi === null ? 1 : 0;
                foreach ($d->authorNames as $a) {
                    $this->assertGreaterThanOrEqual(2, preg_match_all('/\pL/u', $a));
                }
            }
        }
        $this->assertCount(700, $ids);
        $this->assertSame(251, $noDoi);
        $this->assertSame(801, $json->expectedTotal($api . 'openalex-api-page1.json'));

        $csv = $this->items(new OpenAlexCsvImporter(), $ui . 'works-2026-11-19T16-08-23.csv');
        $this->assertCount(149, $csv);
        $this->assertCount(149, array_unique(array_map(fn ($d) => $d->externalId, $csv)));
        $this->assertCount(110, array_filter($csv, fn ($d) => $d->doi !== null));
        $this->assertCount(1, array_filter($csv, fn ($d) => $d->authorNames === []));
        foreach ($csv as $d) {
            foreach ($d->countries as $cc) {
                $this->assertMatchesRegularExpression('/^[A-Z]{2}$/', $cc);
            }
        }

        $ris = new OpenAlexRisImporter();
        $n = 0;
        $withDoi = 0;
        foreach ($ris->parseStream($ui . 'works-2026-04-18T01-00-07.ris') as $d) {
            $this->assertInstanceOf(BibliographicRecordDTO::class, $d);
            $n++;
            $withDoi += $d->doi !== null ? 1 : 0;
        }
        $this->assertSame(9540, $n);
        $this->assertSame(9235, $withDoi);
        $this->assertSame(0.7, $ris->detect($ui . 'works-2026-04-18T01-00-07.ris'));
    }
}
