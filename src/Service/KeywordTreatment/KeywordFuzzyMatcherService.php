<?php

namespace App\Service\KeywordTreatment;

final class KeywordFuzzyMatcherService
{
    /**
     * Calculates similarity score (0 to 100) between two terms.
     */
    public function getSimilarityScore(string $term1, string $term2): float
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
        if ($this->isAcronym($term1) || $this->isAcronym($term2)) {
            return 0.0;
        }

        // Levenshtein distance
        $dist = levenshtein($term1, $term2);
        $maxLen = max($len1, $len2);

        $levScore = (1.0 - ($dist / $maxLen)) * 100.0;

        // Double check using similar_text
        similar_text($term1, $term2, $simPercent);

        // Return the average or conservative lower score of the two algorithms to be safe
        return min($levScore, $simPercent);
    }

    private function isAcronym(string $term): bool
    {
        // If string contains only uppercase letters (ignoring spaces/digits) or is short
        $clean = preg_replace('/[^a-zA-Z]/', '', $term);
        if ($clean === '') {
            return false;
        }
        return $clean === strtoupper($clean) && strlen($clean) <= 4;
    }
}
