<?php

namespace App\Service\Import\Brapci;

use App\DTO\BibliographicRecordDTO;
use App\Service\Import\PreviewNoticesInterface;
use App\Service\Import\Ris\AbstractRisImporter;
use App\Service\Import\Ris\RisReader;

/**
 * RIS da cesta da BRAPCI: sem acentos (ASCII), TY sempre JOUR (o tipo real vem em M3) e o ID só no fim do
 * endereço UR (handle). Sem DOI.
 */
class BrapciRisImporter extends AbstractRisImporter implements PreviewNoticesInterface
{
    public function supports(string $format, ?string $source = null): bool
    {
        return strtolower($format) === 'ris' && strtolower($source ?? '') === 'brapci';
    }

    public function detect(string $filePath): float
    {
        foreach (RisReader::head($filePath, 200) as $line) {
            if (preg_match('/^DB  - BRAPCI\s*$/', $line)) {
                return 1.0;
            }
        }

        return 0.0;
    }

    protected function mapFields(array $fields, int $position): ?BibliographicRecordDTO
    {
        $ur = self::first($fields, 'UR');
        if ($ur === null || !preg_match('#brapci/(\d+)\s*$#', $ur, $m)) {
            throw new \RuntimeException('Registro sem o endereço UR com o ID da BRAPCI.');
        }
        $dto = new BibliographicRecordDTO();
        $dto->source = 'brapci';
        $dto->externalId = $m[1];
        $dto->strictIdentity = true;
        $dto->documentType = self::first($fields, 'M3');
        $dto->title = self::cleanText(self::first($fields, 'TI', 'T1'));
        $dto->authorNames = array_values(array_filter(array_map(fn ($a) => self::oneLine($a), self::all($fields, 'AU'))));
        $dto->year = self::yearFrom(self::first($fields, 'PY'));
        $dto->sourceTitle = self::cleanText(self::first($fields, 'JO', 'PB'));
        $dto->volume = self::first($fields, 'VL');
        $dto->issue = self::first($fields, 'IS');
        $dto->abstractText = self::cleanText(self::first($fields, 'AB'));
        $dto->authorKeywords = array_values(array_unique(array_filter(array_map(fn ($k) => self::oneLine($k), self::all($fields, 'KW')))));
        $dto->url = BrapciRecordMapper::HANDLE_PREFIX . $m[1];

        return $dto;
    }

    public function previewNotice(string $filePath): ?string
    {
        return 'O RIS da BRAPCI vem sem acentos e sem DOI. Prefira o CSV.';
    }

    public function blockingNotice(string $filePath): ?string
    {
        return null;
    }

    public function getSourceName(): string { return 'BRAPCI'; }
    public function getSourceKey(): string { return 'brapci'; }
}
