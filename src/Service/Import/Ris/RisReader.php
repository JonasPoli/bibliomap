<?php

namespace App\Service\Import\Ris;

use App\Service\Import\LineReader;
use App\Service\Import\TextNormalizer;

/**
 * Lê arquivos RIS registro a registro, sem carregar o arquivo inteiro. Aceita quebras de linha "\n", "\r\n" e
 * só "\r" (a BRAPCI exporta assim), BOM no início e linhas de continuação sem marcação (resumo e afiliações
 * em várias linhas), que são juntadas ao último campo com "\n". Um registro começa em "TY  -" e termina em
 * "ER  -"; registro aberto no fim do arquivo também é entregue.
 */
final class RisReader
{
    private const TAG = '/^([A-Z][A-Z0-9])  -(?: (.*))?$/';

    /**
     * @return \Generator<int, array{fields: array<string, list<string>>, position: int}>
     */
    public function records(string $path): \Generator
    {
        $fields = null;
        $lastTag = null;
        $position = 0;

        foreach (self::lines($path) as $line) {
            $line = rtrim(TextNormalizer::scrub($line), " \t");
            if (preg_match(self::TAG, $line, $m)) {
                $tag = $m[1];
                $value = trim($m[2] ?? '');
                if ($tag === 'TY') {
                    if ($fields !== null) {
                        yield ['fields' => $fields, 'position' => $position];
                    }
                    $position++;
                    $fields = ['TY' => [$value]];
                    $lastTag = 'TY';
                    continue;
                }
                if ($fields === null) {
                    continue; // marcação antes do primeiro TY (cabeçalho de alguns exportadores)
                }
                if ($tag === 'ER') {
                    yield ['fields' => $fields, 'position' => $position];
                    $fields = null;
                    $lastTag = null;
                    continue;
                }
                $fields[$tag][] = $value;
                $lastTag = $tag;
                continue;
            }
            if ($fields !== null && $lastTag !== null && trim($line) !== '') {
                $i = count($fields[$lastTag]) - 1;
                $fields[$lastTag][$i] = $fields[$lastTag][$i] === '' ? trim($line) : $fields[$lastTag][$i] . "\n" . trim($line);
            }
        }

        if ($fields !== null) {
            yield ['fields' => $fields, 'position' => $position];
        }
    }

    /** Número de registros que records() entrega (a mesma leitura). */
    public function count(string $path): int
    {
        $n = 0;
        foreach ($this->records($path) as $_) {
            $n++;
        }

        return $n;
    }

    /**
     * Primeiras linhas não vazias do arquivo (para a detecção), já sem BOM.
     *
     * @return list<string>
     */
    public static function head(string $path, int $max = 200): array
    {
        $out = [];
        foreach (self::lines($path) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $out[] = $line;
            if (count($out) >= $max) {
                break;
            }
        }

        return $out;
    }

    /** Linhas do arquivo com qualquer quebra ("\n", "\r\n" ou "\r"), sem o BOM inicial. */
    public static function lines(string $path): \Generator
    {
        return LineReader::lines($path);
    }

    public static function isTagLine(string $line): bool
    {
        return (bool) preg_match(self::TAG, rtrim($line));
    }
}
