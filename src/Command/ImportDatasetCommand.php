<?php

namespace App\Command;

use App\Entity\Dataset;
use App\Repository\DatasetRepository;
use App\Service\Import\DocumentEnrichmentService;
use App\Service\Import\DocumentImportService;
use App\Service\Import\ImporterResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use Symfony\Component\Console\Input\InputOption;

#[AsCommand(
    name: 'app:import:dataset',
    description: 'Process pending dataset imports in the background (one after another)',
)]
class ImportDatasetCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DatasetRepository $datasetRepo,
        private readonly DocumentImportService $importService,
        private readonly ImporterResolver $resolver,
        private readonly DocumentEnrichmentService $enrichmentService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('datasetId', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'Dataset ID(s) to process, in sequence');
        $this->addOption('skip-enrichment', null, InputOption::VALUE_NONE, 'Skip post-import enrichment');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $skipEnrichment = (bool) $input->getOption('skip-enrichment');

        // Arquivos do envio múltiplo são processados em sequência no mesmo processo:
        // vários processos em paralelo derrubaram o MySQL ("server has gone away").
        $ok = true;
        $projectsToEnrich = [];
        foreach ((array) $input->getArgument('datasetId') as $rawId) {
            $result = $this->importDataset((int) $rawId, $io);
            if ($result === null) {
                $ok = false;
                continue;
            }
            if ($result['imported'] > 0) {
                $projectsToEnrich[$result['projectId']] = true;
            }
        }

        // Enriquecimento (países, instituições, Qualis) reprocessa o projeto inteiro:
        // roda uma vez no fim, e não por arquivo.
        if (!$skipEnrichment) {
            foreach (array_keys($projectsToEnrich) as $projectId) {
                $this->enrichProject($projectId, $io);
            }
        }

        return $ok ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @return array{projectId: int, imported: int}|null  null em caso de falha
     */
    private function importDataset(int $datasetId, SymfonyStyle $io): ?array
    {
        $this->em->clear();
        $conn = $this->em->getConnection();

        // Trava por dataset: uma segunda execução simultânea do mesmo dataset
        // duplicaria contagens e registros de "duplicados".
        $lockName = 'bibliomap_import_dataset_' . $datasetId;
        if ((int) $conn->fetchOne('SELECT GET_LOCK(?, 0)', [$lockName]) !== 1) {
            $io->error("Dataset #{$datasetId} já está sendo importado por outro processo.");
            return null;
        }

        try {
            return $this->doImportDataset($datasetId, $io);
        } finally {
            try {
                $conn->fetchOne('SELECT RELEASE_LOCK(?)', [$lockName]);
            } catch (\Throwable) {}
        }
    }

    private function doImportDataset(int $datasetId, SymfonyStyle $io): ?array
    {
        $dataset = $this->datasetRepo->find($datasetId);
        if (!$dataset) {
            $io->error("Dataset #{$datasetId} not found.");
            return null;
        }

        $filePath = $dataset->getFilePath();
        if (!$filePath || !file_exists($filePath)) {
            $dataset->setStatus(Dataset::STATUS_ERROR);
            $dataset->setErrorMessage('Arquivo não encontrado: ' . $filePath);
            $this->em->flush();
            $io->error('File not found: ' . $filePath);
            return null;
        }

        $io->title("Importando dataset #{$datasetId}: {$dataset->getName()}");
        $io->text('Arquivo: ' . $dataset->getOriginalFilename());

        // Mark as importing
        $dataset->setStatus(Dataset::STATUS_IMPORTING);
        $dataset->setErrorMessage(null);
        $this->em->flush();

        // Resolve importer: try explicit source+format first, then auto-detect
        $format   = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $source   = $dataset->getSource() ?? '';
        $importer = $this->resolver->resolve($filePath, $format, $source);

        if ($importer === null) {
            $dataset->setStatus(Dataset::STATUS_ERROR);
            $dataset->setErrorMessage(
                'Formato não reconhecido. O BiblioMap lê Scopus CSV, Web of Science TXT (Plain text), '
                . 'Lens.org CSV e PubMed CSV/NBIB. Reexporte o arquivo num desses formatos.'
            );
            $this->em->flush();
            $io->error('No importer for this file.');
            return null;
        }

        try {
            // Reexecução (ex.: após queda do banco): os duplicados da tentativa
            // anterior são registrados de novo nesta execução.
            $this->em->getConnection()->executeStatement('DELETE FROM dataset_skip WHERE dataset_id = ?', [$datasetId]);

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
            }, true);

            // Update dataset with final results
            $dataset->setImportedCount($stats['imported']);
            $dataset->setDuplicatedCount($stats['skipped']);
            $dataset->setErrorCount($stats['errors']);
            $dataset->setImportedAt(new \DateTimeImmutable());

            $read = $stats['imported'] + $stats['skipped'] + $stats['errors'];
            if ($stats['imported'] + $stats['skipped'] === 0) {
                // Nada aproveitável: não deixa a importação parecer bem-sucedida com 0 documentos
                $dataset->setStatus(Dataset::STATUS_ERROR);
                $dataset->setErrorMessage($read === 0
                    ? 'Nenhum registro foi lido do arquivo. Confira se ele foi exportado no formato indicado para a base escolhida.'
                    : sprintf('Nenhum dos %d registros lidos pôde ser importado.', $read));
            } else {
                $dataset->setStatus(Dataset::STATUS_IMPORTED);
            }

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

            return ['projectId' => (int) $project->getId(), 'imported' => $stats['imported']];

        } catch (\Throwable $e) {
            try {
                $dataset->setStatus(Dataset::STATUS_ERROR);
                $dataset->setErrorMessage(substr($e->getMessage(), 0, 1000));
                $this->em->flush();
            } catch (\Throwable) {}

            $io->error('Erro durante importação: ' . $e->getMessage());
            return null;
        }
    }

    private function enrichProject(int $projectId, SymfonyStyle $io): void
    {
        $conn = $this->em->getConnection();
        // Uma sincronização por projeto de cada vez (ela apaga e recria os vínculos)
        $lockName = 'bibliomap_enrich_project_' . $projectId;
        if ((int) $conn->fetchOne('SELECT GET_LOCK(?, 600)', [$lockName]) !== 1) {
            $io->warning("Enriquecimento do projeto #{$projectId} não executado: outra sincronização em andamento.");
            return;
        }

        try {
            $io->text("Enriquecendo projeto #{$projectId} (países, instituições, Qualis)...");
            $this->enrichmentService->enrichProject($projectId);
        } catch (\Throwable $e) {
            $io->warning('Falha no enriquecimento pós-importação: ' . $e->getMessage());
        } finally {
            try {
                $conn->fetchOne('SELECT RELEASE_LOCK(?)', [$lockName]);
            } catch (\Throwable) {}
        }
    }
}
