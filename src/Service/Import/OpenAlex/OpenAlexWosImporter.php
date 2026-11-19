<?php

namespace App\Service\Import\OpenAlex;

use App\DTO\BibliographicRecordDTO;
use App\Service\Import\WosImporter;

/**
 * "Text (Web of Science plain text)" exportado pelo OpenAlex: mesmo formato de marcações da Web of Science, com
 * cabeçalho "FN OpenAlex". Grava a base OpenAlex, o Work ID de UT (quando houver) e as citações de TC ou, no
 * exportador antigo, de CT.
 */
class OpenAlexWosImporter extends WosImporter
{
    public function supports(string $format, ?string $source = null): bool
    {
        return strtolower($format) === 'txt' && strtolower($source ?? '') === 'openalex';
    }

    public function detect(string $filePath): float
    {
        $h = @fopen($filePath, 'rb');
        if (!$h) {
            return 0.0;
        }
        $head = ltrim((string) fread($h, self::HEADER_SCAN_BYTES), "\xEF\xBB\xBF");
        fclose($h);

        return preg_match('/^FN OpenAlex/m', $head) ? 1.0 : 0.0;
    }

    public function mapToInternalRecord(array $raw): BibliographicRecordDTO
    {
        $dto = parent::mapToInternalRecord($raw);
        $dto->source = 'openalex';
        $dto->externalId = OpenAlexWorkMapper::workId($this->field($raw, 'UT'));
        $dto->strictIdentity = $dto->externalId !== null;
        if ($dto->citedBy === null) {
            $ct = $this->field($raw, 'CT');
            $dto->citedBy = $ct !== null && ctype_digit($ct) ? (int) $ct : null;
        }
        // Mesma grafia do JSON e do CSV do OpenAlex: tipo em minúsculas ("book-chapter"), acesso aberto "Green"
        if ($dto->documentType !== null) {
            $dto->documentType = strtolower($dto->documentType);
        }
        if ($dto->openAccessStatus !== null && $dto->openAccessStatus !== '') {
            $dto->openAccessStatus = strtolower($dto->openAccessStatus) === 'closed' ? 'Closed' : ucfirst(strtolower($dto->openAccessStatus));
        }
        foreach (['volume', 'issue', 'pageStart', 'pageEnd', 'issn', 'language', 'publisher', 'sourceTitle'] as $f) {
            if ($dto->$f !== null && trim($dto->$f) === '') {
                $dto->$f = null;
            }
        }

        return $dto;
    }

    public function getSourceName(): string { return 'OpenAlex'; }
    public function getSourceKey(): string { return 'openalex'; }
}
