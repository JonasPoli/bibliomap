<?php

namespace App\Service\KeywordTreatment;

final class KeywordFuzzyMatcherService
{
    /**
     * Calculates similarity score (0 to 100) between two terms.
     *
     * $term1/$term2 são as formas normalizadas (minúsculas); $display1/$display2, quando
     * informados, são as grafias de exibição usadas só para detectar siglas.
     */
    public function getSimilarityScore(string $term1, string $term2, ?string $display1 = null, ?string $display2 = null): float
    {
        $len1 = mb_strlen($term1, 'UTF-8');
        $len2 = mb_strlen($term2, 'UTF-8');

        if ($len1 === 0 || $len2 === 0) {
            return 0.0;
        }

        // Safety Rule: Do not match terms if any of them is shorter than 4 characters
        if ($len1 < 4 || $len2 < 4) {
            return 0.0;
        }

        // Safety Rule: Do not match acronyms (e.g., "AI", "IoT", all uppercase strings or strings with uppercase letters only)
        if ($this->isAcronym($display1 ?? $term1) || $this->isAcronym($display2 ?? $term2)) {
            return 0.0;
        }

        // levenshtein()/similar_text() contam bytes: cada caractere UTF-8 vira um byte antes
        [$bytes1, $bytes2] = $this->toSingleByte($term1, $term2);

        // Levenshtein distance
        $dist = levenshtein($bytes1, $bytes2);
        $maxLen = max($len1, $len2);

        $levScore = (1.0 - ($dist / $maxLen)) * 100.0;

        // Double check using similar_text
        similar_text($bytes1, $bytes2, $simPercent);

        // Return the average or conservative lower score of the two algorithms to be safe
        return min($levScore, $simPercent);
    }

    private function isAcronym(string $term): bool
    {
        // If string contains only uppercase letters (ignoring spaces/digits) or is short
        $clean = preg_replace('/[^\p{L}]/u', '', $term);
        if ($clean === '') {
            return false;
        }
        return $clean === mb_strtoupper($clean, 'UTF-8') && mb_strlen($clean, 'UTF-8') <= 4;
    }

    /**
     * Mapeia cada caractere distinto dos dois termos para um único byte.
     *
     * @return array{string, string}
     */
    private function toSingleByte(string $a, string $b): array
    {
        if (!preg_match('/[^\x00-\x7F]/', $a . $b)) {
            return [$a, $b];
        }

        $map = [];
        $out = [];
        foreach ([$a, $b] as $i => $s) {
            $out[$i] = '';
            foreach (mb_str_split($s, 1, 'UTF-8') as $ch) {
                if (!isset($map[$ch])) {
                    if (count($map) >= 256) {
                        // Mais de 256 caracteres distintos: mantém a comparação por bytes
                        return [$a, $b];
                    }
                    $map[$ch] = chr(count($map));
                }
                $out[$i] .= $map[$ch];
            }
        }

        return [$out[0], $out[1]];
    }
}
