<?php

namespace App\Service\Import;

final class TextNormalizer
{
    public function cleanDisplayValue(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        // 1. Decodifica entidades HTML
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 2. Remove tags HTML
        $value = strip_tags($value);

        // 3. Converte espaços Unicode para espaço normal
        $value = preg_replace('/[\p{Z}\s]+/u', ' ', $value);

        // 4. Trim normal
        $value = trim($value);

        // 5. Remove sujeira repetida nas bordas (sem remover pontuação interna)
        $value = preg_replace('/^[\s\'"“”‘’`´\.,;:_\*\+\-\–\—\(\)\[\]\{\}\/\\\\]+/u', '', $value);
        $value = preg_replace('/[\s\'"“”‘’`´\.,;:_\*\+\-\–\—\(\)\[\]\{\}\/\\\\]+$/u', '', $value);

        // 6. Remove espaços duplicados novamente
        $value = preg_replace('/\s+/u', ' ', $value);

        return trim($value);
    }

    public function normalizeForComparison(?string $value): string
    {
        $value = $this->cleanDisplayValue($value);

        $value = mb_strtolower($value, 'UTF-8');

        // Remove acentos
        $value = transliterator_transliterate('NFD; [:Nonspacing Mark:] Remove; NFC', $value);

        // Remove pontuação para comparação
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);

        $value = preg_replace('/\s+/u', ' ', $value);

        return trim($value);
    }

    public function isOnlyReferenceNumber(string $value): bool
    {
        $clean = trim($value);

        return preg_match('/^[\[\(]?\d+[\]\)]?$/u', $clean) === 1;
    }

    public function normalizeKeyword(string $original): array
    {
        $display = $this->cleanDisplayValue($original);

        if ($this->isOnlyReferenceNumber($display)) {
            return [
                'valid' => false,
                'reason' => 'numeric_reference',
                'original' => $original,
                'display' => '',
                'normalized' => '',
            ];
        }

        $normalized = $this->normalizeForComparison($display);

        if ($normalized === '' || mb_strlen($normalized) < 2) {
            return [
                'valid' => false,
                'reason' => 'empty_or_too_short',
                'original' => $original,
                'display' => $display,
                'normalized' => $normalized,
            ];
        }

        // Rules to check if a keyword requires review (e.g. numerical terms, terms that look like authors, university, etc.)
        $needsReview = false;
        $reasons = [];

        // Too short/suspicious: contains digits
        if (preg_match('/\d/u', $display)) {
            $needsReview = true;
            $reasons[] = 'contains_number';
        }

        // Looks like an author (e.g. "Surname, Name" or "Silva, J.")
        if (preg_match('/^[A-Z][a-z]+,\s+[A-Z]\.?$/u', $display) || preg_match('/^[A-Z][a-z]+,\s+[A-Z][a-z]+$/u', $display)) {
            $needsReview = true;
            $reasons[] = 'looks_like_author';
        }

        return [
            'valid' => true,
            'needs_review' => $needsReview,
            'review_reasons' => $reasons,
            'original' => $original,
            'display' => $display,
            'normalized' => $normalized,
        ];
    }

    public function normalizeAuthor(string $original): array
    {
        $display = $this->cleanDisplayValue($original);
        $normalized = $this->normalizeForComparison($display);

        $needsReview = false;
        $reasons = [];

        // Autor com uma palavra só é suspeito.
        if (count(explode(' ', $display)) < 2) {
            $needsReview = true;
            $reasons[] = 'single_word_author';
        }

        // Números em nomes são suspeitos.
        if (preg_match('/\d/u', $display)) {
            $needsReview = true;
            $reasons[] = 'contains_number';
        }

        // Termos com cara de instituição.
        if (preg_match('/\b(univ|university|institut|institute|dept|department|laboratory|lab|center|centre|certh|iti)\b/i', $display)) {
            $needsReview = true;
            $reasons[] = 'looks_like_institution';
        }

        if ($normalized === '' || mb_strlen($normalized) < 2) {
            return [
                'valid' => false,
                'reason' => 'empty_or_too_short',
                'original' => $original,
                'display' => $display,
                'normalized' => $normalized,
            ];
        }

        return [
            'valid' => true,
            'needs_review' => $needsReview,
            'review_reasons' => $reasons,
            'original' => $original,
            'display' => $display,
            'normalized' => $normalized,
        ];
    }
}
