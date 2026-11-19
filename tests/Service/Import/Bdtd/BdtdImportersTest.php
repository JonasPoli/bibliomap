<?php

namespace App\Tests\Service\Import\Bdtd;

use App\DTO\BibliographicRecordDTO;
use App\Service\Import\Bdtd\BdtdCsvImporter;
use App\Service\Import\Bdtd\BdtdFieldCleaner;
use App\Service\Import\Bdtd\BdtdRisImporter;
use App\Service\Import\Brapci\BrapciCsvImporter;
use App\Service\Import\GenericRisImporter;
use App\Service\Import\LensCsvImporter;
use App\Service\Import\PubmedCsvImporter;
use App\Service\Import\ScopusCsvImporter;
use App\Service\Import\ZipUploadExtractor;
use PHPUnit\Framework\TestCase;

class BdtdImportersTest extends TestCase
{
    private const DIR = __DIR__ . '/../../../fixtures/import/bdtd/';

    /** @return list<BibliographicRecordDTO> */
    private function read(object $imp, string $file): array
    {
        $out = iterator_to_array($imp->parseStream($file), false);
        foreach ($out as $d) {
            $this->assertInstanceOf(BibliographicRecordDTO::class, $d);
        }

        return $out;
    }

    public function testLimpador(): void
    {
        $this->assertNull(BdtdFieldCleaner::value('  Não informado pela instituição '));
        $this->assertNull(BdtdFieldCleaner::value('NÃO INFORMADO PELA INSTITUIÇÃO'));
        $this->assertSame(['a', 'b'], BdtdFieldCleaner::list('a||Não informado pela instituição||b||a'));
        $this->assertSame('A RESPONSABILIDADE', BdtdFieldCleaner::stripLangTag('[pt] A RESPONSABILIDADE'));
        $this->assertSame('Barros, Eduardo', BdtdFieldCleaner::author(['Barros, Eduardo', 'Pires, E., 1973-', '2024-10-10']));
        $this->assertSame('Moura, Juliana', BdtdFieldCleaner::author(['Moura, Juliana', 'https://orcid.org/0000-0000-0000-0000']));
        $this->assertSame('Amado, Nina', BdtdFieldCleaner::author(['Amado, Nina', 'nice@x.com']));
        $this->assertSame('Gaspar, Emerson', BdtdFieldCleaner::author(['Gaspar, Emerson, 1975-']));
        $this->assertSame('Matos, Michel', BdtdFieldCleaner::author(['Matos, Michel [UNESP]']));
        $this->assertNull(BdtdFieldCleaner::author(['Não informado pela instituição']));
        $this->assertSame('por', BdtdFieldCleaner::language(['pt_BR']));
        $this->assertSame('eng', BdtdFieldCleaner::language(['en_US', 'por']));
        $this->assertSame(['url' => 'https://a.br/1', 'doi' => '10.17771/pucrio.acad.61871'],
            BdtdFieldCleaner::links(['https://a.br/1', 'https://a.br/2', 'http://doi.org/10.17771/PUCRio.acad.61871']));
        $this->assertSame(['url' => 'http://doi.org/10.1234/x', 'doi' => '10.1234/x'], BdtdFieldCleaner::links(['http://doi.org/10.1234/x']));
        $this->assertSame(['url' => null, 'doi' => null], BdtdFieldCleaner::links(['(Broch.)', 'javascript:alert(1)']));
        // Mesma chave com o link separado por "||" (CSV) ou "; " (RIS), e com "não informado" ou vazio
        $this->assertSame(BdtdFieldCleaner::rowKey('A', 'T', '2020', 'https://a||https://b'), BdtdFieldCleaner::rowKey('A', 'T', '2020', 'https://a; https://b'));
        $this->assertSame(BdtdFieldCleaner::rowKey('Não informado pela instituição', 'T', '2020', 'Não informado pela instituição'), BdtdFieldCleaner::rowKey('', 'T', '2020', ''));
        $this->assertNotSame(BdtdFieldCleaner::rowKey('A', 'T', '2020', 'https://a'), BdtdFieldCleaner::rowKey('A', 'T', '2020', 'https://b'));
    }

    public function testCsv(): void
    {
        $imp = new BdtdCsvImporter();
        $f = self::DIR . 'busca.csv';
        $this->assertSame(1.0, $imp->detect($f));
        foreach ([new ScopusCsvImporter(), new LensCsvImporter(), new PubmedCsvImporter(), new BrapciCsvImporter()] as $other) {
            $this->assertLessThan(0.5, $other->detect($f), $other::class);
        }
        $this->assertSame(12, $imp->countRows($f));
        $r = $this->read($imp, $f);
        $this->assertCount(12, $r);

        $a = $r[0];
        $this->assertSame(['Ortega, Mariana Teste'], $a->authorNames);
        $this->assertSame(2023, $a->year);
        $this->assertSame('Dissertação (mestrado)', $a->documentType);
        $this->assertSame(['Universidade Nove de Julho'], $a->institutions);
        $this->assertSame('Universidade Nove de Julho', $a->publisher);
        $this->assertSame(['Brasil'], $a->countries);
        $this->assertSame(['Inteligência artificial', 'Direito penal', 'Artificial intelligence'], $a->authorKeywords);
        $this->assertSame("Resumo com 'aspas' e", $a->abstractText, 'entidades convertidas e marcação removida');
        $this->assertSame('bdtd', $a->source);
        $this->assertTrue($a->strictIdentity);

        $b = $r[1];
        $this->assertSame([], $b->authorNames);
        $this->assertSame(['Brasil'], $b->countries, 'país não informado vira Brasil');
        $this->assertSame([], $b->authorKeywords);
        $this->assertNull($b->url);
        $this->assertSame('Tese (doutorado)', $b->documentType);

        $c = $r[2];
        $this->assertSame(['Barros, Eduardo Teste'], $c->authorNames);
        $this->assertSame('A RESPONSABILIDADE CIVIL', $c->title);
        $this->assertSame(['TECNOLOGIA', 'TECHNOLOGY'], $c->authorKeywords);
        $this->assertSame('por', $c->language);
        $this->assertSame('A presente tese', $c->abstractText);
        $this->assertSame('10.17771/pucrio.acad.61871', $c->doi);
        $this->assertStringContainsString('idi=1', $c->url);
        $this->assertSame('Embargoed', $c->openAccessStatus);

        $this->assertSame(['Moura, Juliana'], $r[3]->authorNames);
        $this->assertSame('eng', $r[3]->language);
        $this->assertNull($r[3]->url, '(Broch.) não é endereço');
        $this->assertSame(['Amado, Nina'], $r[4]->authorNames);
        $this->assertSame(['Estados Unidos'], $r[4]->countries);
        $this->assertSame('Só o resumo em português', $r[4]->abstractText);
        $this->assertSame(['Gaspar, Emerson'], $r[5]->authorNames);
        $this->assertSame(['Matos, Michel'], $r[6]->authorNames);
        $this->assertNull($r[7]->title);
        $this->assertSame([], $r[7]->authorNames);
        $this->assertNotNull($r[7]->externalId);

        // Duas linhas que só diferem na sigla: mesma chave + número de ordem; link diferente: outra chave
        $this->assertSame($r[8]->externalId . '#2', $r[9]->externalId);
        $this->assertNotSame($r[8]->externalId, $r[10]->externalId);
        $this->assertSame('Título repetido, com vírgula e "aspas"', $r[8]->title);
        $this->assertSame('Título terminado em barra \\"', $r[11]->title);
        $this->assertSame('Dissertação (mestrado)', $r[11]->documentType, 'barra invertida não engole as colunas seguintes');

        // Nenhum campo com "não informado"
        foreach ($r as $d) {
            $this->assertStringNotContainsStringIgnoringCase('não informado pela instituição', json_encode(get_object_vars($d), JSON_UNESCAPED_UNICODE));
        }
    }

    public function testRisGeraAsMesmasChavesDoCsv(): void
    {
        $ris = new BdtdRisImporter();
        $f = self::DIR . 'busca.ris';
        $this->assertSame(1.0, $ris->detect($f));
        $this->assertLessThan(1.0, (new GenericRisImporter())->detect($f));
        $this->assertSame(0.0, $ris->detect(__DIR__ . '/../../../fixtures/import/ris/lf.ris'));
        $r = $this->read($ris, $f);
        $c = $this->read(new BdtdCsvImporter(), self::DIR . 'busca.csv');
        $this->assertSame(array_map(fn ($d) => $d->externalId, $c), array_map(fn ($d) => $d->externalId, $r));
        $this->assertSame(['Barros, Eduardo Teste'], $r[2]->authorNames);
        $this->assertSame([], $r[0]->institutions);
        $this->assertSame('O RIS da BDTD não traz instituição nem assuntos. Prefira o CSV.', $ris->previewNotice($f));
    }

    public function testArquivosReais(): void
    {
        $root = dirname(__DIR__, 4) . '/var/exemplos/bdtb/';
        if (!is_file($root . 'search_result--1705555757-csv.zip')) {
            $this->markTestSkipped('Sem os arquivos reais em var/exemplos.');
        }
        $dir = sys_get_temp_dir() . '/bm_bdtd_' . uniqid();
        $csv = (new ZipUploadExtractor())->extract($root . 'search_result--1705555757-csv.zip', $dir)['path'];
        $ris = (new ZipUploadExtractor())->extract($root . 'search_result--1705555757-ris.zip', $dir)['path'];
        try {
            $c = $this->read(new BdtdCsvImporter(), $csv);
            $r = $this->read(new BdtdRisImporter(), $ris);
            $this->assertCount(7193, $c);
            $ids = array_map(fn ($d) => $d->externalId, $c);
            $this->assertCount(7193, array_unique($ids));
            $this->assertSame($ids, array_map(fn ($d) => $d->externalId, $r));
            $types = array_count_values(array_map(fn ($d) => (string) $d->documentType, $c));
            $this->assertSame(4986, $types['Dissertação (mestrado)']);
            $this->assertSame(2207, $types['Tese (doutorado)']);
            $this->assertCount(243, array_filter($c, fn ($d) => $d->doi !== null));
            foreach ($c as $d) {
                foreach ($d->authorNames as $a) {
                    $this->assertDoesNotMatchRegularExpression('#^\d{4}-|^https?://|@#', $a);
                }
            }
        } finally {
            @unlink($csv);
            @unlink($ris);
            @rmdir($dir);
        }
    }
}
