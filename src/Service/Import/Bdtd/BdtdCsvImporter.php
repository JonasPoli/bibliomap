<?php

namespace App\Service\Import\Bdtd;

use App\DTO\BibliographicRecordDTO;
use App\DTO\ImportResult;
use App\DTO\UnreadableRecord;
use App\Service\Import\BibliographicImporterInterface;
use App\Service\Import\TextNormalizer;
use League\Csv\Reader;

/**
 * CSV do botão "Exportar" da BDTD (vem dentro de um ZIP): 25 colunas em português, sem identificador do
 * registro, "Não informado pela instituição" nos campos vazios e "||" entre valores múltiplos.
 */
class BdtdCsvImporter implements BibliographicImporterInterface
{
    use BdtdRecordBuilder;

    public const SIGNATURE = ['Autor(a)', 'Orientadores', 'Título', 'Ano de defesa', 'Instituição de defesa',
        'Sigla da instituição de defesa', 'Tipo de documento', 'Link de acesso'];

    public function supports(string $format, ?string $source = null): bool
    {
        return strtolower($format) === 'csv' && strtolower($source ?? '') === 'bdtd';
    }

    public function detect(string $filePath): float
    {
        try {
            $reader = self::reader($filePath);
            $headers = array_map('trim', $reader->getHeader());

            return count(array_intersect(self::SIGNATURE, $headers)) / count(self::SIGNATURE);
        } catch (\Throwable) {
            return 0.0;
        }
    }

    public function parse(string $filePath, int $limit = 0): ImportResult
    {
        $result = new ImportResult();
        $result->detectedSource = 'bdtd';
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
        $this->resetKeys();
        $reader = self::reader($filePath);
        $position = 0;
        foreach ($reader->getRecords() as $record) {
            $position++;
            try {
                yield $this->mapToInternalRecord(TextNormalizer::trimKeys($record) + ['__position' => $position]);
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

    /**
     * Sem caractere de escape: o CSV padrão (e o da BDTD) não escapa com barra invertida. Com o escape padrão da
     * biblioteca, um título terminado em \" engolia as colunas seguintes do registro (visto no arquivo real).
     */
    private static function reader(string $filePath): Reader
    {
        $reader = Reader::from($filePath);
        $reader->setEscape('');
        $reader->setHeaderOffset(0);

        return $reader;
    }

    public function mapToInternalRecord(array $rawRecord): BibliographicRecordDTO
    {
        $g = fn (string $k): ?string => isset($rawRecord[$k]) ? (string) $rawRecord[$k] : null;
        $subjects = array_merge(BdtdFieldCleaner::list($g('Assuntos em português')), BdtdFieldCleaner::list($g('Assuntos em inglês')));

        return $this->buildRecord([
            'author' => $g('Autor(a)'),
            'title' => $g('Título'),
            'year' => $g('Ano de defesa'),
            'link' => $g('Link de acesso'),
            'type' => $g('Tipo de documento'),
            'language' => $g('Idioma'),
            'abstract' => BdtdFieldCleaner::value($g('Resumo em Português')) ?? $g('Resumo'),
            'institution' => $g('Instituição de defesa'),
            'country' => $g('País da instituição de defesa'),
            'subjects' => $subjects,
            'access' => $g('Tipos de acesso'),
        ], (int) ($rawRecord['__position'] ?? 0));
    }

    public function getSourceName(): string { return 'BDTD'; }
    public function getSourceKey(): string { return 'bdtd'; }
    public function getFormatName(): string { return 'CSV'; }
}
