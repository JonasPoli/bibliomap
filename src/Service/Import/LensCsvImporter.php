<?php

namespace App\Service\Import;

use App\DTO\BibliographicRecordDTO;
use App\DTO\ImportResult;
use League\Csv\Reader;

class LensCsvImporter implements BibliographicImporterInterface
{
    private const SIGNATURE_COLUMNS = ['Lens ID', 'Publication Year', 'Author/s', 'Citing Works Count'];

    public function supports(string $format, ?string $source = null): bool
    {
        return strtolower($format) === 'csv' && in_array(strtolower($source ?? ''), ['lens', '']);
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
        $result->detectedSource = 'lens';
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

    public function parseStream(string $filePath): \Generator
    {
        $reader = Reader::from($filePath);
        $reader->setHeaderOffset(0);

        foreach ($reader->getRecords() as $record) {
            try {
                yield $this->mapToInternalRecord($record);
            } catch (\Throwable) {
                // skip malformed rows silently
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
        return max(0, $count - 1);
    }

    public function mapToInternalRecord(array $rawRecord): BibliographicRecordDTO
    {
        $dto = new BibliographicRecordDTO();
        $dto->source = 'lens';
        $dto->rawData = json_encode($rawRecord, JSON_UNESCAPED_UNICODE);

        $dto->externalId = $this->get($rawRecord, 'Lens ID');
        $dto->title = $this->get($rawRecord, 'Title');
        
        $abstract = $this->get($rawRecord, 'Abstract');
        if ($abstract) {
            $dto->abstractText = strip_tags($abstract);
        }

        $dto->year = $this->getInt($rawRecord, 'Publication Year');
        $dto->documentType = $this->get($rawRecord, 'Publication Type');
        $dto->doi = $this->normalizeDoi($this->get($rawRecord, 'DOI'));
        $dto->pmid = $this->get($rawRecord, 'PMID');
        $dto->issn = $this->get($rawRecord, 'ISSNs');
        $dto->url = $this->get($rawRecord, 'External URL') ?? $this->get($rawRecord, 'Source URLs');
        $dto->sourceTitle = $this->get($rawRecord, 'Source Title');
        $dto->volume = $this->get($rawRecord, 'Volume');
        $dto->issue = $this->get($rawRecord, 'Issue Number');
        $dto->pageStart = $this->get($rawRecord, 'Start Page');
        $dto->pageEnd = $this->get($rawRecord, 'End Page');
        $dto->publisher = $this->get($rawRecord, 'Publisher');
        $dto->citedBy = $this->getInt($rawRecord, 'Citing Works Count');
        
        $isOpenAccess = $this->get($rawRecord, 'Is Open Access');
        $oaColour = $this->get($rawRecord, 'Open Access Colour');
        if ($isOpenAccess === 'true') {
            $dto->openAccessStatus = $oaColour ? ucfirst(strtolower($oaColour)) : 'Open Access';
        } else {
            $dto->openAccessStatus = 'Closed';
        }

        $authorsRaw = $this->get($rawRecord, 'Author/s');
        if ($authorsRaw) {
            $dto->authorNames = array_map('trim', explode(';', $authorsRaw));
        }

        $kwRaw = $this->get($rawRecord, 'Keywords');
        if ($kwRaw) {
            $dto->authorKeywords = array_filter(array_map('trim', explode(';', $kwRaw)));
        }

        $fieldsRaw = $this->get($rawRecord, 'Fields of Study');
        if ($fieldsRaw) {
            $dto->indexedKeywords = array_filter(array_map('trim', explode(';', $fieldsRaw)));
        }

        $country = $this->get($rawRecord, 'Source Country');
        if ($country) {
            $dto->countries = [ucfirst(strtolower(trim($country)))];
        }

        return $dto;
    }

    public function getSourceName(): string { return 'Lens.org'; }
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
