<?php

namespace App\Tests\Service\Thesaurus;

use App\Service\Thesaurus\EntityMergeService;
use App\Service\Thesaurus\ThesaurusFileService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ThesaurusFileServiceTest extends TestCase
{
    #[DataProvider('headerLines')]
    public function testDetectCsvDelimiter(string $line, string $expected): void
    {
        $this->assertSame($expected, ThesaurusFileService::detectCsvDelimiter($line));
    }

    public static function headerLines(): array
    {
        return [
            'exportação autores (;) com BOM' => ["\xEF\xBB\xBFname;orcid;status;variations\n", ';'],
            'exportação revistas (;)'        => ["issn;title;qualis\r\n", ';'],
            'vírgula'                        => ["term,type,status,variations\n", ','],
            'tabulação'                      => ["preferred_name\tvariant_name\n", "\t"],
            'vírgula dentro de aspas'        => ["\"a,b,c\";\"d\";e\n", ';'],
            'coluna única'                   => ["name\n", ','],
            'vazio'                          => ['', ','],
        ];
    }

    public function testCreateCsvReaderReadsSemicolonExport(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($file, "\xEF\xBB\xBFname;orcid;status;variations\n\"Poli, Jonas\";;1;\"Poli J;Poli, J.\"\n");
        try {
            $records = iterator_to_array(ThesaurusFileService::createCsvReader($file)->getRecords(), false);
        } finally {
            unlink($file);
        }

        $this->assertCount(1, $records);
        $this->assertSame('Poli, Jonas', $records[0]['name']);
        $this->assertSame('Poli J;Poli, J.', $records[0]['variations']);
    }

    public function testParseCsvThesaurusExportedWithSemicolon(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'the') . '.csv';
        file_put_contents($file, "\xEF\xBB\xBFpreferred_name;variant_name\nE-learning;elearning\nE-learning;electronic learning\n");
        try {
            $entries = (new ThesaurusFileService())->parseFile($file);
        } finally {
            unlink($file);
        }

        $this->assertSame([['header' => 'E-learning', 'variations' => ['elearning', 'electronic learning']]], $entries);
    }

    #[DataProvider('issnTexts')]
    public function testExtractIssn(string $text, ?string $expected): void
    {
        $this->assertSame($expected, ThesaurusFileService::extractIssn($text));
    }

    public static function issnTexts(): array
    {
        return [
            'com hífen'          => ['Revista X 0101-7330', '0101-7330'],
            'sem hífen, X final' => ['1024123x', '1024-123X'],
            'sem ISSN'           => ['Sensors', null],
            'ano não é ISSN'     => ['Journal 2020', null],
            'número longo'       => ['123456789', null],
        ];
    }

    #[DataProvider('issnNormalization')]
    public function testNormalizeIssnMatchesDocumentEnrichment(string $issn, string $expected): void
    {
        // Mesma regra do enriquecimento: LOWER(REPLACE(REPLACE(issn, '-', ''), ' ', ''))
        $this->assertSame($expected, EntityMergeService::normalizeIssn($issn));
    }

    public static function issnNormalization(): array
    {
        return [
            ['2169-3536', '21693536'],
            ['1024-123X', '1024123x'],
            [' 0101 7330 ', '01017330'],
        ];
    }
}
