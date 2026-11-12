<?php

namespace App\Tests\Service\Import;

use App\DTO\BibliographicRecordDTO;
use App\Service\Import\DocumentEnrichmentService;
use App\Service\Import\DocumentImportService;
use App\Service\Import\LensCsvImporter;
use App\Service\Import\PubmedNbibImporter;
use App\Service\Import\ScopusCsvImporter;
use App\Service\Import\TextNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Tarefas 5.1, 5.4, 5.7, 5.21 e 1.14 da revisão de 11/2026. */
class ImportFixesTest extends TestCase
{
    private function dto(array $f): BibliographicRecordDTO
    {
        $d = new BibliographicRecordDTO();
        foreach ($f as $k => $v) {
            $d->$k = $v;
        }
        return $d;
    }

    // ── 5.4 hash de reserva ───────────────────────────────────────────────────

    public function testHashComDoiOuTituloEAnoNaoMuda(): void
    {
        $this->assertSame(md5('doi:10.1/abc'), DocumentImportService::computeHash($this->dto(['doi' => ' 10.1/ABC ', 'title' => 'x', 'year' => 2020])));
        $this->assertSame(md5('my title here:2020'), DocumentImportService::computeHash($this->dto(['title' => ' My  Title Here ', 'year' => 2020, 'externalId' => 'E1', 'source' => 'scopus'])));
    }

    public function testHashDeReservaUsaBaseEIdentificador(): void
    {
        $a = DocumentImportService::computeHash($this->dto(['title' => 'Sem ano', 'externalId' => 'E1', 'source' => 'scopus']));
        $b = DocumentImportService::computeHash($this->dto(['title' => 'Outro', 'externalId' => 'E1', 'source' => 'scopus']));
        $c = DocumentImportService::computeHash($this->dto(['title' => 'Sem ano', 'externalId' => 'E1', 'source' => 'lens']));
        $this->assertNotNull($a);
        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);
    }

    public function testHashDeReservaPeloTituloNormalizado(): void
    {
        $a = DocumentImportService::computeHash($this->dto(['title' => 'Um  Titulo']));
        $b = DocumentImportService::computeHash($this->dto(['title' => ' um titulo ']));
        $this->assertNotNull($a);
        $this->assertSame($a, $b);
        $this->assertNull(DocumentImportService::computeHash($this->dto([])));
    }

    // ── 5.21 encoding ─────────────────────────────────────────────────────────

    public function testCutNaoPartePersonagemUtf8(): void
    {
        $r = DocumentImportService::cut('ééé', 5); // cada é tem 2 bytes
        $this->assertSame('éé', $r);
        $this->assertTrue(mb_check_encoding($r, 'UTF-8'));
    }

    public function testAmostraDeEncoding(): void
    {
        $this->assertTrue(TextNormalizer::isUtf8Sample('Título ção'));
        $this->assertFalse(TextNormalizer::isUtf8Sample(mb_convert_encoding('Título ção', 'ISO-8859-1', 'UTF-8')));
        $this->assertFalse(TextNormalizer::isUtf8Sample(mb_convert_encoding('abc', 'UTF-16LE', 'UTF-8')));
        // caractere cortado no fim da amostra só é aceito quando a amostra é truncada
        $cut = substr('abcé', 0, -1);
        $this->assertFalse(TextNormalizer::isUtf8Sample($cut));
        $this->assertTrue(TextNormalizer::isUtf8Sample($cut, true));
    }

    // ── 1.14 URL ──────────────────────────────────────────────────────────────

    #[DataProvider('urls')]
    public function testSafeUrl(?string $in, ?string $out): void
    {
        $this->assertSame($out, TextNormalizer::safeUrl($in));
    }

    public static function urls(): array
    {
        return [
            'https'      => ['https://a.org/x?y=1', 'https://a.org/x?y=1'],
            'http'       => ['HTTP://a.org', 'HTTP://a.org'],
            'javascript' => ['javascript:alert(1)', null],
            'data'       => ['data:text/html;base64,AAA', null],
            'ftp'        => ['ftp://a.org/f', null],
            'vazio'      => ['', null],
            'nulo'       => [null, null],
            'lista'      => ['javascript:x; https://b.org/p', 'https://b.org/p'],
            'aspas'      => ['https://a.org/"onmouseover=1', null],
        ];
    }

    public function testImportadoresDescartamUrlPerigosa(): void
    {
        $scopus = (new ScopusCsvImporter())->mapToInternalRecord(['Title' => 'T', 'Link' => 'javascript:alert(1)']);
        $this->assertNull($scopus->url);
        $scopus = (new ScopusCsvImporter())->mapToInternalRecord(['Title' => 'T', 'Link' => 'https://www.scopus.com/x']);
        $this->assertSame('https://www.scopus.com/x', $scopus->url);

        $lens = (new LensCsvImporter())->mapToInternalRecord(['Title' => 'T', 'External URL' => 'javascript:1', 'Source URLs' => 'https://l.org/a']);
        $this->assertSame('https://l.org/a', $lens->url);
        $lens = (new LensCsvImporter())->mapToInternalRecord(['Title' => 'T', 'External URL' => 'data:x']);
        $this->assertNull($lens->url);
    }

    // ── 5.7 ISSN ──────────────────────────────────────────────────────────────

    #[DataProvider('issns')]
    public function testFirstIssn(?string $in, ?string $out): void
    {
        $this->assertSame($out, TextNormalizer::firstIssn($in));
    }

    public static function issns(): array
    {
        return [
            'simples'         => ['1234-5678', '1234-5678'],
            'sem hífen'       => ['12345678', '1234-5678'],
            'X minúsculo'     => ['1234-567x', '1234-567X'],
            'lista'           => ['1234-5678; 8765-4321', '1234-5678'],
            'com sufixo'      => ['0028-0836 (Print)', '0028-0836'],
            'lixo antes'      => ['n/a; 1476-4687', '1476-4687'],
            'curto demais'    => ['123-456', null],
            'longo demais'    => ['123456789', null],
            'vazio'           => ['', null],
            'nulo'            => [null, null],
        ];
    }

    public function testLensUsaPrimeiroIssnValido(): void
    {
        $dto = (new LensCsvImporter())->mapToInternalRecord(['Title' => 'T', 'ISSNs' => 'xx; 1476-4687; 0028-0836']);
        $this->assertSame('1476-4687', $dto->issn);
    }

    public function testNbibLeIssnDaTagIS(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bm_nbib_') . '.nbib';
        file_put_contents($path, "PMID- 111\nTI  - Um titulo\nDP  - 2020 Jan\nIS  - 0028-0836 (Print)\nIS  - 1476-4687 (Electronic)\nJT  - Nature\n\nPMID- 222\nTI  - Sem issn\nDP  - 2021\n");
        try {
            $recs = iterator_to_array((new PubmedNbibImporter())->parseStream($path), false);
        } finally {
            @unlink($path);
        }
        $this->assertSame('0028-0836', $recs[0]->issn);
        $this->assertNull($recs[1]->issn);
    }

    // ── 5.1 sigla de duas letras ──────────────────────────────────────────────

    public function testParseUsLocationToken(): void
    {
        $uk = DocumentEnrichmentService::parseUsLocationToken('UK');
        $this->assertFalse($uk['known_state']);
        $this->assertFalse($uk['explicit_usa']);
        $br = DocumentEnrichmentService::parseUsLocationToken('BR');
        $this->assertFalse($br['known_state'] || $br['explicit_usa']);

        $ca = DocumentEnrichmentService::parseUsLocationToken('CA 94305 USA');
        $this->assertTrue($ca['known_state']);
        $this->assertTrue($ca['explicit_usa']);
        $this->assertSame('94305', $ca['postal_code']);
        $this->assertSame('CA', $ca['state']);

        $tx = DocumentEnrichmentService::parseUsLocationToken('TX');
        $this->assertTrue($tx['known_state']);
        $this->assertFalse($tx['explicit_usa']);

        $xx = DocumentEnrichmentService::parseUsLocationToken('XX 12345 USA');
        $this->assertFalse($xx['known_state']);
        $this->assertTrue($xx['explicit_usa']);

        $this->assertNull(DocumentEnrichmentService::parseUsLocationToken('Brazil'));
    }

    // ── 5.22 desempenho da normalização de texto e dos leitores ───────────────

    public function testColunasComEspacoContinuamSendoLidas(): void
    {
        $r = ['  Title ' => ' Um título ', 'Year' => '2020', ' EID' => '2-s2.0-1', 'DOI' => ''];
        $dto = (new ScopusCsvImporter())->mapToInternalRecord($r);
        $this->assertSame('Um título', $dto->title);
        $this->assertSame(2020, $dto->year);
        $this->assertSame('2-s2.0-1', $dto->externalId);
        $this->assertNull($dto->doi);
        $this->assertNull($dto->rawData); // o campo não é mais preenchido: ninguém o lia e dobrava a memória do registro
    }

    public function testTrimKeysPrimeiraOcorrenciaVence(): void
    {
        $this->assertSame(['a' => 1, 'b' => 3], TextNormalizer::trimKeys([' a' => 1, 'a ' => 2, 'b' => 3]));
    }

    public function testNormalizacaoComCacheDevolveOMesmoResultado(): void
    {
        $n = new TextNormalizer();
        $first = $n->normalizeKeyword('  "Machine Learning". ');
        $second = $n->normalizeKeyword('  "Machine Learning". ');
        $this->assertSame($first, $second);
        $this->assertSame('machine learning', $first['normalized']);
        $a = $n->normalizeAuthor('Silva, José A.');
        $this->assertSame($a, $n->normalizeAuthor('Silva, José A.'));
        $this->assertSame('jose', explode(' ', (new TextNormalizer())->normalizeForComparison('José'))[0]);
    }
}
