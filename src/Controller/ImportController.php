<?php

namespace App\Controller;

use App\Entity\BibliometricProject;
use App\Entity\Dataset;
use App\Repository\DocumentRepository;
use App\Service\Import\DocumentImportService;
use App\Service\Import\ImporterResolver;
use App\Service\Import\ScopusCsvImporter;
use Doctrine\ORM\EntityManagerInterface;
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
        private readonly ?Profiler $profiler = null,
    ) {}

    // ── Step 1: Upload form ───────────────────────────────────────────────────

    #[Route('', name: 'app_import_index', methods: ['GET'])]
    public function index(int $projectId): Response
    {
        $project = $this->getProject($projectId);
        return $this->render('import/index.html.twig', ['project' => $project]);
    }

    // ── Step 2: Receive file, detect format, show preview ────────────────────

    #[Route('/upload', name: 'app_import_upload', methods: ['POST'])]
    public function upload(Request $request, int $projectId): Response
    {
        $project = $this->getProject($projectId);

        /** @var UploadedFile|null $file */
        $file = $request->files->get('file');

        if (!$file || !$file->isValid()) {
            $this->addFlash('danger', 'Nenhum arquivo válido recebido.');
            return $this->redirectToRoute('app_import_index', ['projectId' => $projectId]);
        }

        $allowedExts = ['csv', 'txt', 'ris', 'bib', 'xlsx', 'json'];
        $ext = strtolower($file->getClientOriginalExtension());

        if (!in_array($ext, $allowedExts)) {
            $this->addFlash('danger', 'Formato não suportado. Use CSV, TXT, RIS, BibTeX, XLSX ou JSON.');
            return $this->redirectToRoute('app_import_index', ['projectId' => $projectId]);
        }

        if ($file->getSize() > 600 * 1024 * 1024) {
            $this->addFlash('danger', 'Arquivo muito grande. Máximo: 600MB.');
            return $this->redirectToRoute('app_import_index', ['projectId' => $projectId]);
        }

        // Capture BEFORE move() — after move the temp file is deleted
        $originalFilename = $file->getClientOriginalName();
        $fileSizeBytes    = $file->getSize();

        // Move to persistent upload dir
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

        // Auto-detect source
        $detections = $this->resolver->detectAll($fullPath);
        $detected   = $detections[0] ?? null;

        // Parse preview (first 10 rows only)
        $previewRecords = [];
        $headers        = [];
        $totalRows      = 0;

        $previewImporter = $detected ? $detected['importer'] : new ScopusCsvImporter();
        $previewResult   = $previewImporter->parse($fullPath, 10);
        $previewRecords  = $previewResult->records;
        $headers         = $previewResult->headers;
        $totalRows       = $previewResult->totalRead;

        // Store in session
        $session = $request->getSession();
        $session->set('import_file', $fullPath);
        $session->set('import_original_name', $originalFilename);
        $session->set('import_source', $detected['source'] ?? 'unknown');
        $session->set('import_format', $ext);
        $session->set('import_project', $projectId);

        return $this->render('import/preview.html.twig', [
            'project'          => $project,
            'detections'       => $detections,
            'detected'         => $detected,
            'previewRecords'   => $previewRecords,
            'headers'          => $headers,
            'totalRows'        => $totalRows,
            'originalFilename' => $originalFilename,
            'fileSize'         => $fileSizeBytes,
        ]);
    }

    // ── Step 3: Create dataset + launch background import ─────────────────────

    #[Route('/confirm', name: 'app_import_confirm', methods: ['POST'])]
    public function confirm(Request $request, int $projectId): Response
    {
        $project      = $this->getProject($projectId);
        $session      = $request->getSession();

        $filePath     = $session->get('import_file');
        $originalName = $session->get('import_original_name');
        $source       = $request->request->get('source', $session->get('import_source', 'scopus'));
        $datasetName  = trim($request->request->get('dataset_name', '')) ?: ($originalName ?? 'Importação');
        $description  = trim($request->request->get('description', '')) ?: null;
        $startRaw     = $request->request->get('search_period_start');
        $endRaw       = $request->request->get('search_period_end');

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

        try {
            if ($startRaw) $dataset->setSearchPeriodStart(new \DateTimeImmutable($startRaw));
            if ($endRaw)   $dataset->setSearchPeriodEnd(new \DateTimeImmutable($endRaw));
        } catch (\Throwable) {}

        $this->em->persist($dataset);
        $project->setStatus(\App\Entity\BibliometricProject::STATUS_IMPORTING);
        $this->em->flush();

        $session->remove('import_file');
        $session->remove('import_source');

        // Launch import as a detached background process.
        // Running as a console command avoids:
        //   - Symfony Profiler collecting 100k+ SQL queries → OOM
        //   - Monolog NormalizerFormatter buffering log data → OOM
        //   - PHP web request timeout (max_execution_time)
        $phpFinder = new \Symfony\Component\Process\PhpExecutableFinder();
        $phpBinary = $phpFinder->find(false) ?: 'php';

        $projectDir = $this->getParameter('kernel.project_dir');
        $logFile    = $projectDir . '/var/log/import_' . $dataset->getId() . '.log';

        $appEnv  = $_SERVER['APP_ENV'] ?? 'dev';
        $cmd = sprintf(
            'nohup %s -d memory_limit=2048M -d max_execution_time=0 %s/bin/console app:import:dataset %d --env=%s --no-debug >> %s 2>&1 < /dev/null &',
            escapeshellarg($phpBinary),
            escapeshellarg($projectDir),
            $dataset->getId(),
            escapeshellarg($appEnv),
            escapeshellarg($logFile)
        );

        exec($cmd);


        return $this->redirectToRoute('app_import_processing', [
            'projectId' => $projectId,
            'id'        => $dataset->getId(),
        ]);
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

    private function getProject(int $projectId): BibliometricProject
    {
        $project = $this->em->getRepository(BibliometricProject::class)->find($projectId);
        if (!$project || $project->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }
        return $project;
    }
}
