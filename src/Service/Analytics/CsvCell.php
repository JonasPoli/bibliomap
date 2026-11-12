<?php

namespace App\Service\Analytics;

/**
 * Proteção contra injeção de fórmula em CSV aberto no Excel/LibreOffice: texto que começa com
 * "=", "+", "-" ou "@" (ou tabulação / retorno de carro) vira fórmula. Recebe um apóstrofo na frente.
 * Números de verdade (ex.: "-5", "+1,5") não são texto de fórmula e ficam como estão.
 */
final class CsvCell
{
    private const TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    public static function escape(mixed $value): string
    {
        $text = (string) ($value ?? '');
        if ($text === '' || !in_array($text[0], self::TRIGGERS, true)) {
            return $text;
        }
        if (is_numeric(str_replace(',', '.', $text))) {
            return $text;
        }

        return "'" . $text;
    }

    /** Desfaz escape() ao reimportar um CSV gerado pelo sistema: "'=SOMA(A1)" volta a "=SOMA(A1)". */
    public static function unescape(string $text): string
    {
        if (strlen($text) >= 2 && $text[0] === "'" && in_array($text[1], self::TRIGGERS, true)
            && !is_numeric(str_replace(',', '.', substr($text, 1)))) {
            return substr($text, 1);
        }

        return $text;
    }
}
