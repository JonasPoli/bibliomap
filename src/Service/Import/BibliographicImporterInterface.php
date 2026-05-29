<?php

namespace App\Service\Import;

use App\DTO\BibliographicRecordDTO;
use App\DTO\ImportResult;

interface BibliographicImporterInterface
{
    public function supports(string $format, ?string $source = null): bool;

    /**
     * Detect if this file belongs to this importer based on headers/content.
     * Returns confidence score 0-1.
     */
    public function detect(string $filePath): float;

    /**
     * Parse the file and return all records.
     * For large files, processes in streaming mode.
     */
    public function parse(string $filePath, int $limit = 0): ImportResult;

    public function mapToInternalRecord(array $rawRecord): BibliographicRecordDTO;

    public function getSourceName(): string;
    public function getFormatName(): string;
}
