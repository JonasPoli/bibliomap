<?php

namespace App\Service\Import;

class ImporterResolver
{
    /** @var BibliographicImporterInterface[] */
    private array $importers;

    public function __construct(
        iterable $importers,
        private readonly \Doctrine\ORM\EntityManagerInterface $em
    ) {
        $this->importers = iterator_to_array($importers);
    }

    public function resolve(string $filePath, string $format, ?string $source = null): ?BibliographicImporterInterface
    {
        $src = strtolower(trim($source ?? ''));

        // 1. Try by explicit source/format (only if source is set and specific)
        if ($src !== '' && $src !== 'generic' && $src !== 'unknown') {
            foreach ($this->importers as $importer) {
                if ($importer->supports($format, $source)) {
                    return $importer;
                }
            }
        }

        // 2. Try auto-detect using hardcoded importer detection
        $best = null;
        $bestScore = 0.0;
        foreach ($this->importers as $importer) {
            $score = $importer->detect($filePath);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $importer;
            }
        }

        // 3. Try database-based signature columns detection
        if ($bestScore <= 0.5) {
            $dbBest = $this->detectFromDatabase($filePath);
            if ($dbBest !== null && $dbBest['score'] > $bestScore) {
                $bestScore = $dbBest['score'];
                foreach ($this->importers as $importer) {
                    if ($importer->supports($format, $dbBest['source'])) {
                        $best = $importer;
                        break;
                    }
                }
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

        // Database-based auto-detection check for CSV
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if ($ext === 'csv') {
            try {
                $reader = \League\Csv\Reader::from($filePath);
                $reader->setHeaderOffset(0);
                $headers = array_map('trim', $reader->getHeader());

                $dbSources = $this->em->getRepository(\App\Entity\AcademicDatabase::class)->findAll();
                foreach ($dbSources as $db) {
                    $formats = $db->getFileFormats() ?? [];
                    if (!in_array('csv', $formats)) {
                        continue;
                    }

                    $sigCols = $db->getSignatureColumns() ?? [];
                    if (empty($sigCols)) {
                        continue;
                    }

                    $found = 0;
                    foreach ($sigCols as $sig) {
                        if (in_array($sig, $headers)) {
                            $found++;
                        }
                    }
                    $score = $found / count($sigCols);
                    if ($score > 0) {
                        $alreadyExists = false;
                        foreach ($results as &$res) {
                            if (strtolower($res['source']) === strtolower($db->getName()) || strtolower($res['source']) === strtolower($db->getAcronym())) {
                                if ($score > $res['score']) {
                                    $res['score'] = $score;
                                }
                                $alreadyExists = true;
                                break;
                            }
                        }
                        if (!$alreadyExists) {
                            $matchedImporter = null;
                            foreach ($this->importers as $importer) {
                                if ($importer->supports('csv', $db->getAcronym())) {
                                    $matchedImporter = $importer;
                                    break;
                                }
                            }
                            if ($matchedImporter) {
                                $results[] = [
                                    'importer' => $matchedImporter,
                                    'source' => $db->getName(),
                                    'format' => 'CSV',
                                    'score' => $score,
                                ];
                            }
                        }
                    }
                }
            } catch (\Throwable) {}
        }

        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);
        return $results;
    }

    private function detectFromDatabase(string $filePath): ?array
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            return null;
        }

        try {
            $reader = \League\Csv\Reader::from($filePath);
            $reader->setHeaderOffset(0);
            $headers = array_map('trim', $reader->getHeader());

            $dbSources = $this->em->getRepository(\App\Entity\AcademicDatabase::class)->findAll();
            $bestSource = null;
            $bestScore = 0.0;

            foreach ($dbSources as $db) {
                $formats = $db->getFileFormats() ?? [];
                if (!in_array('csv', $formats)) {
                    continue;
                }

                $sigCols = $db->getSignatureColumns() ?? [];
                if (empty($sigCols)) {
                    continue;
                }

                $found = 0;
                foreach ($sigCols as $sig) {
                    if (in_array($sig, $headers)) {
                        $found++;
                    }
                }
                $score = $found / count($sigCols);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestSource = $db->getAcronym();
                }
            }

            if ($bestScore > 0.0) {
                return [
                    'source' => $bestSource,
                    'score' => $bestScore,
                ];
            }
        } catch (\Throwable) {}

        return null;
    }
}
