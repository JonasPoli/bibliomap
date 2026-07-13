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

#[IsGranted('ROLE_USER')]
class ProjectSyncController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DocumentEnrichmentService $enrichmentService,
    ) {}

    // Legacy Redirects
    #[Route('/projects/{id}/sync-geography', name: 'app_project_sync_geography', methods: ['GET'])]
    public function legacyRedirect(int $id): Response
    {
        return $this->redirectToRoute('app_project_sync', ['id' => $id]);
    }

    #[Route('/projects/{id}/sync-geography/run', name: 'app_project_sync_geography_run', methods: ['POST'])]
    public function legacyRunRedirect(int $id): Response
    {
        return $this->redirectToRoute('app_project_sync_run', ['id' => $id]);
    }

    #[Route('/projects/{id}/sync-geography/export-unmatched', name: 'app_project_sync_geography_export_unmatched', methods: ['GET'])]
    public function legacyExportRedirect(int $id, Request $request): Response
    {
        return $this->redirectToRoute('app_project_sync_export_unmatched', [
            'id' => $id,
            'type' => $request->query->get('type')
        ]);
    }

    // New Routes
    #[Route('/projects/{id}/sync', name: 'app_project_sync', methods: ['GET'])]
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

    #[Route('/projects/{id}/sync/run', name: 'app_project_sync_run', methods: ['POST'])]
    public function run(int $id, Request $request): Response
    {
        $project = $this->getProject($id);

        if (!$this->isCsrfTokenValid('sync_data_' . $project->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_project_sync', ['id' => $id]);
        }

        try {
            // Run matching/enrichment for geography, institutions, authors and keywords
            $report = $this->enrichmentService->enrichProject($project->getId());

            // Write report to cache file
            $cacheFile = $this->getCacheFilePath($id);
            $cacheDir = dirname($cacheFile);
            if (!is_dir($cacheDir)) {
                mkdir($cacheDir, 0777, true);
            }
            file_put_contents($cacheFile, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            $this->addFlash('success', 'Sincronização do projeto concluída com sucesso!');
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Ocorreu um erro durante a sincronização: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_project_sync', ['id' => $id]);
    }

    #[Route('/projects/{id}/sync/export-unmatched', name: 'app_project_sync_export_unmatched', methods: ['GET'])]
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
        } elseif ($type === 'authors') {
            $csv->insertOne(['raw_author_name', 'occurrences']);
            $rows = $this->em->getConnection()->fetchAllAssociative('
                SELECT a.preferred_name AS name, COUNT(da.document_id) AS count
                FROM document_author da
                JOIN author_identity a ON da.author_identity_id = a.id
                JOIN document d ON da.document_id = d.id
                WHERE d.project_id = ? AND a.status = 0
                GROUP BY a.preferred_name
                ORDER BY count DESC
            ', [$project->getId()]);
            foreach ($rows as $row) {
                $csv->insertOne([$row['name'], $row['count']]);
            }
            $filename = 'autores_nao_encontrados_projeto_' . $id . '.csv';
        } elseif ($type === 'keywords') {
            $csv->insertOne(['raw_keyword_term', 'occurrences']);
            $rows = $this->em->getConnection()->fetchAllAssociative('
                SELECT k.keyword_display AS term, COUNT(dk.document_id) AS count
                FROM document_keyword dk
                JOIN keyword k ON dk.keyword_id = k.id
                JOIN document d ON dk.document_id = d.id
                WHERE d.project_id = ? AND k.status = 0
                GROUP BY k.keyword_display
                ORDER BY count DESC
            ', [$project->getId()]);
            foreach ($rows as $row) {
                $csv->insertOne([$row['term'], $row['count']]);
            }
            $filename = 'palavras_chave_nao_encontradas_projeto_' . $id . '.csv';
        } elseif ($type === 'journals') {
            $csv->insertOne(['raw_journal_title', 'issn', 'occurrences']);
            $rows = $this->em->getConnection()->fetchAllAssociative('
                SELECT source_title, issn, COUNT(id) AS count
                FROM document
                WHERE project_id = ? AND qualis_journal_id IS NULL AND source_title IS NOT NULL AND source_title != ""
                GROUP BY source_title, issn
                ORDER BY count DESC
            ', [$project->getId()]);
            foreach ($rows as $row) {
                $csv->insertOne([$row['source_title'], $row['issn'], $row['count']]);
            }
            $filename = 'revistas_nao_encontradas_projeto_' . $id . '.csv';
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
