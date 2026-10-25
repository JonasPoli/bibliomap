<?php

namespace App\Tests\Service\Import;

use App\DTO\BibliographicRecordDTO;
use App\Service\Import\LensCsvImporter;
use App\Service\Import\PubmedCsvImporter;
use App\Service\Import\ScopusCsvImporter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CsvImporterCountTest extends TestCase
{
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
    }

    private function csv(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bm_csv_') . '.csv';
        file_put_contents($path, $content);
        $this->files[] = $path;
        return $path;
    }

    /** 3 registros; o resumo do 1º tem 3 quebras de linha (6 linhas físicas + cabeçalho). */
    public static function multilineFiles(): array
    {
        return [
            'scopus' => [ScopusCsvImporter::class,
                "Authors,Title,Year,Abstract,EID,Cited by,Author Keywords\n"
                . "\"Silva J.\",\"T1\",2020,\"linha 1\nlinha 2\n\nlinha 4\",2-s2.0-1,3,a;b\n"
                . "\"Souza M.\",\"T2\",2021,\"curto\",2-s2.0-2,0,c\n"
                . "\"Lima A.\",\"T3\",2022,\"\",2-s2.0-3,,\n"],
            'lens' => [LensCsvImporter::class,
                "Lens ID,Title,Publication Year,Author/s,Abstract,Citing Works Count,Source Country\n"
                . "001-1,\"T1\",2020,\"Ana Silva\",\"a\nb\nc\nd\",1,Netherlands\n"
                . "001-2,\"T2\",2021,\"Rui Lima\",\"x\",2,United States\n"
                . "001-3,\"T3\",2022,\"Eva Melo\",,0,Brazil\n"],
            'pubmed' => [PubmedCsvImporter::class,
                "PMID,Title,Authors,Citation,Journal/Book,Publication Year\n"
                . "1,\"T1\nparte 2\nparte 3\nparte 4\",\"Silva J\",\"c\",J,2020\n"
                . "2,\"T2\",\"Lima A\",\"c\",J,2021\n"
                . "3,\"T3\",\"Melo E\",\"c\",J,2022\n"],
        ];
    }

    #[DataProvider('multilineFiles')]
    public function testCountRowsCountsRecordsNotLines(string $class, string $content): void
    {
        $path = $this->csv($content);
        $importer = new $class();

        // Antes: contava linhas físicas (6) e o "aproveitamento" caía para 50%
        $this->assertSame(3, $importer->countRows($path));
        $this->assertCount(3, iterator_to_array($importer->parseStream($path), false));
    }

    public function testLensDoesNotUseJournalCountryAsDocumentCountry(): void
    {
        $dto = (new LensCsvImporter())->mapToInternalRecord([
            'Lens ID' => '001-1', 'Title' => 'T', 'Publication Year' => '2020',
            'Author/s' => 'Ana Silva', 'Source Country' => 'Netherlands',
        ]);
        $this->assertInstanceOf(BibliographicRecordDTO::class, $dto);
        $this->assertSame([], $dto->countries);
    }
}
