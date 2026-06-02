<?php

namespace App\Command;

use App\Entity\Dataset;
use App\Repository\DatasetRepository;
use App\Service\Import\DocumentImportService;
use App\Service\Import\ImporterResolver;
use App\Service\Import\WosImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import:dataset',
    description: 'Process a pending dataset import in the background',
)]
class ImportDatasetCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DatasetRepository $datasetRepo,
        private readonly DocumentImportService $importService,
        private readonly ImporterResolver $resolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('datasetId', InputArgument::REQUIRED, 'Dataset ID to process');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $datasetId = (int) $input->getArgument('datasetId');

        $dataset = $this->datasetRepo->find($datasetId);
        if (!$dataset) {
            $io->error("Dataset #{$datasetId} not found.");
            return Command::FAILURE;
        }

        $filePath = $dataset->getFilePath();
        if (!$filePath || !file_exists($filePath)) {
            $dataset->setStatus(Dataset::STATUS_ERROR);
            $dataset->setErrorMessage('Arquivo não encontrado: ' . $filePath);
            $this->em->flush();
            $io->error('File not found: ' . $filePath);
            return Command::FAILURE;
        }

        $io->title("Importando dataset #{$datasetId}: {$dataset->getName()}");
        $io->text('Arquivo: ' . $dataset->getOriginalFilename());

        // Mark as importing
        $dataset->setStatus(Dataset::STATUS_IMPORTING);
        $this->em->flush();

        // Resolve importer: try explicit source+format first, then auto-detect
        $format   = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $source   = $dataset->getSource() ?? '';
        $importer = $this->resolver->resolve($filePath, $format, $source)
            ?? new WosImporter(); // sane fallback for .txt files

        try {
            // Count total rows
            $totalRows = method_exists($importer, 'countRows')
                ? $importer->countRows($filePath)
                : 0;

            $dataset->setRecordsCount($totalRows);
            $this->em->flush();

            $io->text("Total de registros no arquivo: {$totalRows}");
            $io->text('Iniciando importação em streaming...');

            // Stream import — never loads all records into memory
            $stream = $importer->parseStream($filePath);
            $stats  = $this->importService->importAll($stream, $dataset, function (array $currentStats) use ($dataset) {
                $dataset->setImportedCount($currentStats['imported']);
                $dataset->setDuplicatedCount($currentStats['skipped']);
                $dataset->setErrorCount($currentStats['errors']);
                $this->em->flush();
            });

            // Update dataset with final results
            $dataset->setImportedCount($stats['imported']);
            $dataset->setDuplicatedCount($stats['skipped']);
            $dataset->setErrorCount($stats['errors']);
            $dataset->setStatus(Dataset::STATUS_IMPORTED);
            $dataset->setImportedAt(new \DateTimeImmutable());

            // Update project status
            $project = $dataset->getProject();
            $totalDocs = $this->em->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM document WHERE project_id = ?',
                [$project->getId()]
            );
            $project->setStatus($totalDocs > 0
                ? \App\Entity\BibliometricProject::STATUS_READY
                : \App\Entity\BibliometricProject::STATUS_IMPORTED
            );

            $this->em->flush();

            $io->success(sprintf(
                'Concluído! %d importados, %d duplicados ignorados, %d erros.',
                $stats['imported'],
                $stats['skipped'],
                $stats['errors']
            ));

            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $dataset->setStatus(Dataset::STATUS_ERROR);
            $dataset->setErrorMessage(substr($e->getMessage(), 0, 1000));
            $this->em->flush();

            $io->error('Erro durante importação: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
