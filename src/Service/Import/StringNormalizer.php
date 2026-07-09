<?php

namespace App\Service\Import;

class StringNormalizer
{
    public static function normalizeString(string $val, bool $forComparison = false): string
    {
        $normalizer = new TextNormalizer();
        if ($forComparison) {
            return $normalizer->normalizeForComparison($val);
        }
        return $normalizer->cleanDisplayValue($val);
    }
}
