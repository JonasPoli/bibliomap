<?php

namespace App\Service\Import\Ris;

use App\DTO\BibliographicRecordDTO;
use App\DTO\ImportResult;
use App\DTO\UnreadableRecord;
use App\Service\Import\BibliographicImporterInterface;
use App\Service\Import\TextNormalizer;

/**
 * Base dos leitores de RIS: a leitura (RisReader), a prévia, a contagem e os auxiliares de campo. Cada base
 * implementa a detecção do seu dialeto e o mapeamento das marcações.
 */
abstract class AbstractRisImporter implements BibliographicImporterInterface
{
    protected RisReader $reader;

    public function __construct()
    {
        $this->reader = new RisReader();
    }

    /**
     * Marcações de um registro (cada uma com a lista de valores) → DTO.
     * Devolve null (ou lança) quando o registro não pode ser aproveitado; ele vira erro com a posição.
     *
     * @param array<string, list<string>> $fields
     */
    abstract protected function mapFields(array $fields, int $position): ?BibliographicRecordDTO;

    public function getFormatName(): string
    {
        return 'RIS';
    }

    public function mapToInternalRecord(array $rawRecord): BibliographicRecordDTO
    {
        $dto = $this->mapFields($rawRecord['fields'] ?? $rawRecord, (int) ($rawRecord['position'] ?? 0));
        if ($dto === null) {
            throw new \RuntimeException('Registro RIS sem os campos mínimos.');
        }

        return $dto;
    }

    public function parse(string $filePath, int $limit = 0): ImportResult
    {
        $result = new ImportResult();
        $result->detectedSource = $this->getSourceKey();
        $result->detectedFormat = 'ris';
        $result->headers = ['TY', 'TI', 'AU', 'PY', 'DO'];
        foreach ($this->parseStream($filePath) as $item) {
            $result->totalRead++;
            if ($item instanceof BibliographicRecordDTO) {
                $result->records[] = $item;
                $result->totalValid++;
            } else {
                $result->totalErrors++;
                $result->errors[] = $item instanceof UnreadableRecord ? 'Registro ' . $item->position . ': ' . $item->message : 'Registro ilegível';
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
        foreach ($this->reader->records($filePath) as $rec) {
            $fields = $rec['fields'];
            try {
                $dto = $this->mapFields($fields, $rec['position']);
            } catch (\Throwable $e) {
                $dto = null;
                $msg = $e->getMessage();
            }
            if ($dto === null) {
                yield new UnreadableRecord($rec['position'], $msg ?? 'Registro RIS sem os campos mínimos.', null, self::first($fields, 'TI', 'T1'));
                unset($msg);
                continue;
            }
            $dto->position = $rec['position'];
            yield $dto;
        }
    }

    public function countRows(string $filePath): int
    {
        return $this->reader->count($filePath);
    }

    // ── Auxiliares ───────────────────────────────────────────────────────────

    /** Primeiro valor não vazio entre as marcações, na ordem dada. */
    protected static function first(array $fields, string ...$tags): ?string
    {
        foreach ($tags as $tag) {
            foreach ($fields[$tag] ?? [] as $v) {
                $v = trim((string) $v);
                if ($v !== '') {
                    return $v;
                }
            }
        }

        return null;
    }

    /** Todos os valores não vazios das marcações, na ordem. @return list<string> */
    protected static function all(array $fields, string ...$tags): array
    {
        $out = [];
        foreach ($tags as $tag) {
            foreach ($fields[$tag] ?? [] as $v) {
                $v = trim((string) $v);
                if ($v !== '') {
                    $out[] = $v;
                }
            }
        }

        return $out;
    }

    /** Texto de várias linhas numa linha só. */
    protected static function oneLine(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $v = trim(preg_replace('/\s+/u', ' ', $v));

        return $v === '' ? null : $v;
    }

    /** DOI sem prefixo de endereço, em minúsculas; null se não tiver a forma 10.NNNN/... */
    public static function doiFrom(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $v = trim($v);
        $v = preg_replace('#^(https?://)?(dx\.)?doi\.org/#i', '', $v);
        $v = preg_replace('/^doi:\s*/i', '', $v);
        $v = strtolower(rtrim($v, " .;,"));

        return preg_match('#^10\.\d{4,9}/\S+$#', $v) ? $v : null;
    }

    /** Ano: os quatro primeiros dígitos (aceita 2019/09/30/, 2014///Mar/Apr, 2020-04-28). */
    public static function yearFrom(?string $v): ?int
    {
        if ($v !== null && preg_match('/(1[5-9]\d{2}|20\d{2}|21\d{2})/', $v, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /** ISSN no formato NNNN-NNNN (aceita sem hífen); null se não for ISSN. */
    public static function issnFrom(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        foreach (preg_split('/[;,|\s]+/', $v) as $part) {
            if (preg_match('/^(\d{4})-?(\d{3}[\dXx])$/', trim($part), $m)) {
                return $m[1] . '-' . strtoupper($m[2]);
            }
        }

        return null;
    }

    protected static function cleanText(?string $v): ?string
    {
        $v = TextNormalizer::decodeEntities($v);

        return $v === null ? null : self::oneLine(strip_tags($v));
    }
}
