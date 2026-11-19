<?php

namespace App\Service\Import\OpenAlex;

use App\DTO\BibliographicRecordDTO;
use App\DTO\ImportResult;
use App\DTO\UnreadableRecord;
use App\Service\Import\BibliographicImporterInterface;
use App\Service\Import\PreviewNoticesInterface;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;

/**
 * JSON do OpenAlex lido em fluxo (sem carregar o arquivo inteiro): a resposta da API ({"meta":…,"results":[…]}),
 * uma lista de works ([…]) ou um work por linha (JSON Lines).
 */
class OpenAlexJsonImporter implements BibliographicImporterInterface, PreviewNoticesInterface
{
    private const API = 'api';
    private const LIST = 'list';
    private const LINES = 'lines';

    public function supports(string $format, ?string $source = null): bool
    {
        return strtolower($format) === 'json' && strtolower($source ?? '') === 'openalex';
    }

    public function detect(string $filePath): float
    {
        return $this->form($filePath) !== null ? 1.0 : 0.0;
    }

    /** Forma do arquivo, ou null se não for JSON do OpenAlex. */
    private function form(string $filePath): ?string
    {
        $h = @fopen($filePath, 'rb');
        if (!$h) {
            return null;
        }
        $head = (string) fread($h, 65536);
        fclose($h);
        $head = preg_replace('/^\xEF\xBB\xBF/', '', $head);
        if (!str_contains($head, 'openalex.org/W') && !str_contains($head, 'openalex.org\\/W')) {
            return null;
        }
        $t = ltrim($head);
        if (str_starts_with($t, '[')) {
            return self::LIST;
        }
        if (!str_starts_with($t, '{')) {
            return null;
        }
        if (preg_match('/^\{\s*"(meta|results|group_by)"/', $t) || preg_match('/"results"\s*:\s*\[/', $head)) {
            return self::API;
        }
        // Um work por linha: a primeira linha é um objeto completo com o id do work
        $first = strtok($t, "\n");
        $obj = json_decode((string) $first, true);

        return is_array($obj) && isset($obj['id']) ? self::LINES : null;
    }

    /** @return iterable<mixed> */
    private function items(string $filePath): iterable
    {
        $decoder = new ExtJsonDecoder(true);

        return match ($this->form($filePath)) {
            self::API => Items::fromFile($filePath, ['pointer' => '/results', 'decoder' => $decoder]),
            self::LIST => Items::fromFile($filePath, ['decoder' => $decoder]),
            self::LINES => (function () use ($filePath) {
                $h = fopen($filePath, 'rb');
                try {
                    while (($line = fgets($h)) !== false) {
                        if (trim($line) !== '') {
                            yield json_decode($line, true);
                        }
                    }
                } finally {
                    fclose($h);
                }
            })(),
            default => [],
        };
    }

    public function parse(string $filePath, int $limit = 0): ImportResult
    {
        $result = new ImportResult();
        $result->detectedSource = 'openalex';
        $result->detectedFormat = 'json';
        $result->headers = ['id', 'title', 'publication_year', 'doi', 'authorships'];
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
        foreach ($this->items($filePath) as $work) {
            $position++;
            if (!is_array($work)) {
                yield new UnreadableRecord($position, 'Item que não é um work do OpenAlex.');
                continue;
            }
            try {
                yield OpenAlexWorkMapper::fromApiWork($work, $position);
            } catch (\Throwable $e) {
                yield new UnreadableRecord($position, $e->getMessage(), null, is_string($work['title'] ?? null) ? $work['title'] : null);
            }
        }
    }

    public function countRows(string $filePath): int
    {
        $n = 0;
        foreach ($this->items($filePath) as $_) {
            $n++;
        }

        return $n;
    }

    public function mapToInternalRecord(array $rawRecord): BibliographicRecordDTO
    {
        return OpenAlexWorkMapper::fromApiWork($rawRecord, 0);
    }

    /** Total da busca (meta.count) quando o arquivo é uma resposta da API. */
    public function expectedTotal(string $filePath): ?int
    {
        if ($this->form($filePath) !== self::API) {
            return null;
        }
        try {
            foreach (Items::fromFile($filePath, ['pointer' => '/meta/count']) as $count) {
                return is_int($count) ? $count : null;
            }
        } catch (\Throwable) {
        }

        return null;
    }

    public function previewNotice(string $filePath): ?string
    {
        $total = $this->expectedTotal($filePath);
        if ($total === null) {
            return null;
        }
        $n = $this->countRows($filePath);
        if ($total === $n) {
            return null;
        }

        return sprintf('A busca tem %s resultados; este arquivo traz %s. Envie as outras páginas da busca para completar.',
            number_format($total, 0, ',', '.'), number_format($n, 0, ',', '.'));
    }

    public function blockingNotice(string $filePath): ?string
    {
        return null;
    }

    public function getSourceName(): string { return 'OpenAlex'; }
    public function getSourceKey(): string { return 'openalex'; }
    public function getFormatName(): string { return 'JSON'; }
}
