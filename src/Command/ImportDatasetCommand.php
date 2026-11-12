<?php

namespace App\Command;

use App\Entity\Dataset;
use App\Repository\DatasetRepository;
use App\Service\Import\DocumentEnrichmentService;
use App\Service\Import\DocumentImportService;
use App\Service\Import\ImporterResolver;
use App\Service\Import\TextNormalizer;
use Doctrine\DBAL\Connection;
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

    /** Espera máxima pela vez na fila de importações (segundos). */
    private const QUEUE_WAIT_SECONDS = 6 * 3600;

    /** Projeto do dataset em andamento: o `finally` acerta o status dele em qualquer desfecho. */
    private ?int $currentProjectId = null;

    /**
     * @return array{projectId: int, imported: int}|null  null em caso de falha
     */
    private function importDataset(int $datasetId, SymfonyStyle $io): ?array
    {
        $this->currentProjectId = null;
        $conn = $this->em->getConnection();
        $lockName = 'bibliomap_import_dataset_' . $datasetId;
        $queueLock = 'bibliomap_import';
        $hasQueueLock = false;
        $hasDatasetLock = false;

        try {
            $this->em->clear();
            // A conexão pode ter caído durante o dataset anterior: as travas (da sessão do MySQL)
            // são pegas de novo a cada dataset, já na conexão que vai importar.
            $this->ensureConnection($conn);

            // Uma importação por vez no servidor: as demais esperam aqui (fila natural),
            // com o dataset ainda pendente.
            if ((int) $conn->fetchOne('SELECT GET_LOCK(?, ?)', [$queueLock, self::QUEUE_WAIT_SECONDS]) !== 1) {
                $io->error("Dataset #{$datasetId}: outra importação ocupou o servidor por mais de " . (self::QUEUE_WAIT_SECONDS / 3600) . ' h; ele continua pendente.');
                return null;
            }
            $hasQueueLock = true;

            // Trava por dataset: uma segunda execução simultânea do mesmo dataset
            // duplicaria contagens e registros de "duplicados".
            if ((int) $conn->fetchOne('SELECT GET_LOCK(?, 0)', [$lockName]) !== 1) {
                $io->error("Dataset #{$datasetId} já está sendo importado por outro processo.");
                return null;
            }
            $hasDatasetLock = true;

            return $this->doImportDataset($datasetId, $io);
        } catch (\Throwable $e) {
            $io->error("Dataset #{$datasetId}: erro antes de importar: " . $e->getMessage());
            return null;
        } finally {
            // Sem isto, um dataset que falha deixava o projeto em "Importando" para sempre
            if ($this->currentProjectId !== null) {
                $this->refreshProjectStatus($this->currentProjectId);
            }
            foreach ([$hasDatasetLock ? $lockName : null, $hasQueueLock ? $queueLock : null] as $name) {
                if ($name === null) {
                    continue;
                }
                try {
                    $conn->fetchOne('SELECT RELEASE_LOCK(?)', [$name]);
                } catch (\Throwable) {}
            }
        }
    }

    /** Fecha a conexão se ela não responde: o DBAL abre outra na próxima consulta. */
    private function ensureConnection(Connection $conn): void
    {
        try {
            $conn->fetchOne('SELECT 1');
        } catch (\Throwable) {
            try {
                $conn->close();
            } catch (\Throwable) {}
        }
    }

    /** Projeto pronto se tem documentos; senão, apenas importado. Via DBAL: não depende do EntityManager. */
    private function refreshProjectStatus(int $projectId): void
    {
        $conn = $this->em->getConnection();
        try {
            $this->ensureConnection($conn);
            $total = (int) $conn->fetchOne('SELECT COUNT(*) FROM document WHERE project_id = ?', [$projectId]);
            $conn->executeStatement(
                'UPDATE bibliometric_project SET status = ? WHERE id = ? AND status IN (?, ?, ?, ?, ?)',
                [
                    $total > 0 ? \App\Entity\BibliometricProject::STATUS_READY : \App\Entity\BibliometricProject::STATUS_IMPORTED,
                    $projectId,
                    // só estados de importação; um projeto já normalizando ou concluído não é tocado
                    \App\Entity\BibliometricProject::STATUS_IMPORTING,
                    \App\Entity\BibliometricProject::STATUS_DRAFT,
                    \App\Entity\BibliometricProject::STATUS_AWAITING_IMPORT,
                    \App\Entity\BibliometricProject::STATUS_IMPORTED,
                    \App\Entity\BibliometricProject::STATUS_READY,
                ]
            );
        } catch (\Throwable) {
            // Sem banco agora: o status é corrigido na próxima importação ou pela retomada da fila
        }
    }

    private function doImportDataset(int $datasetId, SymfonyStyle $io): ?array
    {
        $dataset = $this->datasetRepo->find($datasetId);
        if (!$dataset) {
            $io->error("Dataset #{$datasetId} not found.");
            return null;
        }

        $this->currentProjectId = (int) $dataset->getProject()->getId();

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

        if (!TextNormalizer::isUtf8File($filePath)) {
            $dataset->setStatus(Dataset::STATUS_ERROR);
            $dataset->setErrorMessage(
                'O arquivo não está em UTF-8 (parece Windows-1252, Latin-1 ou UTF-16): os acentos seriam gravados errados. '
                . 'Reexporte em UTF-8 ou abra e salve o arquivo como UTF-8 antes de enviar.'
            );
            $this->em->flush();
            $io->error('Arquivo fora de UTF-8.');
            return null;
        }

        try {
            // Reexecução (ex.: após queda do banco): os duplicados da tentativa
            // anterior são registrados de novo nesta execução.
            $this->em->getConnection()->executeStatement('DELETE FROM dataset_skip WHERE dataset_id = ?', [$datasetId]);

            // O envio já contou as linhas na prévia: só conta de novo se o valor não veio
            $totalRows = $dataset->getRecordsCount();
            if ($totalRows <= 0) {
                $totalRows = method_exists($importer, 'countRows')
                    ? $importer->countRows($filePath)
                    : 0;
            }

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

            $this->em->flush();

            $io->success(sprintf(
                'Concluído! %d importados, %d duplicados ignorados, %d erros.',
                $stats['imported'],
                $stats['skipped'],
                $stats['errors']
            ));

            return ['projectId' => (int) $dataset->getProject()->getId(), 'imported' => $stats['imported']];

        } catch (\Throwable $e) {
            // Conexão perdida ("server has gone away"): fecha para o DBAL reabrir, senão o erro
            // abaixo também falharia e o dataset ficaria "importando" para sempre.
            $conn = $this->em->getConnection();
            try {
                $conn->close();
            } catch (\Throwable) {}
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
        $this->ensureConnection($conn);
        // Uma sincronização por projeto de cada vez (ela apaga e recria os vínculos)
        $lockName = 'bibliomap_enrich_project_' . $projectId;
        if ((int) $conn->fetchOne('SELECT GET_LOCK(?, 600)', [$lockName]) !== 1) {
            $io->warning("Enriquecimento do projeto #{$projectId} não executado: outra sincronização em andamento.");
            return;
        }

        try {
            $io->text("Enriquecendo projeto #{$projectId} (países, instituições, Qualis)...");
            // O relatório estatístico não é usado aqui: pula as agregações
            $this->enrichmentService->enrichProject($projectId, false);
        } catch (\Throwable $e) {
            $io->warning('Falha no enriquecimento pós-importação: ' . $e->getMessage());
        } finally {
            try {
                $conn->fetchOne('SELECT RELEASE_LOCK(?)', [$lockName]);
            } catch (\Throwable) {}
        }
    }
}
