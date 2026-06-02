<?php

namespace App\Service\Import;

use App\DTO\BibliographicRecordDTO;
use App\DTO\ImportResult;

/**
 * Importer for Web of Science "Plain Text File" export format.
 *
 * File signature (first two lines):
 *   FN Clarivate Analytics Web of Science
 *   VR 1.0
 *
 * Field tags (2-char code + space + value).
 * Continuation lines start with 3 spaces.
 * Each record ends with "ER" on its own line.
 *
 * Key tags parsed:
 *   PT  Publication Type  (J=Article, C=Conference, B=Book)
 *   AU  Authors           (abbreviated, one per line)
 *   AF  Authors Full Name (one per line)
 *   TI  Title
 *   SO  Source (journal name)
 *   AB  Abstract
 *   DE  Author Keywords   (semicolon-separated)
 *   ID  Keywords Plus     (semicolon-separated)
 *   PY  Publication Year
 *   DI  DOI
 *   UT  WoS UID           (WOS:000...)
 *   LA  Language
 *   DT  Document Type
 *   VL  Volume
 *   IS  Issue
 *   BP  Begin Page
 *   EP  End Page
 *   TC  Times Cited
 *   PU  Publisher
 *   SN  ISSN
 *   BN  ISBN
 *   C1  Affiliations
 *   OA  Open Access
 *   CR  Cited References  (one per line)
 *   ER  End of Record
 */
class WosImporter implements BibliographicImporterInterface
{
    /** Bytes of the beginning of the file to scan for detection. */
    private const HEADER_SCAN_BYTES = 200;

    // ── Detection ────────────────────────────────────────────────────────────

    public function supports(string $format, ?string $source = null): bool
    {
        return strtolower($format) === 'txt'
            && in_array(strtolower($source ?? ''), ['wos', 'webofscience', ''], true);
    }

    /**
     * Returns confidence score 0–1.
     * Checks the WoS file header and presence of ER (end-of-record) markers.
     */
    public function detect(string $filePath): float
    {
        try {
            $handle = fopen($filePath, 'r');
            if (!$handle) return 0.0;

            $header = fread($handle, self::HEADER_SCAN_BYTES);
            fclose($handle);

            $header = ltrim($header, "\xEF\xBB\xBF"); // strip UTF-8 BOM if present

            // Strongest signal: canonical WoS header
            if (str_contains($header, 'Clarivate Analytics Web of Science')) {
                return 1.0;
            }

            // Slightly weaker: just the version line
            if (str_contains($header, 'FN ') && str_contains($header, 'VR 1.0')) {
                return 0.9;
            }

            // Heuristic: file starts with "PT " (publication type) — common WoS tag
            if (preg_match('/^(FN |PT [A-Z])/m', $header)) {
                return 0.6;
            }

            return 0.0;
        } catch (\Throwable) {
            return 0.0;
        }
    }

    // ── Parsing ──────────────────────────────────────────────────────────────

    /**
     * Parse up to $limit records (0 = all).
     */
    public function parse(string $filePath, int $limit = 0): ImportResult
    {
        $result = new ImportResult();
        $result->detectedSource = 'wos';
        $result->detectedFormat = 'txt';
        $result->headers = ['PT', 'AU', 'TI', 'SO', 'AB', 'DE', 'PY', 'DI', 'UT', 'TC'];

        $count = 0;
        foreach ($this->parseRecordsFromFile($filePath) as $raw) {
            $result->totalRead++;
            try {
                $dto = $this->mapToInternalRecord($raw);
                if (empty($result->sample)) {
                    $result->sample = $raw;
                }
                $result->records[] = $dto;
                $result->totalValid++;
                $count++;
                if ($limit > 0 && $count >= $limit) {
                    break;
                }
            } catch (\Throwable $e) {
                $result->totalErrors++;
                $result->errors[] = 'Registro ' . $result->totalRead . ': ' . $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * Streaming generator — yields ONE DTO at a time.
     * Use for full imports to avoid memory exhaustion.
     *
     * @return \Generator<BibliographicRecordDTO>
     */
    public function parseStream(string $filePath): \Generator
    {
        foreach ($this->parseRecordsFromFile($filePath) as $raw) {
            try {
                yield $this->mapToInternalRecord($raw);
            } catch (\Throwable) {
                // skip malformed records silently
            }
        }
    }

    /**
     * Count approximate number of records (count ER markers).
     */
    public function countRows(string $filePath): int
    {
        $handle = fopen($filePath, 'r');
        if (!$handle) return 0;
        $count = 0;
        while (($line = fgets($handle)) !== false) {
            if (rtrim($line) === 'ER') {
                $count++;
            }
        }
        fclose($handle);
        return $count;
    }

    // ── Field mapping ────────────────────────────────────────────────────────

    public function mapToInternalRecord(array $raw): BibliographicRecordDTO
    {
        $dto = new BibliographicRecordDTO();
        $dto->source = 'wos';
        $dto->rawData = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Title (can span multiple lines in WoS)
        $dto->title = $this->field($raw, 'TI');

        // Abstract
        $dto->abstractText = $this->field($raw, 'AB');

        // Year
        $py = $this->field($raw, 'PY');
        $dto->year = $py && ctype_digit($py) ? (int) $py : null;

        // DOI — WoS uses DI tag
        $dto->doi = $this->normalizeDoi($this->field($raw, 'DI'));

        // External ID (WoS UID: "WOS:000...")
        $dto->externalId = $this->field($raw, 'UT');

        // Language
        $dto->language = $this->field($raw, 'LA');

        // Document type
        $dt = $this->field($raw, 'DT');
        $pt = $this->field($raw, 'PT');
        $dto->documentType = $dt ?? $this->expandPt($pt);

        // Source (journal / proceedings name)
        $dto->sourceTitle = $this->field($raw, 'SO');

        // Volume / Issue / Pages
        $dto->volume    = $this->field($raw, 'VL');
        $dto->issue     = $this->field($raw, 'IS');
        $dto->pageStart = $this->field($raw, 'BP');
        $dto->pageEnd   = $this->field($raw, 'EP');

        // Publisher
        $dto->publisher = $this->field($raw, 'PU');

        // ISSN / ISBN
        $dto->issn = $this->field($raw, 'SN');
        $dto->isbn = $this->field($raw, 'BN');

        // Times cited
        $tc = $this->field($raw, 'TC');
        $dto->citedBy = $tc && ctype_digit($tc) ? (int) $tc : null;

        // Open Access
        $dto->openAccessStatus = $this->field($raw, 'OA');

        // ── Authors ──────────────────────────────────────────────────────────
        // AF = full names (preferred); AU = abbreviated
        $afLines = $raw['AF'] ?? $raw['AU'] ?? [];
        if (is_string($afLines)) {
            $afLines = [$afLines];
        }
        $dto->authorNames = array_values(array_filter(array_map('trim', $afLines)));

        // ── Keywords ─────────────────────────────────────────────────────────
        // DE = Author Keywords; ID = Keywords Plus (WoS-indexed)
        $de = $this->field($raw, 'DE');
        if ($de) {
            $dto->authorKeywords = array_values(array_filter(
                array_map('trim', explode(';', $de))
            ));
        }
        $id = $this->field($raw, 'ID');
        if ($id) {
            $dto->indexedKeywords = array_values(array_filter(
                array_map('trim', explode(';', $id))
            ));
        }

        // ── Affiliations ─────────────────────────────────────────────────────
        // C1 lines: "[Author Name] Institution, City, Country."
        $c1Lines = $raw['C1'] ?? [];
        if (is_string($c1Lines)) {
            $c1Lines = [$c1Lines];
        }
        $dto->affiliations  = array_values(array_filter(array_map('trim', $c1Lines)));
        $dto->countries     = $this->extractCountriesFromC1($c1Lines);
        $dto->institutions  = $this->extractInstitutionsFromC1($c1Lines);

        // ── Cited references ─────────────────────────────────────────────────
        $crLines = $raw['CR'] ?? [];
        if (is_string($crLines)) {
            $crLines = [$crLines];
        }
        $dto->references = array_values(array_filter(array_map('trim', $crLines)));

        return $dto;
    }

    public function getSourceName(): string { return 'wos'; }
    public function getFormatName(): string { return 'txt'; }

    // ── Private: WoS file parser ─────────────────────────────────────────────

    /**
     * Generator that reads the WoS plain-text file and yields one record
     * at a time as ['TAG' => string|string[], ...].
     *
     * Multi-value tags (AU, AF, C1, CR) are always returned as arrays.
     *
     * @return \Generator<array<string, string|string[]>>
     */
    private function parseRecordsFromFile(string $filePath): \Generator
    {
        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            return;
        }

        // Tags whose values accumulate as array (multi-line multi-value)
        $arrayTags = ['AU', 'AF', 'C1', 'C3', 'CR', 'WC', 'SC', 'SP', 'BE'];

        $record     = [];
        $currentTag = null;
        $inRecord   = false;

        while (($raw = fgets($handle)) !== false) {
            // Strip UTF-8 BOM on first line
            $line = ltrim($raw, "\xEF\xBB\xBF");
            $line = rtrim($line, "\r\n");

            // Skip header lines (FN / VR) and blank lines between records
            if (!$inRecord) {
                if (str_starts_with($line, 'PT ') || str_starts_with($line, 'PT\t')) {
                    $inRecord   = true;
                    $record     = [];
                    $currentTag = 'PT';
                    $record['PT'] = trim(substr($line, 3));
                }
                // else: skip (header / blank / EF end-of-file marker)
                continue;
            }

            // End of record
            if ($line === 'ER' || $line === 'ER ') {
                yield $record;
                $record     = [];
                $currentTag = null;
                $inRecord   = false;
                continue;
            }

            // Continuation line (starts with at least 3 spaces or a tab)
            if (isset($line[0]) && ($line[0] === ' ' || $line[0] === "\t") && $currentTag !== null) {
                $value = trim($line);
                if ($value === '') continue;
                if (in_array($currentTag, $arrayTags, true)) {
                    $record[$currentTag][] = $value;
                } else {
                    // Append to existing string value with a space
                    $record[$currentTag] = ($record[$currentTag] ?? '') . ' ' . $value;
                }
                continue;
            }

            // New tag line: "XX value"
            if (strlen($line) >= 2 && $line[2] === ' ') {
                $tag   = substr($line, 0, 2);
                $value = trim(substr($line, 3));

                // Skip end-of-file marker
                if ($tag === 'EF') {
                    break;
                }

                $currentTag = $tag;

                if (in_array($tag, $arrayTags, true)) {
                    if (!isset($record[$tag])) {
                        $record[$tag] = [];
                    }
                    if ($value !== '') {
                        $record[$tag][] = $value;
                    }
                } else {
                    $record[$tag] = $value;
                }
                continue;
            }

            // Fallback: append to current tag if line doesn't match any pattern
            if ($currentTag !== null && trim($line) !== '') {
                $value = trim($line);
                if (in_array($currentTag, $arrayTags, true)) {
                    $record[$currentTag][] = $value;
                } else {
                    $record[$currentTag] = ($record[$currentTag] ?? '') . ' ' . $value;
                }
            }
        }

        // Yield last record if file doesn't end with ER
        if (!empty($record)) {
            yield $record;
        }

        fclose($handle);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Returns the string value of a tag, or null if not present / empty.
     * For array-valued tags, joins elements with '; '.
     */
    private function field(array $raw, string $tag): ?string
    {
        if (!isset($raw[$tag])) return null;
        $val = $raw[$tag];
        if (is_array($val)) {
            $val = implode('; ', $val);
        }
        $val = trim((string) $val);
        return $val !== '' ? $val : null;
    }

    private function normalizeDoi(?string $doi): ?string
    {
        if (!$doi) return null;
        $doi = trim($doi);
        $doi = preg_replace('#^https?://doi\.org/#i', '', $doi);
        return $doi !== '' ? $doi : null;
    }

    /** Expand PT single-letter codes to readable document type. */
    private function expandPt(?string $pt): ?string
    {
        return match (strtoupper(trim((string) $pt))) {
            'J'  => 'Article',
            'C'  => 'Proceedings Paper',
            'B'  => 'Book',
            'S'  => 'Book Chapter',
            'P'  => 'Patent',
            'R'  => 'Review',
            default => $pt,
        };
    }

    /**
     * Extract countries from C1 (affiliation) lines.
     * C1 format: "[Author] Institution, City, Country."
     */
    private function extractCountriesFromC1(array $c1Lines): array
    {
        $countries = [];
        foreach ($c1Lines as $line) {
            // Remove author bracket prefix if present: [Name] Institution...
            $line = preg_replace('/^\[.*?\]\s*/', '', $line);
            // Last comma-separated segment is typically the country
            $parts = array_map('trim', explode(',', $line));
            $last  = end($parts);
            // Strip trailing dot
            $last = rtrim((string) $last, '. ');
            if ($last && strlen($last) > 1) {
                $countries[] = $last;
            }
        }
        return array_values(array_unique(array_filter($countries)));
    }

    /**
     * Extract institution names from C1 lines.
     */
    private function extractInstitutionsFromC1(array $c1Lines): array
    {
        $institutions = [];
        foreach ($c1Lines as $line) {
            // Remove author bracket prefix
            $line = preg_replace('/^\[.*?\]\s*/', '', $line);
            // First comma-separated part is usually the institution
            $parts = array_map('trim', explode(',', $line));
            $first = $parts[0] ?? '';
            $first = trim($first, '. ');
            if ($first && strlen($first) > 3) {
                $institutions[] = $first;
            }
        }
        return array_values(array_unique(array_filter($institutions)));
    }
}
