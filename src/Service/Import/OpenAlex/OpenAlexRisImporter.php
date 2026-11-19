<?php

namespace App\Service\Import\OpenAlex;

use App\DTO\BibliographicRecordDTO;
use App\Service\Import\AffiliationParser;
use App\Service\Import\PreviewNoticesInterface;
use App\Service\Import\Ris\AbstractRisImporter;
use App\Service\Import\Ris\RisReader;
use App\Service\Import\TextNormalizer;

/**
 * RIS exportado pela interface do OpenAlex ("EndNote (RIS)"): sem Work ID, sem citações e sem referências; as
 * marcações KW trazem os conceitos do OpenAlex (não são importados). Sem marca própria no arquivo, é reconhecido
 * pela forma dos primeiros registros. Deduplica por DOI e por título + ano.
 */
class OpenAlexRisImporter extends AbstractRisImporter implements PreviewNoticesInterface
{
    private const TYPES = ['JOUR' => 'article', 'CHAP' => 'book-chapter', 'BOOK' => 'book', 'THES' => 'dissertation',
        'DATA' => 'dataset', 'RPRT' => 'report', 'LETTER' => 'letter', 'ERRT' => 'erratum', 'GEN' => 'other'];

    public function supports(string $format, ?string $source = null): bool
    {
        return strtolower($format) === 'ris' && strtolower($source ?? '') === 'openalex';
    }

    public function detect(string $filePath): float
    {
        $n = 0;
        foreach ($this->reader->records($filePath) as $rec) {
            $f = $rec['fields'];
            foreach (['AN', 'DB', 'JF', 'JO', 'M3'] as $foreign) {
                if (isset($f[$foreign])) {
                    return 0.0;
                }
            }
            foreach ($f['UR'] ?? [] as $ur) {
                if (!str_starts_with($ur, 'https://doi.org/')) {
                    return 0.0;
                }
            }
            $da = self::first($f, 'DA');
            if ($da !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $da)) {
                return 0.0;
            }
            if (++$n >= 5) {
                break;
            }
        }
        if ($n === 0) {
            return 0.0;
        }
        // Um C1 logo depois de um AU: lido pela ordem das marcações no arquivo
        $prev = null;
        foreach (RisReader::head($filePath, 400) as $line) {
            $tag = substr($line, 0, 2);
            if ($tag === 'C1' && $prev === 'AU') {
                return 0.7;
            }
            if (RisReader::isTagLine($line)) {
                $prev = $tag;
            }
        }

        return 0.0;
    }

    protected function mapFields(array $fields, int $position): ?BibliographicRecordDTO
    {
        $dto = new BibliographicRecordDTO();
        $dto->source = 'openalex';
        $dto->title = self::cleanText(self::first($fields, 'TI', 'T1'));
        $dto->authorNames = array_values(array_filter(array_map(fn ($a) => self::oneLine($a), self::all($fields, 'AU'))));
        $dto->year = self::yearFrom(self::first($fields, 'PY', 'DA'));
        $dto->publicationDate = self::first($fields, 'DA');
        $dto->sourceTitle = self::cleanText(self::first($fields, 'T2'));
        $dto->publisher = self::first($fields, 'PB');
        $dto->issn = self::issnFrom(self::first($fields, 'SN'));
        $dto->volume = self::first($fields, 'VL');
        $dto->issue = self::first($fields, 'IS');
        $dto->pageStart = self::first($fields, 'SP');
        $dto->pageEnd = self::first($fields, 'EP');
        $dto->doi = self::doiFrom(self::first($fields, 'DO'));
        $dto->url = TextNormalizer::safeUrl(self::first($fields, 'UR'));
        $dto->language = self::first($fields, 'LA');
        $dto->abstractText = self::cleanText(self::first($fields, 'AB'));
        $ty = self::first($fields, 'TY');
        $dto->documentType = $ty !== null ? (self::TYPES[$ty] ?? strtolower($ty)) : null;

        $affs = [];
        foreach (self::all($fields, 'C1') as $c1) {
            $c1 = self::oneLine($c1);
            if ($c1 !== null && !in_array($c1, $affs, true)) {
                $affs[] = $c1;
            }
        }
        $dto->affiliations = $affs;
        if ($affs !== []) {
            $joined = implode('; ', $affs);
            $dto->countries = AffiliationParser::countries($joined);
            $dto->institutions = AffiliationParser::institutions($joined);
        }

        return $dto;
    }

    public function previewNotice(string $filePath): ?string
    {
        return 'O RIS do OpenAlex não traz citações, referências, palavras-chave nem Work ID. Prefira o CSV com a coluna Work ID.';
    }

    public function blockingNotice(string $filePath): ?string
    {
        return null;
    }

    public function getSourceName(): string { return 'OpenAlex'; }
    public function getSourceKey(): string { return 'openalex'; }
}
