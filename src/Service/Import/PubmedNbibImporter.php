<?php

namespace App\Service\Import;

use App\DTO\BibliographicRecordDTO;
use App\DTO\ImportResult;

/**
 * Importer for PubMed / MEDLINE plain-text/NBIB tagged format.
 *
 * File signature (each record starts with):
 *   PMID- [value]
 *
 * Each tag line consists of:
 *   - A 4-character tag name (padded with spaces on the right)
 *   - A hyphen "-"
 *   - A space " "
 *   - The field value
 *
 * Continuation lines start with spaces or tabs (usually 6 spaces).
 * Records are separated by a blank line or indicated by a new "PMID-" tag.
 */
class PubmedNbibImporter implements BibliographicImporterInterface
{
    private const HEADER_SCAN_BYTES = 1000;

    public function supports(string $format, ?string $source = null): bool
    {
        $fmt = strtolower($format);
        $src = strtolower($source ?? '');

        // Support both explicitly configured pubmed txt/nbib, or generic formats if detected
        return ($fmt === 'nbib') || ($fmt === 'txt' && in_array($src, ['pubmed', ''], true));
    }

    /**
     * Auto-detect if a file is a PubMed MEDLINE tagged file.
     * Looks for lines starting with "PMID- " or containing multiple tags like "OWN - ", "STAT- ".
     */
    public function detect(string $filePath): float
    {
        try {
            $handle = fopen($filePath, 'r');
            if (!$handle) {
                return 0.0;
            }

            $content = fread($handle, self::HEADER_SCAN_BYTES);
            fclose($handle);

            $content = ltrim($content, "\xEF\xBB\xBF"); // strip UTF-8 BOM

            // PMID- at the absolute start is a 100% signal
            if (str_starts_with($content, 'PMID- ')) {
                return 1.0;
            }

            // Strong signals: has both PMID- and OWN - or STAT-
            if (preg_match('/^PMID-\s*\d+/m', $content) && preg_match('/^OWN\s*-\s*[A-Z]+/m', $content)) {
                return 0.95;
            }

            // Mild signal: has PMID- tag anywhere in the first chunk
            if (preg_match('/^PMID-\s*\d+/m', $content)) {
                return 0.7;
            }

            return 0.0;
        } catch (\Throwable) {
            return 0.0;
        }
    }

    public function parse(string $filePath, int $limit = 0): ImportResult
    {
        $result = new ImportResult();
        $result->detectedSource = 'pubmed';
        $result->detectedFormat = 'nbib';
        $result->headers = ['PMID', 'TI', 'AB', 'DP', 'AID', 'LID', 'JT', 'FAU', 'AD', 'MH', 'OT'];

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
     * Keeps memory consumption minimal.
     *
     * @return \Generator<BibliographicRecordDTO>
     */
    public function parseStream(string $filePath): \Generator
    {
        foreach ($this->parseRecordsFromFile($filePath) as $raw) {
            try {
                yield $this->mapToInternalRecord($raw);
            } catch (\Throwable) {
                // skip malformed records silently in stream
            }
        }
    }

    public function countRows(string $filePath): int
    {
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            return 0;
        }

        $count = 0;
        while (($line = fgets($handle)) !== false) {
            if (str_starts_with($line, 'PMID- ')) {
                $count++;
            }
        }
        fclose($handle);

        return $count;
    }

    public function mapToInternalRecord(array $raw): BibliographicRecordDTO
    {
        $dto = new BibliographicRecordDTO();
        $dto->source = 'pubmed';
        $dto->rawData = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // PMID & ExternalId
        $pmid = $this->field($raw, 'PMID');
        $dto->externalId = $pmid;
        $dto->pmid = $pmid;
        if ($pmid) {
            $dto->url = 'https://pubmed.ncbi.nlm.nih.gov/' . $pmid . '/';
        }

        // Title
        $dto->title = $this->field($raw, 'TI');

        // Abstract
        $dto->abstractText = $this->field($raw, 'AB');

        // Year from publication date (DP) e.g., "2021 Dec 7" or "2021 Jul" or "2021"
        $dp = $this->field($raw, 'DP');
        if ($dp && preg_match('/^(19|20)\d{2}/', $dp, $matches)) {
            $dto->year = (int) $matches[0];
        }

        // DOI from AID or LID (matching e.g. "10.1093/eurheartj/ehab649 [doi]")
        $doi = null;
        $aids = $raw['AID'] ?? [];
        if (is_string($aids)) {
            $aids = [$aids];
        }
        foreach ($aids as $aid) {
            if (str_contains($aid, '[doi]')) {
                $doi = trim(str_replace('[doi]', '', $aid));
                break;
            }
        }
        if (!$doi) {
            $lids = $raw['LID'] ?? [];
            if (is_string($lids)) {
                $lids = [$lids];
            }
            foreach ($lids as $lid) {
                if (str_contains($lid, '[doi]')) {
                    $doi = trim(str_replace('[doi]', '', $lid));
                    break;
                }
            }
        }
        $dto->doi = $this->normalizeDoi($doi);

        // Source Title (Journal) - prefer JT, fallback to TA
        $dto->sourceTitle = $this->field($raw, 'JT') ?? $this->field($raw, 'TA');

        // Volume / Issue / Pages
        $dto->volume = $this->field($raw, 'VI');
        $dto->issue = $this->field($raw, 'IP');
        $pg = $this->field($raw, 'PG');
        if ($pg) {
            $parts = explode('-', $pg);
            $dto->pageStart = trim($parts[0]);
            $dto->pageEnd = isset($parts[1]) ? trim($parts[1]) : null;
        }

        // Language
        $dto->language = $this->field($raw, 'LA');

        // Document Type
        $dto->documentType = $this->field($raw, 'PT') ?? 'Article';

        // Authors - prefer FAU (Full Author Name) over AU (Abbreviated)
        $authorLines = $raw['FAU'] ?? $raw['AU'] ?? [];
        if (is_string($authorLines)) {
            $authorLines = [$authorLines];
        }
        $dto->authorNames = array_values(array_filter(array_map('trim', $authorLines)));

        // Keywords:
        // OT (Other Terms) -> authorKeywords
        $ots = $raw['OT'] ?? [];
        if (is_string($ots)) {
            $ots = [$ots];
        }
        $dto->authorKeywords = array_values(array_filter(array_map(function ($kw) {
            return rtrim(trim($kw), '*');
        }, $ots)));

        // MH (MeSH Terms) -> indexedKeywords
        $mhs = $raw['MH'] ?? [];
        if (is_string($mhs)) {
            $mhs = [$mhs];
        }
        $dto->indexedKeywords = array_values(array_filter(array_map(function ($kw) {
            return rtrim(trim($kw), '*');
        }, $mhs)));

        // Affiliations (AD)
        $adLines = $raw['AD'] ?? [];
        if (is_string($adLines)) {
            $adLines = [$adLines];
        }
        $dto->affiliations = array_values(array_filter(array_map('trim', $adLines)));
        $dto->countries = $this->extractCountriesFromAd($dto->affiliations);
        $dto->institutions = $this->extractInstitutionsFromAd($dto->affiliations);

        return $dto;
    }

    public function getSourceName(): string { return 'pubmed'; }
    public function getFormatName(): string { return 'nbib'; }

    /**
     * Reads MEDLINE plain text and yields records as assoc arrays.
     */
    private function parseRecordsFromFile(string $filePath): \Generator
    {
        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            return;
        }

        $arrayTags = ['AU', 'FAU', 'AD', 'MH', 'OT', 'AID', 'LID', 'PT'];

        $record = [];
        $currentTag = null;
        $inRecord = false;

        while (($raw = fgets($handle)) !== false) {
            $line = ltrim($raw, "\xEF\xBB\xBF"); // strip BOM
            $line = rtrim($line, "\r\n");

            if (trim($line) === '') {
                continue;
            }

            // Continuation line
            if (isset($line[0]) && ($line[0] === ' ' || $line[0] === "\t") && $currentTag !== null) {
                $value = trim($line);
                if ($value === '') {
                    continue;
                }
                if (in_array($currentTag, $arrayTags, true)) {
                    $lastIdx = count($record[$currentTag]) - 1;
                    if ($lastIdx >= 0) {
                        $record[$currentTag][$lastIdx] .= ' ' . $value;
                    } else {
                        $record[$currentTag][] = $value;
                    }
                } else {
                    $record[$currentTag] = ($record[$currentTag] ?? '') . ' ' . $value;
                }
                continue;
            }

            // New tag line
            if (strlen($line) >= 6 && $line[4] === '-' && $line[5] === ' ') {
                $tag = trim(substr($line, 0, 4));
                $value = trim(substr($line, 6));

                if ($tag === 'PMID') {
                    if ($inRecord && !empty($record)) {
                        yield $record;
                    }
                    $record = [];
                    $inRecord = true;
                }

                if (!$inRecord) {
                    continue;
                }

                $currentTag = $tag;

                if (in_array($tag, $arrayTags, true)) {
                    if (!isset($record[$tag])) {
                        $record[$tag] = [];
                    }
                    $record[$tag][] = $value;
                } else {
                    $record[$tag] = $value;
                }
            }
        }

        if ($inRecord && !empty($record)) {
            yield $record;
        }

        fclose($handle);
    }

    private function field(array $raw, string $tag): ?string
    {
        if (!isset($raw[$tag])) {
            return null;
        }
        $val = $raw[$tag];
        if (is_array($val)) {
            $val = implode('; ', $val);
        }
        $val = trim((string) $val);
        return $val !== '' ? $val : null;
    }

    private function normalizeDoi(?string $doi): ?string
    {
        if (!$doi) {
            return null;
        }
        $doi = trim($doi);
        $doi = preg_replace('#^https?://doi\.org/#i', '', $doi);
        return $doi !== '' ? $doi : null;
    }

    private function extractCountriesFromAd(array $adLines): array
    {
        $countries = [];
        foreach ($adLines as $line) {
            $line = preg_replace('/^\[.*?\]\s*/', '', $line);
            $parts = array_map('trim', explode(',', $line));
            $last = end($parts);
            $last = rtrim((string) $last, '. ');
            if ($last && !str_contains($last, '@') && strlen($last) > 1) {
                $countries[] = $last;
            }
        }
        return array_values(array_unique(array_filter($countries)));
    }

    private function extractInstitutionsFromAd(array $adLines): array
    {
        $institutions = [];
        foreach ($adLines as $line) {
            $line = preg_replace('/^\[.*?\]\s*/', '', $line);
            $parts = array_map('trim', explode(',', $line));
            $first = $parts[0] ?? '';
            $first = trim($first, '. ');
            if ($first && !str_contains($first, '@') && strlen($first) > 3) {
                $institutions[] = $first;
            }
        }
        return array_values(array_unique(array_filter($institutions)));
    }
}
