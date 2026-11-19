<?php

namespace App\Service\Import;

/**
 * Países e instituições a partir de afiliações em texto ("Inst, Cidade, País; Inst2, ..."). Movido sem mudança
 * do leitor do Scopus, para ser usado também pelo RIS do OpenAlex.
 */
final class AffiliationParser
{
    public static function countries(string $affiliationText): array
    {
        $countries = [];
        $affiliations = explode(';', $affiliationText);

        foreach ($affiliations as $aff) {
            $parts = array_map('trim', explode(',', $aff));

            if (count($parts) === 0) {
                continue;
            }

            $last = end($parts);

            if (!$last || strlen($last) <= 2) {
                continue;
            }

            $country = self::normalizeCountryFromAffiliationLastSegment($last);

            if ($country !== null) {
                $countries[] = $country;
            }
        }

        return array_values(array_unique(array_filter($countries)));
    }

    private static function normalizeCountryFromAffiliationLastSegment(string $value): ?string
    {
        $value = trim($value);

        // Ex.: FL 32611 USA, CA 95616 USA, TX 77843 USA
        if (preg_match('/^[A-Z]{2}\s+\d{5}(?:-\d{4})?\s+(USA|US|U\.S\.A\.)$/i', $value)) {
            return 'USA';
        }

        // Variação comum do Scopus para China
        if (preg_match('/^Peoples R China$/i', $value)) {
            return 'China';
        }

        return $value;
    }

    public static function institutions(string $affiliationText): array
    {
        $institutions = [];
        $affiliations = explode(';', $affiliationText);
        foreach ($affiliations as $aff) {
            $parts = array_map('trim', explode(',', $aff));
            foreach ($parts as $part) {
                $lower = strtolower($part);
                if (str_contains($lower, 'universi') || 
                    str_contains($lower, 'colleg') || 
                    str_contains($lower, 'institut') || 
                    str_contains($lower, 'school') || 
                    str_contains($lower, 'hospital') || 
                    str_contains($lower, 'center') || 
                    str_contains($lower, 'centre') || 
                    str_contains($lower, 'clinic') || 
                    str_contains($lower, 'laboratory') || 
                    str_contains($lower, 'lab') ||
                    str_contains($lower, 'foundation') || 
                    str_contains($lower, 'fundacao') ||
                    str_contains($lower, 'embrapa') ||
                    str_contains($lower, 'cnpq') ||
                    str_contains($lower, 'usp') ||
                    str_contains($lower, 'unicamp') ||
                    str_contains($lower, 'unesp') ||
                    str_contains($lower, 'ufscar')
                ) {
                    $part = preg_replace('/\s+/', ' ', $part);
                    if (strlen($part) > 3 && strlen($part) < 100) {
                        $institutions[] = $part;
                        break;
                    }
                }
            }
        }
        return array_unique(array_filter($institutions));
    }
}
