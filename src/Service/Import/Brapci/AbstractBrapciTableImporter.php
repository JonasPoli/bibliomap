<?php

namespace App\Service\Import\Brapci;

use App\DTO\BibliographicRecordDTO;
use App\DTO\ImportResult;
use App\DTO\UnreadableRecord;
use App\Service\Import\BibliographicImporterInterface;

/**
 * Base do CSV e do XLS da BRAPCI: os dois têm as mesmas 11 colunas; muda só como o arquivo vira linhas.
 * Antes de entregar o primeiro registro, uma passada curta acha os DOIs usados por mais de um ID do arquivo
 * (a BRAPCI atribui DOI de obra citada a registros), que não são gravados.
 */
abstract class AbstractBrapciTableImporter implements BibliographicImporterInterface
{
    /**
     * Linhas de dados do arquivo: [posição a partir de 1, células] ou [posição, null, motivo] quando a linha
     * não tem o número de colunas esperado.
     *
     * @return \Generator<int, array{0: int, 1: ?array<string, ?string>, 2?: string}>
     */
    abstract protected function rows(string $filePath): \Generator;

    public function getSourceName(): string { return 'BRAPCI'; }
    public function getSourceKey(): string { return 'brapci'; }

    public function mapToInternalRecord(array $rawRecord): BibliographicRecordDTO
    {
        return BrapciRecordMapper::map($rawRecord, 0);
    }

    public function parse(string $filePath, int $limit = 0): ImportResult
    {
        $result = new ImportResult();
        $result->detectedSource = 'brapci';
        $result->detectedFormat = strtolower($this->getFormatName());
        $result->headers = BrapciRecordMapper::COLUMNS;
        foreach ($this->parseStream($filePath) as $item) {
            $result->totalRead++;
            if ($item instanceof BibliographicRecordDTO) {
                $result->records[] = $item;
                $result->totalValid++;
            } else {
                $result->totalErrors++;
                $result->errors[] = 'Linha ' . $item->position . ': ' . $item->message;
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
        $shared = $this->sharedDois($filePath);
        foreach ($this->rows($filePath) as $r) {
            [$position, $cells] = $r;
            if ($cells === null) {
                yield new UnreadableRecord($position, $r[2] ?? 'Linha com número de colunas diferente do cabeçalho.');
                continue;
            }
            try {
                yield BrapciRecordMapper::map($cells, $position, $shared);
            } catch (\Throwable $e) {
                yield new UnreadableRecord($position, $e->getMessage(), BrapciRecordMapper::value($cells['ID'] ?? null), BrapciRecordMapper::text($cells['TITLE'] ?? null));
            }
        }
    }

    public function countRows(string $filePath): int
    {
        $n = 0;
        foreach ($this->rows($filePath) as $_) {
            $n++;
        }

        return $n;
    }

    /**
     * Registros do arquivo por tipo, com os rótulos dos cartões da cesta (Artigos, Trabalhos de eventos...).
     *
     * @return array<string, int>
     */
    public function countByType(string $filePath): array
    {
        $counts = [];
        foreach ($this->rows($filePath) as $r) {
            if ($r[1] === null) {
                continue;
            }
            $class = BrapciRecordMapper::value($r[1]['CLASS'] ?? null) ?? '(sem tipo)';
            $counts[$class] = ($counts[$class] ?? 0) + 1;
        }
        $out = [];
        foreach (BrapciRecordMapper::TYPE_LABELS as $class => $label) {
            if (isset($counts[$class])) {
                $out[$label] = $counts[$class];
                unset($counts[$class]);
            }
        }

        return $out + $counts;
    }

    /** @return array<string, true> DOIs normalizados que aparecem em mais de um ID */
    public function sharedDois(string $filePath): array
    {
        $byDoi = [];
        foreach ($this->rows($filePath) as $r) {
            if ($r[1] === null) {
                continue;
            }
            $doi = BrapciRecordMapper::doiFromUrl(BrapciRecordMapper::value($r[1]['URL'] ?? null));
            if ($doi !== null) {
                $byDoi[$doi][(string) ($r[1]['ID'] ?? '')] = true;
            }
        }
        $shared = [];
        foreach ($byDoi as $doi => $ids) {
            if (count($ids) > 1) {
                $shared[$doi] = true;
            }
        }

        return $shared;
    }

    /**
     * Cabeçalho → nomes das colunas, sem a coluna vazia que a vírgula final cria.
     *
     * @param list<string> $cells
     * @return list<string>
     */
    protected static function headerNames(array $cells): array
    {
        $names = array_map(fn ($c) => trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $c)), $cells);
        while ($names !== [] && end($names) === '') {
            array_pop($names);
        }

        return $names;
    }

    /**
     * @param list<string> $names
     * @param list<?string> $cells
     * @return ?array<string, ?string> null se o número de células não bate (aceita a célula vazia final)
     */
    protected static function combine(array $names, array $cells): ?array
    {
        if (count($cells) === count($names) + 1 && trim((string) end($cells)) === '') {
            array_pop($cells);
        }
        if (count($cells) !== count($names)) {
            return null;
        }

        return array_combine($names, $cells);
    }
}
