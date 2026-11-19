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

    /**
     * Valores aceitos no campo "base de origem" dos formulários: as bases fixas, as cadastradas
     * (siglas) e "generic" (Outra).
     *
     * @return string[]
     */
    public function allowedSources(): array
    {
        $allowed = array_keys(\App\Config\DataSources::all());
        try {
            foreach ($this->em->getRepository(\App\Entity\AcademicDatabase::class)->findAll() as $db) {
                $allowed[] = $db->getAcronym();
            }
        } catch (\Throwable) {}
        $allowed[] = 'generic';

        return array_values(array_unique(array_filter($allowed)));
    }

    /**
     * Leitor do arquivo: primeiro o da base declarada no dataset; se ele mal reconhece o arquivo (pontuação abaixo
     * de 0,3) e outro leitor o reconhece com 0,8 ou mais, vale o detectado (a base escolhida à mão estava errada).
     * Sem base declarada, a maior pontuação acima de 0,5.
     */
    public function resolve(string $filePath, string $format, ?string $source = null): ?BibliographicImporterInterface
    {
        $src = strtolower(trim($source ?? ''));

        // 1. Base declarada (só se for uma base específica)
        if ($src !== '' && $src !== 'generic' && $src !== 'unknown') {
            foreach ($this->importers as $importer) {
                if ($importer->supports($format, $source)) {
                    if ($importer->detect($filePath) < 0.3) {
                        [$best, $bestScore] = $this->bestDetection($filePath);
                        if ($best !== null && $best !== $importer && $bestScore >= 0.8) {
                            return $best;
                        }
                    }
                    return $importer;
                }
            }
        }

        // 2. Detecção pelos leitores
        [$best, $bestScore] = $this->bestDetection($filePath);

        // 3. Colunas-assinatura das bases cadastradas (CSV)
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

    /** @return array{0: ?BibliographicImporterInterface, 1: float} */
    private function bestDetection(string $filePath): array
    {
        $best = null;
        $bestScore = 0.0;
        foreach ($this->importers as $importer) {
            $score = $importer->detect($filePath);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $importer;
            }
        }

        return [$best, $bestScore];
    }

    /**
     * Codificação declarada por um leitor que reconhece o arquivo (pontuação acima de 0,5), ou null.
     * Arquivo assim não é recusado pelo teste de UTF-8: o leitor converte ao ler.
     */
    public function declaredEncoding(string $filePath): ?string
    {
        foreach ($this->importers as $importer) {
            if ($importer instanceof DeclaresEncodingInterface && $importer->detect($filePath) > 0.5) {
                $enc = $importer->sourceEncoding($filePath);
                if ($enc !== null) {
                    return $enc;
                }
            }
        }

        return null;
    }

    /** Leitores registrados, na ordem do contêiner (para montar listas de formatos lidos). */
    public function importers(): array
    {
        return $this->importers;
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
                    'sourceKey' => $importer->getSourceKey(),
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
                                    'sourceKey' => $db->getAcronym(),
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
