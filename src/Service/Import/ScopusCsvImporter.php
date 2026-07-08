<?php

namespace App\Service\Import;

use App\DTO\BibliographicRecordDTO;
use App\DTO\ImportResult;
use League\Csv\Reader;

class ScopusCsvImporter implements BibliographicImporterInterface
{
    // Scopus signature columns
    private const SIGNATURE_COLUMNS = ['EID', 'Authors', 'Cited by', 'Author Keywords'];

    public function supports(string $format, ?string $source = null): bool
    {
        return strtolower($format) === 'csv' && in_array(strtolower($source ?? ''), ['scopus', '']);
    }

    public function detect(string $filePath): float
    {
        try {
            $reader = Reader::from($filePath);
            $reader->setHeaderOffset(0);
            $headers = $reader->getHeader();

            $found = 0;
            foreach (self::SIGNATURE_COLUMNS as $sig) {
                foreach ($headers as $h) {
                    if (trim($h) === $sig) {
                        $found++;
                        break;
                    }
                }
            }

            return $found / count(self::SIGNATURE_COLUMNS);
        } catch (\Throwable) {
            return 0.0;
        }
    }

    /**
     * Parse a limited number of rows for preview only.
     * Never use this for full imports — use parseStream() instead.
     */
    public function parse(string $filePath, int $limit = 0): ImportResult
    {
        $result = new ImportResult();
        $result->detectedSource = 'scopus';
        $result->detectedFormat = 'csv';

        $reader = Reader::from($filePath);
        $reader->setHeaderOffset(0);
        $result->headers = $reader->getHeader();

        $count = 0;
        foreach ($reader->getRecords() as $offset => $record) {
            $result->totalRead++;
            try {
                $dto = $this->mapToInternalRecord($record);
                if (empty($result->sample)) {
                    $result->sample = $record;
                }
                $result->records[] = $dto;
                $result->totalValid++;
                $count++;
                if ($limit > 0 && $count >= $limit) {
                    break;
                }
            } catch (\Throwable $e) {
                $result->totalErrors++;
                $result->errors[] = "Linha {$offset}: " . $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * Streaming generator — yields ONE DTO at a time.
     * Use this for full imports to avoid memory exhaustion.
     *
     * @return \Generator<BibliographicRecordDTO>
     */
    public function parseStream(string $filePath): \Generator
    {
        $reader = Reader::from($filePath);
        $reader->setHeaderOffset(0);

        foreach ($reader->getRecords() as $record) {
            try {
                yield $this->mapToInternalRecord($record);
            } catch (\Throwable) {
                // skip malformed rows silently in streaming mode
            }
        }
    }

    /**
     * Count total rows (fast scan, no record parsing).
     */
    public function countRows(string $filePath): int
    {
        $handle = fopen($filePath, 'r');
        if (!$handle) return 0;
        $count = 0;
        while (!feof($handle)) {
            fgets($handle);
            $count++;
        }
        fclose($handle);
        return max(0, $count - 1); // minus header
    }

    public function mapToInternalRecord(array $rawRecord): BibliographicRecordDTO
    {
        $dto = new BibliographicRecordDTO();
        $dto->source = 'scopus';
        $dto->rawData = json_encode($rawRecord, JSON_UNESCAPED_UNICODE);

        $dto->externalId = $this->get($rawRecord, 'EID');
        $dto->title = $this->get($rawRecord, 'Title');
        $dto->abstractText = $this->get($rawRecord, 'Abstract');
        $dto->year = $this->getInt($rawRecord, 'Year');
        $dto->documentType = $this->get($rawRecord, 'Document Type');
        $dto->doi = $this->normalizeDoi($this->get($rawRecord, 'DOI'));
        $dto->pmid = $this->get($rawRecord, 'PubMed ID');
        $dto->issn = $this->get($rawRecord, 'ISSN');
        $dto->isbn = $this->get($rawRecord, 'ISBN');
        $dto->url = $this->get($rawRecord, 'Link');
        $dto->language = $this->get($rawRecord, 'Language of Original Document');
        $dto->sourceTitle = $this->get($rawRecord, 'Source title');
        $dto->volume = $this->get($rawRecord, 'Volume');
        $dto->issue = $this->get($rawRecord, 'Issue');
        $dto->pageStart = $this->get($rawRecord, 'Page start');
        $dto->pageEnd = $this->get($rawRecord, 'Page end');
        $dto->publisher = $this->get($rawRecord, 'Publisher');
        $dto->citedBy = $this->getInt($rawRecord, 'Cited by');
        $dto->openAccessStatus = $this->get($rawRecord, 'Open Access');
        $dto->publicationStage = $this->get($rawRecord, 'Publication Stage');

        // Authors — separated by semicolon
        $authorsRaw = $this->get($rawRecord, 'Authors');
        if ($authorsRaw) {
            $dto->authorNames = array_map('trim', explode(';', $authorsRaw));
        }

        // Author Keywords
        $kwRaw = $this->get($rawRecord, 'Author Keywords');
        if ($kwRaw) {
            $dto->authorKeywords = array_filter(array_map('trim', explode(';', $kwRaw)));
        }

        // Index Keywords
        $ikRaw = $this->get($rawRecord, 'Index Keywords');
        if ($ikRaw) {
            $dto->indexedKeywords = array_filter(array_map('trim', explode(';', $ikRaw)));
        }

        // Affiliations — split by semicolon
        $affRaw = $this->get($rawRecord, 'Affiliations');
        if ($affRaw) {
            $dto->affiliations = array_filter(array_map('trim', explode(';', $affRaw)));
            $dto->countries = $this->extractCountries($affRaw);
            $dto->institutions = $this->extractInstitutions($affRaw);
        }

        // References
        $refRaw = $this->get($rawRecord, 'References');
        if ($refRaw) {
            $dto->references = array_filter(array_map('trim', explode(';', $refRaw)));
        }

        return $dto;
    }

    public function getSourceName(): string { return 'Scopus'; }
    public function getFormatName(): string { return 'CSV'; }

    // ── Helpers ────────────────────────────────────────

    private function get(array $record, string $key): ?string
    {
        foreach ($record as $k => $v) {
            if (trim($k) === $key) {
                $val = trim((string) $v);
                return $val !== '' ? $val : null;
            }
        }
        return null;
    }

    private function getInt(array $record, string $key): ?int
    {
        $val = $this->get($record, $key);
        return $val !== null && is_numeric($val) ? (int) $val : null;
    }

    private function computeHash(?string $title, ?string $doi, array $record): ?string
    {
        if ($doi) {
            return md5('doi:' . strtolower(trim($doi)));
        }
        $yearVal = $this->get($record, 'Year');
        $year = $yearVal && is_numeric($yearVal) ? (int)$yearVal : null;
        if ($title && $year) {
            $normalized = preg_replace('/\s+/', ' ', strtolower(trim($title)));
            return md5($normalized . ':' . $year);
        }
        return null;
    }

    private function normalizeDoi(?string $doi): ?string
    {
        if (!$doi) return null;
        $doi = trim($doi);
        // Remove "https://doi.org/" prefix if present
        $doi = preg_replace('#^https?://doi\.org/#i', '', $doi);
        return $doi !== '' ? $doi : null;
    }

    private function extractCountries(string $affiliationText): array
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

            $country = $this->normalizeCountryFromAffiliationLastSegment($last);

            if ($country !== null) {
                $countries[] = $country;
            }
        }

        return array_values(array_unique(array_filter($countries)));
    }

    private function normalizeCountryFromAffiliationLastSegment(string $value): ?string
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

    private function extractInstitutions(string $affiliationText): array
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
