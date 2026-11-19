<?php

namespace App\Service\Import\Brapci;

use App\Service\Import\LineReader;

/**
 * CSV da cesta da BRAPCI: UTF-8 sem BOM, quebra de linha só "\r", vírgula sobrando no fim de cada linha.
 * As linhas são lidas com LineReader (aceita "\r") e juntadas enquanto houver aspas abertas, para tolerar o
 * mesmo arquivo regravado com quebras dentro de células.
 */
class BrapciCsvImporter extends AbstractBrapciTableImporter
{
    public function supports(string $format, ?string $source = null): bool
    {
        return strtolower($format) === 'csv' && strtolower($source ?? '') === 'brapci';
    }

    public function getFormatName(): string { return 'CSV'; }

    public function detect(string $filePath): float
    {
        foreach (LineReader::lines($filePath) as $line) {
            $names = self::headerNames(str_getcsv($line, ',', '"', ''));
            $found = count(array_intersect(BrapciRecordMapper::COLUMNS, $names));

            return $found / count(BrapciRecordMapper::COLUMNS);
        }

        return 0.0;
    }

    protected function rows(string $filePath): \Generator
    {
        $names = null;
        $pending = null;
        $position = 0;
        foreach (LineReader::lines($filePath) as $line) {
            $pending = $pending === null ? $line : $pending . "\n" . $line;
            if (substr_count($pending, '"') % 2 === 1) {
                continue; // aspas abertas: a célula continua na próxima linha
            }
            $record = $pending;
            $pending = null;
            if (trim($record) === '') {
                continue;
            }
            $cells = str_getcsv($record, ',', '"', '');
            if ($names === null) {
                $names = self::headerNames($cells);
                continue;
            }
            $position++;
            $row = self::combine($names, $cells);
            yield $row === null
                ? [$position, null, sprintf('Linha com %d colunas; o cabeçalho tem %d.', count($cells), count($names))]
                : [$position, $row];
        }
        if ($pending !== null && trim($pending) !== '' && $names !== null) {
            yield [++$position, null, 'Linha com aspas sem fechar no fim do arquivo.'];
        }
    }
}
