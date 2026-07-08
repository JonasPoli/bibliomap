<?php

namespace App\Controller;

use App\Entity\BibliometricProject;
use App\Service\Import\DocumentEnrichmentService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/projects/{id}/sync-geography')]
#[IsGranted('ROLE_USER')]
class ProjectSyncController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DocumentEnrichmentService $enrichmentService,
    ) {}

    #[Route('', name: 'app_project_sync_geography', methods: ['GET'])]
    public function index(int $id): Response
    {
        $project = $this->getProject($id);
        
        $cacheFile = $this->getCacheFilePath($id);
        $report = null;
        
        if (file_exists($cacheFile)) {
            $report = json_decode(file_get_contents($cacheFile), true);
        }

        // Calculate count of documents in the project
        $docCount = $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM document WHERE project_id = ?',
            [$project->getId()]
        );

        return $this->render('project/sync.html.twig', [
            'project' => $project,
            'report' => $report,
            'doc_count' => (int) $docCount,
        ]);
    }

    #[Route('/run', name: 'app_project_sync_geography_run', methods: ['POST'])]
    public function run(int $id, Request $request): Response
    {
        $project = $this->getProject($id);

        if (!$this->isCsrfTokenValid('sync_geo_' . $project->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_project_sync_geography', ['id' => $id]);
        }

        try {
            // Run matching/enrichment
            $report = $this->enrichmentService->enrichProject($project->getId());

            // Write report to cache file
            $cacheFile = $this->getCacheFilePath($id);
            $cacheDir = dirname($cacheFile);
            if (!is_dir($cacheDir)) {
                mkdir($cacheDir, 0777, true);
            }
            file_put_contents($cacheFile, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            $this->addFlash('success', 'Sincronização geográfica e de instituições concluída com sucesso!');
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Ocorreu um erro durante a sincronização: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_project_sync_geography', ['id' => $id]);
    }

    #[Route('/export-unmatched', name: 'app_project_sync_geography_export_unmatched', methods: ['GET'])]
    public function exportUnmatchedCsv(int $id, Request $request): Response
    {
        $project = $this->getProject($id);
        $cacheFile = $this->getCacheFilePath($id);
        $report = null;
        if (file_exists($cacheFile)) {
            $report = json_decode(file_get_contents($cacheFile), true);
        }

        $type = $request->query->get('type', 'institutions');

        $csv = \League\Csv\Writer::createFromString('');
        
        if ($type === 'countries') {
            $csv->insertOne(['raw_country_name', 'occurrences']);
            $unmatched = $report['unresolved_countries'] ?? [];
            foreach ($unmatched as $name => $count) {
                $csv->insertOne([$name, $count]);
            }
            $filename = 'paises_nao_encontrados_projeto_' . $id . '.csv';
        } else {
            $csv->insertOne(['raw_institution_name', 'occurrences']);
            $unmatched = $report['unresolved_institutions'] ?? [];
            foreach ($unmatched as $name => $count) {
                $csv->insertOne([$name, $count]);
            }
            $filename = 'instituicoes_nao_encontradas_projeto_' . $id . '.csv';
        }

        $response = new Response($csv->toString());
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $response;
    }

    private function getCacheFilePath(int $projectId): string
    {
        return $this->getParameter('kernel.project_dir') . '/var/geography_sync_cache/project_' . $projectId . '.json';
    }

    private function getProject(int $id): BibliometricProject
    {
        $project = $this->em->getRepository(BibliometricProject::class)->find($id);
        if (!$project || $project->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }
        return $project;
    }
}
