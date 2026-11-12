<?php

namespace App\Controller;

use App\Entity\BibliometricProject;
use App\Entity\Dataset;
use App\Repository\DocumentRepository;
use App\Service\Import\DocumentImportService;
use App\Service\Import\ImporterResolver;
use App\Service\Import\ImportQueueInspector;
use App\Service\Import\ScopusCsvImporter;
use App\Service\Import\TextNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/projects/{projectId}/import')]
#[IsGranted('ROLE_USER')]
class ImportController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ImporterResolver $resolver,
        private readonly DocumentImportService $importService,
        private readonly DocumentRepository $documentRepo,
        private readonly LoggerInterface $logger,
        private readonly ImportQueueInspector $queueInspector,
        private readonly ?Profiler $profiler = null,
    ) {}

    // ── Step 1: Upload form ───────────────────────────────────────────────────

    #[Route('', name: 'app_import_index', methods: ['GET'])]
    public function index(int $projectId): Response
    {
        $project = $this->getProject($projectId);
        $databases = $this->em->getRepository(\App\Entity\AcademicDatabase::class)->findBy([], ['name' => 'ASC']);
        return $this->render('import/index.html.twig', [
            'project' => $project,
            'databases' => $databases,
        ]);
    }

    // ── Step 2: Receive file, detect format, show preview ────────────────────

    #[Route('/upload', name: 'app_import_upload', methods: ['POST'])]
    public function upload(Request $request, int $projectId): Response
    {
        $project = $this->getProject($projectId);

        if (!$this->isCsrfTokenValid('import_upload_' . $projectId, (string) $request->request->get('_token'))) {
            // Arquivo maior que post_max_size chega sem campos nem arquivos: o token some junto
            $this->addFlash('danger', $request->files->count() === 0
                ? 'Nenhum arquivo recebido. Ele pode ser maior que o limite do servidor. Tente de novo.'
                : 'Token CSRF inválido. Recarregue a página e envie o arquivo de novo.');
            return $this->redirectToRoute('app_import_index', ['projectId' => $projectId]);
        }

        $filesBag = $request->files->get('file');
        $uploadedFiles = is_array($filesBag) ? $filesBag : ($filesBag ? [$filesBag] : []);

        // Filter valid files
        $uploadedFiles = array_filter($uploadedFiles, function($file) {
            return $file instanceof UploadedFile && $file->isValid();
        });

        if (empty($uploadedFiles)) {
            $this->addFlash('danger', 'Nenhum arquivo válido recebido.');
            return $this->redirectToRoute('app_import_index', ['projectId' => $projectId]);
        }

        // Só extensões com leitor (ImporterResolver); RIS, BibTeX, XLSX e JSON ainda não têm
        $allowedExts = ['csv', 'txt', 'nbib'];
        $unsupportedMsg = 'Formato não suportado: .%s. O BiblioMap lê Scopus/Lens.org/PubMed (CSV), Web of Science/PubMed (TXT) e PubMed (NBIB). RIS, BibTeX, XLSX e JSON ainda não têm leitor.';

        // If exactly one file, preserve the single file preview & confirm step
        if (count($uploadedFiles) === 1) {
            $file = reset($uploadedFiles);
            $ext = strtolower($file->getClientOriginalExtension());

            if (!in_array($ext, $allowedExts)) {
                $this->addFlash('danger', sprintf($unsupportedMsg, $ext));
                return $this->redirectToRoute('app_import_index', ['projectId' => $projectId]);
            }

            if ($file->getSize() > 600 * 1024 * 1024) {
                $this->addFlash('danger', 'Arquivo muito grande. Máximo: 600MB.');
                return $this->redirectToRoute('app_import_index', ['projectId' => $projectId]);
            }

            $originalFilename = $file->getClientOriginalName();
            $fileSizeBytes    = $file->getSize();

            $uploadDir = $this->getParameter('kernel.project_dir') . '/var/uploads/' . $project->getId();
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $safeFilename = uniqid('import_') . '.' . $ext;
            $file->move($uploadDir, $safeFilename);
            $fullPath = $uploadDir . '/' . $safeFilename;

            if ($fileSizeBytes === false || $fileSizeBytes === 0) {
                $fileSizeBytes = filesize($fullPath) ?: 0;
            }

            if (!TextNormalizer::isUtf8File($fullPath)) {
                @unlink($fullPath);
                $this->addFlash('danger', 'O arquivo não está em UTF-8 (parece Windows-1252, Latin-1 ou UTF-16): os acentos seriam gravados errados. Reexporte em UTF-8 e envie de novo.');
                return $this->redirectToRoute('app_import_index', ['projectId' => $projectId]);
            }

            $detections = $this->resolver->detectAll($fullPath);
            $detected   = $detections[0] ?? null;

            $previewRecords = [];
            $headers        = [];
            $totalRows      = null;

            // Sem leitor detectado não há prévia: mostrar o arquivo lido pelo leitor do Scopus
            // só exibiria registros errados. O usuário ainda pode escolher a base à mão.
            if ($detected) {
                $previewImporter = $detected['importer'];
                $previewResult   = $previewImporter->parse($fullPath, 10);
                $previewRecords  = $previewResult->records;
                $headers         = $previewResult->headers;
                // totalRead para em 10 (limite da prévia): conta o arquivo inteiro, uma única vez
                $totalRows       = method_exists($previewImporter, 'countRows')
                    ? $previewImporter->countRows($fullPath)
                    : null;
            }

            $session = $request->getSession();
            // Upload anterior que nunca foi confirmado: apaga o arquivo órfão
            $previous = $session->get('import_file');
            if ($previous && $previous !== $fullPath && $session->get('import_project') === $projectId) {
                $this->deleteUploadedFile((string) $previous, $project);
            }
            $this->purgeOrphanUploads($project);

            $session->set('import_file', $fullPath);
            $session->set('import_original_name', $originalFilename);
            $session->set('import_source', $detected['source'] ?? 'unknown');
            $session->set('import_format', $ext);
            $session->set('import_project', $projectId);
            $session->set('import_total_rows', $totalRows);

            return $this->render('import/preview.html.twig', [
                'project'          => $project,
                'detections'       => $detections,
                'detected'         => $detected,
                'previewRecords'   => $previewRecords,
                'headers'          => $headers,
                'totalRows'        => $totalRows,
                'originalFilename' => $originalFilename,
                'fileSize'         => $fileSizeBytes,
                'unrecognized'     => $detected === null,
            ]);
        }

        // Multiple files enqueued directly
        $createdDatasets = [];
        foreach ($uploadedFiles as $file) {
            $ext = strtolower($file->getClientOriginalExtension());
            if (!in_array($ext, $allowedExts)) {
                $this->addFlash('warning', sprintf('Arquivo "%s" ignorado. ' . $unsupportedMsg, $file->getClientOriginalName(), $ext));
                continue;
            }

            if ($file->getSize() > 600 * 1024 * 1024) {
                $this->addFlash('warning', sprintf('Arquivo "%s" ignorado: tamanho excede o limite de 600MB.', $file->getClientOriginalName()));
                continue;
            }

            $originalFilename = $file->getClientOriginalName();
            
            $uploadDir = $this->getParameter('kernel.project_dir') . '/var/uploads/' . $project->getId();
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $safeFilename = uniqid('import_') . '.' . $ext;
            $file->move($uploadDir, $safeFilename);
            $fullPath = $uploadDir . '/' . $safeFilename;

            if (!TextNormalizer::isUtf8File($fullPath)) {
                @unlink($fullPath);
                $this->addFlash('warning', sprintf('Arquivo "%s" ignorado: não está em UTF-8 (reexporte em UTF-8).', $originalFilename));
                continue;
            }

            $detections = $this->resolver->detectAll($fullPath);
            $detected   = $detections[0] ?? null;
            $source     = $detected['source'] ?? 'unknown';

            $dataset = new Dataset();
            $dataset->setProject($project);
            $dataset->setName(pathinfo($originalFilename, PATHINFO_FILENAME));
            $dataset->setSource($source);
            $dataset->setOriginalFilename($originalFilename);
            $dataset->setFilePath($fullPath);
            $dataset->setFileFormat($ext);
            $dataset->setStatus(Dataset::STATUS_PENDING);

            $this->em->persist($dataset);
            $createdDatasets[] = $dataset;
        }

        if (count($createdDatasets) > 0) {
            $previousStatus = $project->getStatus();
            $project->setStatus(BibliometricProject::STATUS_IMPORTING);
            $this->em->flush();

            // Um único processo importa os arquivos em sequência e enriquece o projeto
            // uma vez no fim. Um processo por arquivo em paralelo sobrecarregava o MySQL
            // (conexões perdidas, importações parciais e contagens incoerentes).
            $ids = array_map(fn (Dataset $d) => $d->getId(), $createdDatasets);
            try {
                $this->launchImport($ids);
            } catch (\Throwable $e) {
                // Nada está rodando: o projeto não pode ficar "Importando"; os datasets seguem pendentes
                $project->setStatus($previousStatus);
                $this->em->flush();
                $this->logger->error('Importação: falha ao iniciar o processo em segundo plano: ' . $e->getMessage(), ['exception' => $e]);
                $this->addFlash('warning', 'Os datasets foram criados, mas a execução em segundo plano não pôde ser iniciada. Tente de novo pela lista de importações ou fale com o administrador.');
            }

            $this->addFlash('success', sprintf('%d arquivo(s) enviado(s) com sucesso e enfileirados para processamento!', count($createdDatasets)));
            return $this->redirectToRoute('app_projects_show', ['id' => $projectId]);
        }

        return $this->redirectToRoute('app_import_index', ['projectId' => $projectId]);
    }

    // ── Step 3: Create dataset + launch background import ─────────────────────

    #[Route('/confirm', name: 'app_import_confirm', methods: ['POST'])]
    public function confirm(Request $request, int $projectId): Response
    {
        $project      = $this->getProject($projectId);
        $session      = $request->getSession();

        if (!$this->isCsrfTokenValid('import_confirm_' . $projectId, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido. Envie o arquivo de novo.');
            return $this->redirectToRoute('app_import_index', ['projectId' => $projectId]);
        }

        $filePath     = $session->get('import_file');
        $originalName = $session->get('import_original_name');
        // Só valores da lista de bases (os cartões da tela); qualquer outro vira "Outra"
        $source       = (string) $request->request->get('source', $session->get('import_source', 'scopus'));
        if (!in_array($source, $this->resolver->allowedSources(), true)) {
            $source = 'generic';
        }
        $datasetName  = trim($request->request->get('dataset_name', '')) ?: ($originalName ?? 'Importação');
        $description  = trim($request->request->get('description', '')) ?: null;
        $startRaw     = $request->request->get('search_period_start');
        $endRaw       = $request->request->get('search_period_end');

        // O arquivo da sessão tem de ser o enviado para este projeto (nunca um caminho de outro lugar)
        $uploadsRoot = realpath($this->uploadDir($project));
        $realFile    = $filePath ? realpath((string) $filePath) : false;
        if ($session->get('import_project') !== $projectId
            || $uploadsRoot === false || $realFile === false
            || !str_starts_with($realFile, $uploadsRoot . DIRECTORY_SEPARATOR)) {
            $filePath = null;
        }
        if (!$filePath || !file_exists($filePath)) {
            $this->addFlash('danger', 'Sessão expirada. Faça o upload novamente.');
            return $this->redirectToRoute('app_import_index', ['projectId' => $projectId]);
        }

        // Create Dataset record (status pending — the command will set it to importing)
        $dataset = new Dataset();
        $dataset->setProject($project);
        $dataset->setName($datasetName);
        $dataset->setDescription($description);
        $dataset->setSource($source);
        $dataset->setOriginalFilename($originalName ?? 'unknown');
        $dataset->setFilePath($filePath);
        $dataset->setFileFormat(pathinfo($filePath, PATHINFO_EXTENSION));
        $dataset->setStatus(Dataset::STATUS_PENDING);
        // A prévia já contou as linhas: o comando não conta de novo
        $totalRows = $session->get('import_total_rows');
        if (is_int($totalRows) && $totalRows > 0) {
            $dataset->setRecordsCount($totalRows);
        }

        try {
            if ($startRaw) $dataset->setSearchPeriodStart(new \DateTimeImmutable($startRaw));
            if ($endRaw)   $dataset->setSearchPeriodEnd(new \DateTimeImmutable($endRaw));
        } catch (\Throwable) {}

        $this->em->persist($dataset);
        $previousStatus = $project->getStatus();
        $project->setStatus(\App\Entity\BibliometricProject::STATUS_IMPORTING);
        $this->em->flush();

        $session->remove('import_file');
        $session->remove('import_source');
        $session->remove('import_total_rows');

        try {
            $this->launchImport([$dataset->getId()]);
        } catch (\Throwable $e) {
            // Nada está rodando: o projeto não pode ficar "Importando"
            $project->setStatus($previousStatus);
            $this->em->flush();
            $this->logger->error('Importação: falha ao iniciar o processo em segundo plano: ' . $e->getMessage(), ['exception' => $e]);
            $this->addFlash('warning', 'O registro foi criado com status pendente, mas a execução em segundo plano não pôde ser iniciada automaticamente. Para corrigir isso no RunCloud, edite as "disable_functions" nas configurações de PHP do seu servidor e garanta que TODAS as seguintes funções estejam habilitadas (removidas da lista): proc_open, proc_close, proc_get_status, proc_terminate, exec.');
        }

        return $this->redirectToRoute('app_import_processing', [
            'projectId' => $projectId,
            'id'        => $dataset->getId(),
        ]);
    }

    // ── Retomar a fila interrompida ───────────────────────────────────────────

    /**
     * Importação que morreu no meio (processo morto, reinício do servidor) deixa datasets
     * `importing` ou `pending` sem ninguém trabalhando neles. Só retoma se a trava de cada
     * dataset e a trava geral de importação estão livres, ou seja, nada está rodando.
     */
    #[Route('/resume', name: 'app_import_resume', methods: ['POST'])]
    public function resume(Request $request, int $projectId): Response
    {
        $project = $this->getProject($projectId);

        if (!$this->isCsrfTokenValid('import_resume_' . $projectId, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido. Recarregue a página e tente de novo.');
            return $this->redirectToRoute('app_datasets_index', ['projectId' => $projectId]);
        }

        $ids = $this->queueInspector->interruptedDatasetIds($projectId);
        if (!$ids) {
            $this->addFlash('info', 'Não há importação interrompida neste projeto (ou ainda há uma em andamento).');
            return $this->redirectToRoute('app_datasets_index', ['projectId' => $projectId]);
        }

        $previousStatus = $project->getStatus();
        $project->setStatus(BibliometricProject::STATUS_IMPORTING);
        $this->em->flush();

        try {
            $this->launchImport($ids);
        } catch (\Throwable $e) {
            $this->logger->error('Importação: falha ao retomar a fila: ' . $e->getMessage(), ['exception' => $e]);
            $project->setStatus($previousStatus);
            $this->em->flush();
            $this->addFlash('warning', 'Não foi possível iniciar o processo em segundo plano. Fale com o administrador.');
            return $this->redirectToRoute('app_datasets_index', ['projectId' => $projectId]);
        }

        $this->addFlash('success', sprintf('Importação retomada para %d arquivo(s). O que já tinha sido gravado não é duplicado.', count($ids)));
        return $this->redirectToRoute('app_datasets_index', ['projectId' => $projectId]);
    }

    // ── Step 4: Animated status page while background import runs ────────────

    #[Route('/processing/{id}', name: 'app_import_processing', methods: ['GET'])]
    public function processing(int $projectId, int $id): Response
    {
        $project = $this->getProject($projectId);
        $dataset = $this->em->getRepository(Dataset::class)->find($id);

        if (!$dataset || $dataset->getProject() !== $project) {
            throw $this->createNotFoundException();
        }

        return $this->render('import/processing.html.twig', [
            'project' => $project,
            'dataset' => $dataset,
        ]);
    }

    // ── JSON polling endpoint ─────────────────────────────────────────────────

    #[Route('/status/{id}', name: 'app_import_status', methods: ['GET'])]
    public function status(int $projectId, int $id): Response
    {
        $project = $this->getProject($projectId);

        // Bypass entity identity map to always get fresh data from DB
        $this->em->clear();
        $dataset = $this->em->getRepository(Dataset::class)->find($id);

        if (!$dataset || $dataset->getProject()->getId() !== $project->getId()) {
            return $this->json(['error' => 'Not found'], 404);
        }

        return $this->json([
            'status'        => $dataset->getStatus(),
            'statusLabel'   => $dataset->getStatusLabel(),
            'statusColor'   => $dataset->getStatusColor(),
            'recordsCount'  => $dataset->getRecordsCount(),
            'importedCount' => $dataset->getImportedCount(),
            'skippedCount'  => $dataset->getDuplicatedCount(),
            'errorCount'    => $dataset->getErrorCount(),
            'successRate'   => $dataset->getSuccessRate(),
            'importedAt'    => $dataset->getImportedAt()?->format('d/m/Y H:i:s'),
            'errorMessage'  => $dataset->getErrorMessage(),
            'showUrl'       => $this->generateUrl('app_datasets_show', [
                'projectId' => $projectId,
                'id'        => $id,
            ]),
        ]);
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function uploadDir(BibliometricProject $project): string
    {
        return $this->getParameter('kernel.project_dir') . '/var/uploads/' . $project->getId();
    }

    /** Apaga um arquivo enviado e ainda não confirmado, só se estiver na pasta do projeto e sem dataset. */
    private function deleteUploadedFile(string $path, BibliometricProject $project): void
    {
        $dir = realpath($this->uploadDir($project));
        $real = realpath($path);
        if ($dir === false || $real === false || !str_starts_with($real, $dir . DIRECTORY_SEPARATOR)) {
            return;
        }
        $used = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM dataset WHERE file_path = ?', [$path]);
        if ($used === 0) {
            @unlink($real);
        }
    }

    /** Uploads de prévia que ninguém confirmou há mais de um dia (import_*, sem dataset). */
    private function purgeOrphanUploads(BibliometricProject $project): void
    {
        $dir = $this->uploadDir($project);
        if (!is_dir($dir)) {
            return;
        }
        $limit = time() - 86400;
        foreach (glob($dir . '/import_*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < $limit) {
                $this->deleteUploadedFile($file, $project);
            }
        }
    }

    private function getProject(int $projectId): BibliometricProject
    {
        $project = $this->em->getRepository(BibliometricProject::class)->find($projectId);
        if (!$project) {
            throw $this->createNotFoundException();
        }
        // Importar é escrita: só o dono (ProjectVoter 'edit')
        $this->denyAccessUnlessGranted('edit', $project);
        return $project;
    }

    /**
     * Dispara app:import:dataset em segundo plano para os datasets, em sequência.
     * Sem --skip-enrichment: o comando enriquece o projeto uma vez ao final.
     *
     * @param int[] $datasetIds
     */
    private function launchImport(array $datasetIds): void
    {
        $projectDir = $this->getParameter('kernel.project_dir');
        $phpBinary  = $this->resolvePhpBinary($projectDir);
        $appEnv     = $_SERVER['APP_ENV'] ?? 'dev';
        $logFile    = $projectDir . '/var/log/import_' . $datasetIds[0] . '.log';

        // 768M: pico medido de 124 MB (kernel + importação + enriquecimento) num projeto de cerca de 52 mil
        // documentos; o resto é folga para os caches de autores e palavras-chave da produção (300 mil linhas).
        $cmd = sprintf(
            'nohup %s -d memory_limit=768M -d max_execution_time=0 %s/bin/console app:import:dataset %s --env=%s --no-debug >> %s 2>&1 < /dev/null &',
            escapeshellarg($phpBinary),
            escapeshellarg($projectDir),
            implode(' ', array_map('intval', $datasetIds)),
            escapeshellarg($appEnv),
            escapeshellarg($logFile)
        );

        $process = \Symfony\Component\Process\Process::fromShellCommandline($cmd);
        $process->start();
    }

    private function resolvePhpBinary(string $projectDir): string
    {
        $phpBinary = null;

        // 1. RunCloud prediction
        if (str_contains($projectDir, '/home/runcloud') && defined('PHP_VERSION')) {
            $parts = explode('.', PHP_VERSION);
            if (count($parts) >= 2) {
                $versionSuffix = $parts[0] . $parts[1];
                $phpBinary = '/RunCloud/Packages/php' . $versionSuffix . 'rc/bin/php';
            }
        }

        // 2. Symfony standard executable finder
        if (!$phpBinary) {
            $phpFinder = new \Symfony\Component\Process\PhpExecutableFinder();
            $phpBinary = $phpFinder->find(false);
        }

        // 3. Fallback path prediction
        if (!$phpBinary && defined('PHP_VERSION')) {
            $parts = explode('.', PHP_VERSION);
            if (count($parts) >= 2) {
                $versionSuffix = $parts[0] . $parts[1];
                $possiblePaths = [
                    '/usr/bin/php' . $versionSuffix,
                    '/usr/local/bin/php' . $versionSuffix,
                    '/usr/bin/php' . $parts[0] . '.' . $parts[1],
                    '/usr/local/bin/php' . $parts[0] . '.' . $parts[1],
                ];
                foreach ($possiblePaths as $path) {
                    if (@is_executable($path)) {
                        $phpBinary = $path;
                        break;
                    }
                }
            }
        }

        return $phpBinary ?: 'php';
    }
}
