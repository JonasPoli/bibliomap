<?php

namespace App\Service\Import;

use App\DTO\BibliographicRecordDTO;
use App\DTO\ImportResult;
use League\Csv\Reader;

class PubmedCsvImporter implements BibliographicImporterInterface
{
    // PubMed CSV signature columns
    private const SIGNATURE_COLUMNS = ['PMID', 'Title', 'Authors', 'Citation', 'Journal/Book', 'Publication Year'];

    public function supports(string $format, ?string $source = null): bool
    {
        return strtolower($format) === 'csv' && in_array(strtolower($source ?? ''), ['pubmed', '']);
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

    public function parse(string $filePath, int $limit = 0): ImportResult
    {
        $result = new ImportResult();
        $result->detectedSource = 'pubmed';
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
        $dto->source = 'pubmed';
        $dto->rawData = json_encode($rawRecord, JSON_UNESCAPED_UNICODE);

        $pmid = $this->get($rawRecord, 'PMID');
        $dto->externalId = $pmid;
        $dto->pmid = $pmid;

        $dto->title = $this->get($rawRecord, 'Title');
        $dto->year = $this->getInt($rawRecord, 'Publication Year');
        $dto->doi = $this->normalizeDoi($this->get($rawRecord, 'DOI'));
        $dto->sourceTitle = $this->get($rawRecord, 'Journal/Book');
        $dto->documentType = 'Article'; // Default to Article for PubMed CSV

        if ($pmid) {
            $dto->url = 'https://pubmed.ncbi.nlm.nih.gov/' . $pmid . '/';
        }

        // Parse authors separated by comma with optional trailing dot: "Author A, Author B, Author C."
        $authorsRaw = $this->get($rawRecord, 'Authors');
        if ($authorsRaw) {
            $authorsRaw = rtrim($authorsRaw, '.');
            $dto->authorNames = array_filter(array_map('trim', explode(',', $authorsRaw)));
        }

        return $dto;
    }

    public function getSourceName(): string { return 'PubMed'; }
    public function getFormatName(): string { return 'CSV'; }

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

    private function normalizeDoi(?string $doi): ?string
    {
        if (!$doi) return null;
        $doi = trim($doi);
        $doi = preg_replace('#^https?://doi\.org/#i', '', $doi);
        return $doi !== '' ? $doi : null;
    }
}
