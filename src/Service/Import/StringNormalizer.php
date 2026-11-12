<?php

namespace App\Service\Import;

class StringNormalizer
{
    public static function normalizeString(string $val, bool $forComparison = false): string
    {
        // Uma instância só: guarda o transliterador e os termos já normalizados
        static $normalizer = null;
        $normalizer ??= new TextNormalizer();
        if ($forComparison) {
            return $normalizer->normalizeForComparison($val);
        }
        return $normalizer->cleanDisplayValue($val);
    }
}
