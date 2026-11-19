<?php

namespace App\Service\Import\OpenAlex;

use App\DTO\BibliographicRecordDTO;
use App\DTO\ImportResult;
use App\DTO\UnreadableRecord;
use App\Service\Import\BibliographicImporterInterface;
use App\Service\Import\PreviewNoticesInterface;
use App\Service\Import\Ris\AbstractRisImporter;
use App\Service\Import\TextNormalizer;
use League\Csv\Reader;

/**
 * CSV exportado pela interface do OpenAlex, lido pelo nome das colunas nas três gerações conhecidas: rótulos
 * ("Work ID", "Citation count"), caminhos ("authorships.author.display_name") e nomes antigos ("author_names").
 * Valores múltiplos separados por "|"; listas por autor (Institution, Country) têm posições vazias.
 */
class OpenAlexCsvImporter implements BibliographicImporterInterface, PreviewNoticesInterface
{
    /** Campo → nomes de coluna aceitos (comparados em minúsculas). */
    private const COLUMNS = [
        'id' => ['work id', 'id'],
        'title' => ['title', 'display_name'],
        'doi' => ['doi'],
        'year' => ['year', 'publication_year'],
        'date' => ['date', 'publication_date'],
        'type' => ['type'],
        'language' => ['language'],
        'authors' => ['author', 'authorships.author.display_name', 'author_names'],
        'institutions' => ['institution', 'authorships.institutions.display_name', 'author_institution_names'],
        'countries' => ['country', 'authorships.countries'],
        'source' => ['source', 'primary_location.source.display_name', 'primary_location_display_name'],
        'issn' => ['issn', 'primary_location.source.issn_l', 'primary_location.source.issn', 'primary_location_issn_l', 'primary_location_issns'],
        'volume' => ['volume', 'biblio.volume', 'biblio_volume'],
        'issue' => ['issue', 'biblio.issue', 'biblio_issue'],
        'first_page' => ['first page', 'biblio.first_page', 'biblio_first_page'],
        'last_page' => ['last page', 'biblio.last_page', 'biblio_last_page'],
        'cited_by' => ['citation count', 'cited_by_count'],
        'oa_status' => ['open access status', 'open_access.oa_status', 'oa_status'],
        'oa' => ['open access', 'open_access.is_oa', 'is_oa'],
        'keywords' => ['keyword', 'keywords.display_name'],
        'cites' => ['cites', 'referenced_works'],
        'abstract' => ['abstract'],
        'pmid' => ['pmid', 'ids.pmid'],
    ];

    /** Corte de célula da variante "CSV (Excel)". */
    private const EXCEL_CELL_LIMIT = 30000;

    public function supports(string $format, ?string $source = null): bool
    {
        return strtolower($format) === 'csv' && strtolower($source ?? '') === 'openalex';
    }

    public function detect(string $filePath): float
    {
        $cols = $this->columnMap($filePath);
        if ($cols === null) {
            return 0.0;
        }
        $known = count(array_filter($cols));
        $headers = $this->headers($filePath) ?? [];
        $hasOpenAlexId = isset($cols['id']) && ($headers[$cols['id']] ?? '') === 'work id';
        if ($hasOpenAlexId && $known >= 4) {
            return 1.0;
        }
        // Gerações antigas: "id" com o endereço do OpenAlex na primeira linha de dados
        if (isset($cols['id']) && $known >= 4 && $this->firstValue($filePath, $cols['id']) !== null
            && str_contains((string) $this->firstValue($filePath, $cols['id']), 'openalex.org/W')) {
            return 1.0;
        }
        // Exportação com as colunas padrão (sem Work ID nem DOI): reconhecida para ser recusada com orientação
        if ((isset($cols['cited_by'], $cols['oa'], $cols['authors'], $cols['year'])
            && in_array('citation count', $headers, true)) || (in_array('cited_by_count', $headers, true) && in_array('publication_year', $headers, true))) {
            return 0.9;
        }

        return 0.0;
    }

    public function blockingNotice(string $filePath): ?string
    {
        $cols = $this->columnMap($filePath);
        if ($cols !== null && !isset($cols['id']) && !isset($cols['doi'])) {
            return 'Este CSV do OpenAlex não traz Work ID nem DOI. Exporte de novo acrescentando as colunas Work ID e DOI.';
        }

        return null;
    }

    public function previewNotice(string $filePath): ?string
    {
        $cols = $this->columnMap($filePath) ?? [];
        if (!isset($cols['volume']) && isset($cols['id'])) {
            return 'O CSV do OpenAlex não traz volume, número nem páginas. Para ter esses campos, use o JSON da API.';
        }

        return null;
    }

    /** @return ?array<string, int> campo → índice da coluna */
    private function columnMap(string $filePath): ?array
    {
        $headers = $this->headers($filePath);
        if ($headers === null) {
            return null;
        }
        $map = [];
        foreach (self::COLUMNS as $field => $names) {
            foreach ($names as $n) {
                $i = array_search($n, $headers, true);
                if ($i !== false) {
                    $map[$field] = $i;
                    break;
                }
            }
        }

        return $map;
    }

    /** @return ?list<string> cabeçalho em minúsculas, sem BOM */
    private function headers(string $filePath): ?array
    {
        try {
            $r = self::reader($filePath);
            foreach ($r->getRecords() as $row) {
                return array_map(fn ($h) => mb_strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), array_values($row));
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private function firstValue(string $filePath, int $col): ?string
    {
        $i = 0;
        foreach (self::reader($filePath)->getRecords() as $row) {
            if ($i++ === 1) {
                return isset($row[$col]) ? (string) $row[$col] : null;
            }
        }

        return null;
    }

    public function parse(string $filePath, int $limit = 0): ImportResult
    {
        $result = new ImportResult();
        $result->detectedSource = 'openalex';
        $result->detectedFormat = 'csv';
        $result->headers = $this->headers($filePath) ?? [];
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
        $cols = $this->columnMap($filePath) ?? [];
        $width = count($this->headers($filePath) ?? []);
        $position = 0;
        $first = true;
        foreach (self::reader($filePath)->getRecords() as $row) {
            if ($first) {
                $first = false;
                continue;
            }
            if ($row === [null] || $row === []) {
                continue;
            }
            $position++;
            if (count($row) !== $width) {
                yield new UnreadableRecord($position, sprintf('Linha com %d colunas; o cabeçalho tem %d.', count($row), $width));
                continue;
            }
            try {
                $cells = [];
                foreach ($cols as $field => $i) {
                    $cells[$field] = $row[$i] ?? null;
                }
                $dto = $this->mapToInternalRecord($cells);
                $dto->position = $position;
                yield $dto;
            } catch (\Throwable $e) {
                yield new UnreadableRecord($position, $e->getMessage());
            }
        }
    }

    public function countRows(string $filePath): int
    {
        $n = -1;
        foreach (self::reader($filePath)->getRecords() as $row) {
            if ($row !== [null] && $row !== []) {
                $n++;
            }
        }

        return max(0, $n);
    }

    /** @param array<string, ?string> $rawRecord células pelo nome do campo (chaves de COLUMNS) */
    public function mapToInternalRecord(array $rawRecord): BibliographicRecordDTO
    {
        $v = function (string $k) use ($rawRecord): ?string {
            $x = isset($rawRecord[$k]) ? trim((string) $rawRecord[$k]) : '';

            return $x === '' ? null : $x;
        };
        $list = function (string $k) use ($v): array {
            $out = [];
            foreach (explode('|', (string) $v($k)) as $item) {
                $item = trim($item);
                if ($item !== '' && !in_array($item, $out, true)) {
                    $out[] = $item;
                }
            }

            return $out;
        };

        $dto = new BibliographicRecordDTO();
        $dto->source = 'openalex';
        $dto->externalId = OpenAlexWorkMapper::workId($v('id'));
        $dto->strictIdentity = $dto->externalId !== null;
        $title = TextNormalizer::decodeEntities($v('title'));
        $dto->title = $title !== null ? trim(strip_tags($title)) : null;
        $dto->doi = AbstractRisImporter::doiFrom($v('doi'));
        $year = $v('year');
        $dto->year = $year !== null && preg_match('/^\d{4}$/', $year) ? (int) $year : AbstractRisImporter::yearFrom($v('date'));
        $dto->publicationDate = $v('date');
        $dto->documentType = $v('type');
        $dto->language = $v('language');
        $dto->authorNames = array_values(array_filter($list('authors'), fn ($a) => preg_match_all('/\pL/u', $a) >= 2));
        $dto->institutions = $list('institutions');
        $dto->countries = $list('countries');
        $dto->sourceTitle = $v('source');
        foreach ($list('issn') as $i) {
            if (($issn = AbstractRisImporter::issnFrom($i)) !== null) {
                $dto->issn = $issn;
                break;
            }
        }
        $dto->volume = $v('volume');
        $dto->issue = $v('issue');
        $dto->pageStart = $v('first_page');
        $dto->pageEnd = $v('last_page');
        $cited = $v('cited_by');
        $dto->citedBy = $cited !== null && ctype_digit($cited) ? (int) $cited : null;
        $status = $v('oa_status');
        if ($status !== null) {
            $dto->openAccessStatus = strtolower($status) === 'closed' ? 'Closed' : ucfirst(strtolower($status));
        } elseif (($oa = $v('oa')) !== null) {
            $dto->openAccessStatus = in_array(strtolower($oa), ['open access', 'true', 'is oa'], true) ? 'Open Access' : 'Closed';
        }
        $dto->indexedKeywords = $list('keywords');
        $cites = $list('cites');
        // Célula cortada no limite da variante "CSV (Excel)": o último identificador pode estar pela metade
        if (mb_strlen((string) $v('cites')) >= self::EXCEL_CELL_LIMIT) {
            array_pop($cites);
        }
        $refs = [];
        foreach ($cites as $c) {
            if (($id = OpenAlexWorkMapper::workId($c)) !== null) {
                $refs[] = $id;
            }
        }
        $dto->references = $refs;
        $abstract = TextNormalizer::decodeEntities($v('abstract'));
        $dto->abstractText = $abstract !== null ? trim(strip_tags($abstract)) : null;
        $pmid = $v('pmid');
        $dto->pmid = $pmid !== null && preg_match('/(\d+)\s*$/', $pmid, $m) ? $m[1] : null;
        $dto->url = $dto->doi !== null ? 'https://doi.org/' . $dto->doi : ($dto->externalId !== null ? 'https://openalex.org/' . $dto->externalId : null);

        return $dto;
    }

    private static function reader(string $filePath): Reader
    {
        $reader = Reader::from($filePath);
        $reader->setEscape('');

        return $reader;
    }

    public function getSourceName(): string { return 'OpenAlex'; }
    public function getSourceKey(): string { return 'openalex'; }
    public function getFormatName(): string { return 'CSV'; }
}
