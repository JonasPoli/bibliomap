<?php

namespace App\Service\Import;

use App\DTO\BibliographicRecordDTO;
use App\Service\Import\Ris\AbstractRisImporter;
use App\Service\Import\Ris\RisReader;

/**
 * RIS de uma base sem leitor próprio, gravado com a base "Outra". Lê as marcações usuais do padrão. Fica abaixo
 * dos leitores de base (que reconhecem o próprio dialeto com pontuação 0,8 ou mais) e deduplica como as bases
 * atuais (DOI e título + ano), sem identidade própria.
 */
class GenericRisImporter extends AbstractRisImporter
{
    public function supports(string $format, ?string $source = null): bool
    {
        return strtolower($format) === 'ris' && in_array(strtolower($source ?? ''), ['generic', 'other', ''], true);
    }

    public function detect(string $filePath): float
    {
        $head = RisReader::head($filePath, 200);
        if ($head === []) {
            return 0.0;
        }
        $tags = 0;
        $ty = false;
        foreach ($head as $line) {
            if (RisReader::isTagLine($line)) {
                $tags++;
                if (str_starts_with($line, 'TY  -')) {
                    $ty = true;
                }
            }
        }

        return $ty && $tags / count($head) >= 0.8 ? 0.55 : 0.0;
    }

    protected function mapFields(array $fields, int $position): ?BibliographicRecordDTO
    {
        $dto = new BibliographicRecordDTO();
        $dto->source = 'generic';
        $dto->title = self::cleanText(self::first($fields, 'TI', 'T1', 'CT', 'BT'));
        $dto->authorNames = array_map(fn ($a) => self::oneLine($a), self::all($fields, 'AU', 'A1'));
        $dto->year = self::yearFrom(self::first($fields, 'PY', 'Y1', 'DA'));
        $dto->sourceTitle = self::cleanText(self::first($fields, 'JO', 'JF', 'T2', 'JA', 'J2', 'BT'));
        $dto->volume = self::first($fields, 'VL');
        $dto->issue = self::first($fields, 'IS');
        $dto->pageStart = self::first($fields, 'SP');
        $dto->pageEnd = self::first($fields, 'EP');
        $sn = self::first($fields, 'SN');
        $dto->issn = self::issnFrom($sn);
        if ($dto->issn === null && $sn !== null && preg_match('/^[\d\-Xx]{10,17}$/', $sn)) {
            $dto->isbn = $sn;
        }
        $dto->doi = self::doiFrom(self::first($fields, 'DO', 'DI'));
        $dto->url = TextNormalizer::safeUrl(self::first($fields, 'UR', 'L2'));
        $dto->abstractText = self::cleanText(self::first($fields, 'AB', 'N2'));
        $dto->authorKeywords = array_values(array_unique(array_map(fn ($k) => self::oneLine($k), self::all($fields, 'KW'))));
        $dto->language = self::first($fields, 'LA');
        $dto->publisher = self::first($fields, 'PB');
        $dto->documentType = self::first($fields, 'TY');

        return $dto;
    }

    public function getSourceName(): string { return 'RIS (outra base)'; }
    public function getSourceKey(): string { return 'generic'; }
}
