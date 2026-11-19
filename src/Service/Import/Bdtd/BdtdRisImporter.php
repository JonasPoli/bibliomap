<?php

namespace App\Service\Import\Bdtd;

use App\DTO\BibliographicRecordDTO;
use App\Service\Import\PreviewNoticesInterface;
use App\Service\Import\Ris\AbstractRisImporter;
use App\Service\Import\Ris\RisReader;

/**
 * RIS do botão "Exportar" da BDTD: TY é masterThesis ou doctoralThesis; só AU, PY, TI, LA, AB e UR (DI e JO
 * vazios). Não traz instituição nem assuntos.
 */
class BdtdRisImporter extends AbstractRisImporter implements PreviewNoticesInterface
{
    use BdtdRecordBuilder;

    public function supports(string $format, ?string $source = null): bool
    {
        return strtolower($format) === 'ris' && strtolower($source ?? '') === 'bdtd';
    }

    public function detect(string $filePath): float
    {
        $ty = 0;
        foreach (RisReader::head($filePath, 200) as $line) {
            if (str_starts_with($line, 'TY  -')) {
                if (!preg_match('/^TY  - (masterThesis|doctoralThesis)\s*$/', $line)) {
                    return 0.0;
                }
                $ty++;
            }
        }

        return $ty > 0 ? 1.0 : 0.0;
    }

    public function parseStream(string $filePath): \Generator
    {
        $this->resetKeys();
        yield from parent::parseStream($filePath);
    }

    protected function mapFields(array $fields, int $position): ?BibliographicRecordDTO
    {
        return $this->buildRecord([
            'author' => implode('||', $fields['AU'] ?? []),
            'title' => self::first($fields, 'TI'),
            'year' => self::first($fields, 'PY'),
            'link' => self::first($fields, 'UR'),
            'type' => self::first($fields, 'TY'),
            'language' => implode('||', $fields['LA'] ?? []),
            'abstract' => self::first($fields, 'AB'),
        ], $position);
    }

    public function previewNotice(string $filePath): ?string
    {
        return 'O RIS da BDTD não traz instituição nem assuntos. Prefira o CSV.';
    }

    public function blockingNotice(string $filePath): ?string
    {
        return null;
    }

    public function getSourceName(): string { return 'BDTD'; }
    public function getSourceKey(): string { return 'bdtd'; }
}
