<?php

namespace App\Controller;

use App\Entity\BibliometricProject;
use App\Service\Analytics\IndicatorService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Doctrine\ORM\EntityManagerInterface;

#[Route('/projects/{id}/dashboard')]
#[IsGranted('ROLE_USER')]
class DashboardController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IndicatorService $indicators,
    ) {}

    #[Route('', name: 'app_dashboard_index', methods: ['GET'])]
    public function index(int $id): Response
    {
        $project = $this->getProject($id);
        $summary = $this->indicators->summary($project->getId());

        return $this->render('dashboard/index.html.twig', [
            'project' => $project,
            'summary' => $summary,
        ]);
    }

    // ── JSON widget endpoints — called by fetch() in the browser ─────────────

    #[Route('/data/annual-production', name: 'app_dashboard_annual_production', methods: ['GET'])]
    public function annualProduction(int $id): Response
    {
        $project = $this->getProject($id);
        return $this->json($this->indicators->annualProduction($project->getId()));
    }

    #[Route('/data/citations-per-year', name: 'app_dashboard_citations_per_year', methods: ['GET'])]
    public function citationsPerYear(int $id): Response
    {
        $project = $this->getProject($id);
        return $this->json($this->indicators->citationsPerYear($project->getId()));
    }

    #[Route('/data/top-authors', name: 'app_dashboard_top_authors', methods: ['GET'])]
    public function topAuthors(int $id, Request $request): Response
    {
        $project = $this->getProject($id);
        $limit = min((int)($request->query->get('limit', 20)), 100);
        return $this->json($this->indicators->topAuthors($project->getId(), $limit));
    }

    #[Route('/data/top-sources', name: 'app_dashboard_top_sources', methods: ['GET'])]
    public function topSources(int $id, Request $request): Response
    {
        $project = $this->getProject($id);
        $limit = min((int)($request->query->get('limit', 20)), 100);
        return $this->json($this->indicators->topSources($project->getId(), $limit));
    }

    #[Route('/data/top-keywords', name: 'app_dashboard_top_keywords', methods: ['GET'])]
    public function topKeywords(int $id, Request $request): Response
    {
        $project = $this->getProject($id);
        $type  = in_array($request->query->get('type'), ['author', 'indexed']) ? $request->query->get('type') : 'author';
        $limit = min((int)($request->query->get('limit', 50)), 200);
        return $this->json($this->indicators->topKeywords($project->getId(), $type, $limit));
    }

    #[Route('/data/document-types', name: 'app_dashboard_document_types', methods: ['GET'])]
    public function documentTypes(int $id): Response
    {
        $project = $this->getProject($id);
        return $this->json($this->indicators->documentTypeDistribution($project->getId()));
    }

    #[Route('/data/open-access', name: 'app_dashboard_open_access', methods: ['GET'])]
    public function openAccess(int $id): Response
    {
        $project = $this->getProject($id);
        return $this->json($this->indicators->openAccessStats($project->getId()));
    }

    #[Route('/data/collaboration-index', name: 'app_dashboard_collaboration_index', methods: ['GET'])]
    public function collaborationIndex(int $id): Response
    {
        $project = $this->getProject($id);
        return $this->json($this->indicators->collaborationIndex($project->getId()));
    }

    #[Route('/data/growth-rate', name: 'app_dashboard_growth_rate', methods: ['GET'])]
    public function growthRate(int $id): Response
    {
        $project = $this->getProject($id);
        return $this->json($this->indicators->productionGrowthRate($project->getId()));
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function getProject(int $id): BibliometricProject
    {
        $project = $this->em->getRepository(BibliometricProject::class)->find($id);
        if (!$project || $project->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }
        return $project;
    }
}
