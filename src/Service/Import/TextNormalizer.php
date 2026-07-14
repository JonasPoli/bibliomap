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

    public function formatKeywordCasing(string $term): string
    {
        $term = trim($term);
        if ($term === '') {
            return '';
        }

        $acronyms = [
            'dna', 'rna', 'onu', 'unesco', 'usp', 'unicamp', 'unesp', 'ufrj', 'ufmg', 'ufrgs', 'ufsc', 'ufpr',
            'unb', 'ufpe', 'ufba', 'capes', 'cnpq', 'fapesp', 'sus', 'ibge', 'wos', 'scopus', 'ieee', 'acm',
            'nasa', 'esa', 'who', 'fao', 'gdp', 'ict', 'iot', 'rfid', 'gis', 'gps', 'nlp', 'ann', 'svm',
            'cnn', 'rnn', 'lstm', 'gan', 'bert', 'gpt', 'api', 'sql', 'dbms', 'nosql', 'xml', 'html', 'css',
            'pdf', 'csv', 'rdf', 'owl', 'skos', 'foaf'
        ];

        $properNouns = [
            // Countries & Continents & Regions
            'brazil', 'brasil', 'portugal', 'angola', 'mozambique', 'spain', 'espanha', 'italy', 'italia',
            'france', 'frança', 'germany', 'alemanha', 'uk', 'usa', 'china', 'japan', 'japão', 'india',
            'canada', 'canadá', 'australia', 'austrália', 'belgium', 'bélgica', 'netherlands', 'holanda',
            'switzerland', 'suíça', 'sweden', 'suécia', 'norway', 'noruega', 'denmark', 'dinamarca',
            'finland', 'finlândia', 'austria', 'áustria', 'russia', 'rússia', 'mexico', 'méxico',
            'argentina', 'chile', 'colombia', 'colômbia', 'peru', 'ecuador', 'equador', 'venezuela',
            'uruguay', 'uruguai', 'paraguay', 'paraguai', 'bolivia', 'bolívia',
            'europe', 'europa', 'asia', 'ásia', 'america', 'américa', 'africa', 'áfrica', 'oceania',
            'england', 'inglaterra', 'scotland', 'escócia', 'wales', 'ireland', 'irlanda',

            // Multi-word component words to capitalize
            'sao', 'são', 'paulo', 'rio', 'de', 'janeiro', 'new', 'york', 'united', 'states', 'kingdom',
            'great', 'britain', 'latin', 'north', 'south', 'european', 'union', 'uniao', 'união', 'world', 'bank',
            'lisbon', 'lisboa', 'porto', 'coimbra', 'madrid', 'barcelona', 'paris',
            'london', 'londres', 'washington', 'california', 'califórnia', 'texas', 'florida', 'flórida',
            'tokyo', 'toquio', 'tóquio', 'beijing', 'pequim', 'shanghai', 'xangai',

            // Institutional terms
            'university', 'universidade', 'institute', 'instituto', 'association', 'associação', 'associacao',
            'organization', 'organização', 'organizacao', 'department', 'departamento', 'journal', 'review',
            'academy', 'academia', 'society', 'sociedade', 'center', 'centre', 'centro', 'school', 'escola',
            'college', 'colégio', 'colegio', 'hospital', 'foundation', 'fundação', 'fundacao', 'ministry',
            'ministério', 'ministerio', 'council', 'conselho'
        ];

        $words = preg_split('/\s+/u', $term);
        $formattedWords = [];

        foreach ($words as $word) {
            $cleanWord = preg_replace('/[^\p{L}\p{N}]+/u', '', $word);
            $cleanWordLower = mb_strtolower($cleanWord, 'UTF-8');
            $len = mb_strlen($cleanWord);

            if ($len === 0) {
                $formattedWords[] = $word;
                continue;
            }

            // 1. Check acronym list
            if (in_array($cleanWordLower, $acronyms)) {
                $formattedWords[] = mb_strtoupper($word, 'UTF-8');
                continue;
            }

            // 2. Check proper noun list
            if (in_array($cleanWordLower, $properNouns)) {
                // If it is 'de' (particle), keep it lowercase
                if ($cleanWordLower === 'de') {
                    $formattedWords[] = mb_strtolower($word, 'UTF-8');
                } else {
                    $firstChar = mb_substr($word, 0, 1, 'UTF-8');
                    $rest = mb_substr($word, 1, null, 'UTF-8');
                    $formattedWords[] = mb_strtoupper($firstChar, 'UTF-8') . mb_strtolower($rest, 'UTF-8');
                }
                continue;
            }

            // 3. Fallback for acronyms (all upper, len 2-5)
            $isAcronymFallback = (mb_strtoupper($cleanWord, 'UTF-8') === $cleanWord && $len >= 2 && $len <= 5);
            if ($isAcronymFallback) {
                $formattedWords[] = mb_strtoupper($word, 'UTF-8');
                continue;
            }

            // 4. Default: all general terms in lowercase
            $formattedWords[] = mb_strtolower($word, 'UTF-8');
        }

        return implode(' ', $formattedWords);
    }

    public function normalizeKeyword(string $original): array
    {
        $display = $this->cleanDisplayValue($original);
        $display = $this->formatKeywordCasing($display);

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

        // ── Hard-invalid rules: terms that are definitively garbage ──────────

        // 1. Purely numeric after cleanup (e.g. "3.04", "4 54", "32")
        $lettersOnly = preg_replace('/[^\p{L}]+/u', '', $normalized);
        if (mb_strlen($lettersOnly) === 0) {
            return [
                'valid' => false,
                'reason' => 'purely_numeric',
                'original' => $original,
                'display' => $display,
                'normalized' => $normalized,
            ];
        }

        // 2. Ratio of letters to total chars is too low (gibberish/formulas)
        //    e.g. "(sic)(sic)(sic)" normalizes to "sic sic sic sic" which is fine,
        //    but we catch repeated single-word patterns
        if (mb_strlen($lettersOnly) < 2) {
            return [
                'valid' => false,
                'reason' => 'insufficient_letters',
                'original' => $original,
                'display' => $display,
                'normalized' => $normalized,
            ];
        }

        // 3. Repetitive nonsense: same word repeated 3+ times (e.g. "sic sic sic sic")
        $words = preg_split('/\s+/u', $normalized);
        if (count($words) >= 3) {
            $uniqueWords = array_unique($words);
            if (count($uniqueWords) === 1) {
                return [
                    'valid' => false,
                    'reason' => 'repetitive_nonsense',
                    'original' => $original,
                    'display' => $display,
                    'normalized' => $normalized,
                ];
            }
        }

        // 4. Excessively long strings (>200 chars) are typically parsing artifacts
        if (mb_strlen($normalized) > 200) {
            return [
                'valid' => false,
                'reason' => 'too_long',
                'original' => $original,
                'display' => $display,
                'normalized' => $normalized,
            ];
        }

        // 5. Starts with a digit and has more digits than letters — likely a table fragment
        if (preg_match('/^\d/u', $normalized) && preg_match_all('/\d/u', $normalized) > preg_match_all('/\p{L}/u', $normalized)) {
            return [
                'valid' => false,
                'reason' => 'numeric_fragment',
                'original' => $original,
                'display' => $display,
                'normalized' => $normalized,
            ];
        }

        // ── Soft review rules (suspicious but not auto-deleted) ─────────────
        $needsReview = false;
        $reasons = [];

        // Contains digits
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
