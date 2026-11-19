<?php

namespace App\Service\Import\Brapci;

use App\Service\Import\DeclaresEncodingInterface;

/**
 * "XLS" da cesta da BRAPCI: na verdade um fragmento HTML <table> em Windows-1252, uma <tr> por linha, sem
 * escape de HTML nas células (o texto pode ter "<"). Por isso não passa por analisador de HTML: cada linha é
 * dividida exatamente nos separadores que o exportador escreve ("</td><td>").
 */
class BrapciXlsImporter extends AbstractBrapciTableImporter implements DeclaresEncodingInterface
{
    public function supports(string $format, ?string $source = null): bool
    {
        return strtolower($format) === 'xls' && strtolower($source ?? '') === 'brapci';
    }

    public function getFormatName(): string { return 'XLS'; }

    public function sourceEncoding(string $filePath): ?string
    {
        return 'Windows-1252';
    }

    public function detect(string $filePath): float
    {
        $h = @fopen($filePath, 'rb');
        if (!$h) {
            return 0.0;
        }
        $head = ltrim((string) fread($h, 512));
        fclose($h);

        return str_starts_with($head, '<table>') && str_contains($head, '<td>ID</td><td>CLASS</td>') ? 1.0 : 0.0;
    }

    protected function rows(string $filePath): \Generator
    {
        $h = @fopen($filePath, 'rb');
        if (!$h) {
            return;
        }
        try {
            $names = null;
            $position = 0;
            $buffer = '';
            while (true) {
                $chunk = feof($h) ? '' : (string) fread($h, 1 << 16);
                $buffer .= $chunk;
                $parts = explode('</tr>', $buffer);
                $buffer = $chunk === '' ? '' : array_pop($parts);
                foreach ($parts as $part) {
                    $start = strpos($part, '<tr><td>');
                    if ($start === false) {
                        continue;
                    }
                    $inner = substr($part, $start + strlen('<tr><td>'));
                    $inner = preg_replace('#</td>\s*$#', '', $inner);
                    $inner = mb_convert_encoding($inner, 'UTF-8', 'Windows-1252');
                    $cells = explode('</td><td>', $inner);
                    if ($names === null) {
                        $names = self::headerNames($cells);
                        continue;
                    }
                    $position++;
                    $row = self::combine($names, $cells);
                    yield $row === null
                        ? [$position, null, sprintf('Linha com %d células; o cabeçalho tem %d.', count($cells), count($names))]
                        : [$position, $row];
                }
                if ($chunk === '') {
                    break;
                }
            }
        } finally {
            fclose($h);
        }
    }
}
