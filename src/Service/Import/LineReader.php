<?php

namespace App\Service\Import;

/**
 * Lê um arquivo de texto linha a linha aceitando qualquer quebra: "\n", "\r\n" ou só "\r" (a BRAPCI exporta
 * assim, e fgets/fgetcsv leriam o arquivo inteiro como uma linha só). Tira o BOM do início.
 */
final class LineReader
{
    /** @return \Generator<int, string> */
    public static function lines(string $path): \Generator
    {
        $h = @fopen($path, 'rb');
        if (!$h) {
            return;
        }
        try {
            $buffer = '';
            $first = true;
            while (!feof($h)) {
                $chunk = fread($h, 1 << 16);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                if ($first) {
                    $chunk = preg_replace('/^\xEF\xBB\xBF/', '', $chunk);
                    $first = false;
                }
                $buffer .= $chunk;
                // "\r" no fim do bloco pode ser a primeira metade de "\r\n": espera o próximo bloco
                $hold = '';
                if (str_ends_with($buffer, "\r") && !feof($h)) {
                    $hold = "\r";
                    $buffer = substr($buffer, 0, -1);
                }
                $parts = preg_split('/\r\n|\r|\n/', $buffer);
                $buffer = array_pop($parts) . $hold;
                foreach ($parts as $p) {
                    yield $p;
                }
            }
            if ($buffer !== '') {
                foreach (preg_split('/\r\n|\r|\n/', $buffer) as $p) {
                    yield $p;
                }
            }
        } finally {
            fclose($h);
        }
    }

}
