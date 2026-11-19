<?php

namespace App\Service\Import\Ebsco;

use App\DTO\BibliographicRecordDTO;
use App\DTO\ImportResult;
use App\DTO\UnreadableRecord;
use App\Service\Import\BibliographicImporterInterface;
use App\Service\Import\PreviewNoticesInterface;
use App\Service\Import\Ris\AbstractRisImporter;
use App\Service\Import\TextNormalizer;
use League\Csv\Reader;

/**
 * CSV exportado pela EBSCO (research.ebsco.com), usado para a LISTA (Library, Information Science & Technology
 * Abstracts). 39 colunas fixas, UTF-8 com BOM, valores múltiplos separados por " ; ". O arquivo traz registros
 * de qualquer base da EBSCO que a busca alcançou: todos são importados e a prévia avisa os que não são da LISTA.
 */
class EbscoCsvImporter implements BibliographicImporterInterface, PreviewNoticesInterface
{
    public const SIGNATURE = ['title', 'publicationDate', 'authors', 'plink', 'longDbName', 'shortDbName', 'accessionNumber', 'source'];

    /** Siglas da LISTA na EBSCO (com e sem texto completo). */
    public const LISTA_CODES = ['lih', 'lxh', 'lls'];

    public function supports(string $format, ?string $source = null): bool
    {
        return strtolower($format) === 'csv' && in_array(strtolower($source ?? ''), ['lista', 'ebsco'], true);
    }

    public function detect(string $filePath): float
    {
        try {
            $headers = array_map('trim', self::reader($filePath)->getHeader());

            return count(array_intersect(self::SIGNATURE, $headers)) / count(self::SIGNATURE);
        } catch (\Throwable) {
            return 0.0;
        }
    }

    public function parse(string $filePath, int $limit = 0): ImportResult
    {
        $result = new ImportResult();
        $result->detectedSource = 'lista';
        $result->detectedFormat = 'csv';
        $result->headers = self::SIGNATURE;
        foreach ($this->parseStream($filePath) as $item) {
            $result->totalRead++;
            if ($item instanceof BibliographicRecordDTO) {
                $result->records[] = $item;
                $result->totalValid++;
            } else {
                $result->totalErrors++;
            }
            if ($limit > 0 && $result->totalRead >= $limit) {
                break;
            }
        }

        return $result;
    }

    /** @return \Generator<BibliographicRecordDTO|UnreadableRecord> */
    public function parseStream(string $filePath): \Generator
    {
        $position = 0;
        foreach (self::reader($filePath)->getRecords() as $record) {
            $position++;
            try {
                $dto = $this->mapToInternalRecord(TextNormalizer::trimKeys($record));
                $dto->position = $position;
                yield $dto;
            } catch (\Throwable $e) {
                yield new UnreadableRecord($position, $e->getMessage());
            }
        }
    }

    public function countRows(string $filePath): int
    {
        try {
            return count(self::reader($filePath));
        } catch (\Throwable) {
            return 0;
        }
    }

    public function mapToInternalRecord(array $rawRecord): BibliographicRecordDTO
    {
        $g = function (string $k) use ($rawRecord): ?string {
            $v = isset($rawRecord[$k]) ? trim((string) $rawRecord[$k]) : '';

            return $v === '' ? null : $v;
        };
        $list = fn (?string $v): array => $v === null ? [] : array_values(array_unique(array_filter(array_map('trim', explode(';', $v)), fn ($x) => $x !== '')));

        $dto = new BibliographicRecordDTO();
        $dto->source = 'lista';

        $db = $g('shortDbName');
        $an = $g('accessionNumber');
        $plink = $g('plink');
        if ($an !== null) {
            $dto->externalId = ($db !== null ? strtolower($db) . ':' : '') . $an;
        } elseif ($plink !== null && preg_match('#([0-9a-f-]{36})\s*$#i', $plink, $m)) {
            $dto->externalId = 'plink:' . strtolower($m[1]);
        }
        $dto->strictIdentity = $dto->externalId !== null;

        $dto->title = self::title(TextNormalizer::decodeEntities($g('title')));

        $date = $g('publicationDate');
        if ($date !== null && preg_match('/^(\d{4})(\d{2})(\d{2})$/', $date, $m)) {
            $dto->year = (int) $m[1];
            $dto->publicationDate = $m[1] . '-' . $m[2] . '-' . $m[3];
        } else {
            $dto->year = AbstractRisImporter::yearFrom($date ?? $g('coverDate'));
        }

        $dto->authorNames = $list($g('authors'));
        $dto->sourceTitle = TextNormalizer::decodeEntities($g('source'));
        $dto->publisher = $g('publisher');
        $dto->volume = $g('volume');
        $dto->issue = $g('issue');
        $dto->pageStart = $g('pageStart');
        $dto->pageEnd = $g('pageEnd');
        $dto->issn = AbstractRisImporter::issnFrom($g('issn'));
        $dto->isbn = $list($g('isbn'))[0] ?? null;
        $dto->language = self::language($g('language'));
        $abstract = TextNormalizer::decodeEntities($g('abstract'));
        $dto->abstractText = $abstract !== null ? trim(strip_tags($abstract)) : null;
        $dto->documentType = $list($g('docType'))[0] ?? $list($g('pubType'))[0] ?? $g('isiType');
        $dto->doi = AbstractRisImporter::doiFrom($g('doi'));
        $pmid = $g('pmid');
        $dto->pmid = $pmid !== null && ctype_digit($pmid) ? $pmid : null;
        $dto->indexedKeywords = $list($g('subjects'));
        $dto->authorKeywords = $list($g('keywords'));
        $dto->url = TextNormalizer::safeUrl($plink);

        return $dto;
    }

    /** Tira o ponto final que a EBSCO acrescenta ao título (mantém reticências e outros sinais). */
    public static function title(?string $t): ?string
    {
        if ($t === null) {
            return null;
        }
        $t = rtrim($t);
        if (str_ends_with($t, '.') && !str_ends_with($t, '..')) {
            $t = rtrim(substr($t, 0, -1));
        }

        return $t === '' ? null : $t;
    }

    public static function language(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $first = trim(explode(';', $v)[0]);

        return ['portuguese' => 'por', 'english' => 'eng', 'spanish' => 'spa', 'french' => 'fre'][strtolower($first)] ?? $first;
    }

    public function previewNotice(string $filePath): ?string
    {
        $total = 0;
        $others = [];
        try {
            foreach (self::reader($filePath)->getRecords() as $r) {
                $total++;
                $code = strtolower(trim((string) ($r['shortDbName'] ?? '')));
                if (!in_array($code, self::LISTA_CODES, true)) {
                    $name = trim((string) ($r['longDbName'] ?? '')) ?: ($code ?: 'base não informada');
                    $others[$name] = ($others[$name] ?? 0) + 1;
                }
            }
        } catch (\Throwable) {
            return null;
        }
        if ($others === []) {
            return null;
        }
        arsort($others);
        $parts = [];
        foreach ($others as $name => $n) {
            $parts[] = $name . ' (' . $n . ')';
        }

        return sprintf('%d dos %d registros não são da LISTA: %s. Todos serão importados; para ficar só com a LISTA, limite a busca a ela na EBSCO.',
            array_sum($others), $total, implode(', ', $parts));
    }

    public function blockingNotice(string $filePath): ?string
    {
        return null;
    }

    private static function reader(string $filePath): Reader
    {
        $reader = Reader::from($filePath);
        $reader->setEscape('');
        $reader->setHeaderOffset(0);

        return $reader;
    }

    public function getSourceName(): string { return 'LISTA (EBSCO)'; }
    public function getSourceKey(): string { return 'lista'; }
    public function getFormatName(): string { return 'CSV'; }
}
