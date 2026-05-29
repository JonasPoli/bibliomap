<?php

namespace App\Command;

use App\Entity\Document;
use App\Repository\BibliometricProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\Csv\Reader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:backfill:countries',
    description: 'Backfill countries JSON column from Scopus CSV files',
)]
class BackfillCountriesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BibliometricProjectRepository $projectRepo,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('projectId', InputArgument::REQUIRED, 'Project ID to backfill');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', '1024M');
        $io = new SymfonyStyle($input, $output);
        $projectId = (int) $input->getArgument('projectId');

        $project = $this->projectRepo->find($projectId);
        if (!$project) {
            $io->error("Project #{$projectId} not found.");
            return Command::FAILURE;
        }

        $datasets = $project->getDatasets();
        if ($datasets->isEmpty()) {
            $io->error("Project #{$projectId} has no datasets.");
            return Command::FAILURE;
        }

        $io->title("Backfilling countries for Project #{$projectId}: {$project->getTitle()}");

        $conn = $this->em->getConnection();

        foreach ($datasets as $dataset) {
            $filePath = $dataset->getFilePath();
            if (!$filePath || !file_exists($filePath)) {
                $io->warning("CSV file for Dataset #{$dataset->getId()} not found at {$filePath}. Skipping.");
                continue;
            }

            $io->text("Processing CSV: {$dataset->getOriginalFilename()} ({$filePath})");

            try {
                $reader = Reader::from($filePath);
                $reader->setHeaderOffset(0);

                $updated = 0;
                $batchSize = 200;
                $conn->beginTransaction();

                foreach ($reader->getRecords() as $record) {
                    // Extract Title and DOI to match DB document
                    $title = $this->get($record, 'Title');
                    $doi   = $this->normalizeDoi($this->get($record, 'DOI'));
                    $hash  = $this->computeHash($title, $doi, $record);

                    $affRaw = $this->get($record, 'Affiliations');
                    if (!$affRaw) continue;

                    $countries = $this->extractCountries($affRaw);
                    if (empty($countries)) continue;

                    $countriesJson = json_encode(array_values($countries), JSON_UNESCAPED_UNICODE);

                    // Try updating by DOI first, then by hash
                    $rowsAffected = 0;
                    if ($doi) {
                        $rowsAffected = $conn->executeStatement(
                            'UPDATE document SET countries = ? WHERE project_id = ? AND doi = ? AND countries IS NULL',
                            [$countriesJson, $projectId, $doi]
                        );
                    }

                    if ($rowsAffected === 0 && $hash) {
                        $rowsAffected = $conn->executeStatement(
                            'UPDATE document SET countries = ? WHERE project_id = ? AND hash = ? AND countries IS NULL',
                            [$countriesJson, $projectId, $hash]
                        );
                    }

                    if ($rowsAffected > 0) {
                        $updated++;
                        if ($updated % $batchSize === 0) {
                            $conn->commit();
                            $conn->beginTransaction();
                        }
                    }
                }

                $conn->commit();
                $io->success("Successfully backfilled {$updated} documents for Dataset #{$dataset->getId()}.");

            } catch (\Throwable $e) {
                if ($conn->isTransactionActive()) {
                    $conn->rollBack();
                }
                $io->error("Error processing dataset: " . $e->getMessage());
            }
        }

        return Command::SUCCESS;
    }

    private function get(array $record, string $key): ?string
    {
        foreach ($record as $k => $v) {
            if (trim($k) === $key) {
                $val = trim((string) $v);
                return $val !== '' ? $val : null;
            }
        }
        return null;
    }

    private function normalizeDoi(?string $doi): ?string
    {
        if (!$doi) return null;
        $doi = trim($doi);
        $doi = preg_replace('#^https?://doi\.org/#i', '', $doi);
        return $doi !== '' ? $doi : null;
    }

    private function computeHash(?string $title, ?string $doi, array $record): ?string
    {
        if ($doi) {
            return md5('doi:' . strtolower(trim($doi)));
        }
        $yearVal = $this->get($record, 'Year');
        $year = $yearVal && is_numeric($yearVal) ? (int)$yearVal : null;
        if ($title && $year) {
            $normalized = preg_replace('/\s+/', ' ', strtolower(trim($title)));
            return md5($normalized . ':' . $year);
        }
        return null;
    }

    private function extractCountries(string $affiliationText): array
    {
        $countries = [];
        $affiliations = explode(';', $affiliationText);
        foreach ($affiliations as $aff) {
            $parts = array_map('trim', explode(',', $aff));
            if (count($parts) > 0) {
                $last = end($parts);
                if ($last && strlen($last) > 2) {
                    $countries[] = $last;
                }
            }
        }
        return array_unique(array_filter($countries));
    }
}
