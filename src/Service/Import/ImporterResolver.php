<?php

namespace App\Service\Import;

class ImporterResolver
{
    /** @var BibliographicImporterInterface[] */
    private array $importers;

    public function __construct(iterable $importers)
    {
        $this->importers = iterator_to_array($importers);
    }

    public function resolve(string $filePath, string $format, ?string $source = null): ?BibliographicImporterInterface
    {
        // 1. Try by explicit source/format
        foreach ($this->importers as $importer) {
            if ($importer->supports($format, $source)) {
                return $importer;
            }
        }

        // 2. Try auto-detect
        $best = null;
        $bestScore = 0.0;
        foreach ($this->importers as $importer) {
            $score = $importer->detect($filePath);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $importer;
            }
        }

        return $bestScore > 0.5 ? $best : null;
    }

    public function detectAll(string $filePath): array
    {
        $results = [];
        foreach ($this->importers as $importer) {
            $score = $importer->detect($filePath);
            if ($score > 0) {
                $results[] = [
                    'importer' => $importer,
                    'source' => $importer->getSourceName(),
                    'format' => $importer->getFormatName(),
                    'score' => $score,
                ];
            }
        }
        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);
        return $results;
    }
}
