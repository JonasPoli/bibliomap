<?php

namespace App\DTO;

class ImportResult
{
    public int $totalRead = 0;
    public int $totalValid = 0;
    public int $totalErrors = 0;
    public string $detectedSource = '';
    public string $detectedFormat = '';

    /** @var array<string> */
    public array $errors = [];

    /** @var BibliographicRecordDTO[] */
    public array $records = [];

    /** @var array<string, mixed> */
    public array $sample = [];

    /** @var string[] */
    public array $headers = [];
}
