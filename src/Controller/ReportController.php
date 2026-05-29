<?php

namespace App\Controller;

use App\Entity\BibliometricProject;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/projects/{id}/reports')]
#[IsGranted('ROLE_USER')]
class ReportController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $conn,
        private readonly \App\Service\Analytics\ReportService $reportService,
    ) {}

    // ── Keyword Evolution — Step 1: selection ─────────────────────────────────

    #[Route('/keyword-evolution', name: 'app_report_keyword_evolution', methods: ['GET'])]
    public function keywordEvolution(int $id, Request $request): Response
    {
        $project = $this->getProject($id);

        // Available years in this project
        $years = $this->conn->fetchFirstColumn(
            'SELECT DISTINCT year FROM document WHERE project_id = ? AND year IS NOT NULL ORDER BY year',
            [$id]
        );

        // Top keywords by occurrence (author + indexed, sorted by count DESC)
        // Limit to 300 to keep the page manageable; user can filter via search
        $keywords = $this->conn->fetchAllAssociative(
            'SELECT k.id, k.term, k.type, COUNT(DISTINCT dk.document_id) AS doc_count
             FROM keyword k
             JOIN document_keyword dk ON dk.keyword_id = k.id
             JOIN document d          ON d.id = dk.document_id AND d.project_id = ?
             GROUP BY k.id
             ORDER BY doc_count DESC
             LIMIT 300',
            [$id]
        );

        return $this->render('report/keyword_evolution_step1.html.twig', [
            'project'  => $project,
            'years'    => $years,
            'keywords' => $keywords,
        ]);
    }

    // ── Keyword Evolution — Step 2: chart ────────────────────────────────────

    #[Route('/keyword-evolution/chart', name: 'app_report_keyword_evolution_chart', methods: ['POST'])]
    public function keywordEvolutionChart(int $id, Request $request): Response
    {
        $project = $this->getProject($id);

        $selectedIds = array_map('intval', (array) $request->request->all('keywords'));
        $yearFrom    = (int) $request->request->get('year_from');
        $yearTo      = (int) $request->request->get('year_to');

        // Validation
        if (empty($selectedIds)) {
            $this->addFlash('danger', 'Selecione ao menos uma palavra-chave.');
            return $this->redirectToRoute('app_report_keyword_evolution', ['id' => $id]);
        }
        if ($yearFrom >= $yearTo) {
            $this->addFlash('danger', 'O ano inicial deve ser menor que o ano final.');
            return $this->redirectToRoute('app_report_keyword_evolution', ['id' => $id]);
        }

        // Fetch selected keywords info
        $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
        $selectedKeywords = $this->conn->fetchAllAssociative(
            "SELECT id, term, type FROM keyword WHERE id IN ($placeholders) ORDER BY term",
            $selectedIds
        );

        // Available years (for display)
        $years = $this->conn->fetchFirstColumn(
            'SELECT DISTINCT year FROM document WHERE project_id = ? AND year IS NOT NULL ORDER BY year',
            [$id]
        );

        return $this->render('report/keyword_evolution_chart.html.twig', [
            'project'          => $project,
            'selectedKeywords' => $selectedKeywords,
            'selectedIds'      => $selectedIds,
            'yearFrom'         => $yearFrom,
            'yearTo'           => $yearTo,
            'years'            => $years,
        ]);
    }

    // ── JSON data endpoint ────────────────────────────────────────────────────

    #[Route('/keyword-evolution/data', name: 'app_report_keyword_evolution_data', methods: ['GET'])]
    public function keywordEvolutionData(int $id, Request $request): Response
    {
        $project     = $this->getProject($id);
        $selectedIds = array_map('intval', (array) $request->query->all('kw'));
        $yearFrom    = (int) $request->query->get('year_from');
        $yearTo      = (int) $request->query->get('year_to');

        if (empty($selectedIds) || $yearFrom >= $yearTo) {
            return $this->json(['error' => 'Invalid parameters'], 400);
        }

        $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
        $params = array_merge([$id], $selectedIds, [$yearFrom, $yearTo]);

        $rows = $this->conn->fetchAllAssociative(
            "SELECT k.id, k.term, d.year, COUNT(DISTINCT dk.document_id) AS count
             FROM keyword k
             JOIN document_keyword dk ON dk.keyword_id = k.id
             JOIN document d          ON d.id = dk.document_id AND d.project_id = ?
             WHERE k.id IN ($placeholders)
               AND d.year BETWEEN ? AND ?
             GROUP BY k.id, k.term, d.year
             ORDER BY k.term, d.year",
            $params
        );

        // Build year range
        $allYears = range($yearFrom, $yearTo);

        // Pivot: keyword → year → count
        $pivot = [];
        foreach ($rows as $row) {
            $pivot[$row['id']]['term'] = $row['term'];
            $pivot[$row['id']]['years'][$row['year']] = (int) $row['count'];
        }

        // Fill missing years with 0
        foreach ($pivot as &$kw) {
            foreach ($allYears as $y) {
                if (!isset($kw['years'][$y])) {
                    $kw['years'][$y] = 0;
                }
            }
            ksort($kw['years']);
        }

        return $this->json([
            'years'    => $allYears,
            'keywords' => array_values($pivot),
        ]);
    }

    #[Route('/keyword-evolution/export', name: 'app_report_keyword_evolution_export', methods: ['GET'])]
    public function export(int $id, Request $request): Response
    {
        $project     = $this->getProject($id);
        $selectedIds = array_map('intval', (array) $request->query->all('kw'));
        $yearFrom    = (int) $request->query->get('year_from');
        $yearTo      = (int) $request->query->get('year_to');

        if (empty($selectedIds) || $yearFrom >= $yearTo) {
            throw $this->createNotFoundException('Parâmetros inválidos.');
        }

        $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
        $params = array_merge([$id], $selectedIds, [$yearFrom, $yearTo]);

        $rows = $this->conn->fetchAllAssociative(
            "SELECT k.id, k.term, d.year, COUNT(DISTINCT dk.document_id) AS count
             FROM keyword k
             JOIN document_keyword dk ON dk.keyword_id = k.id
             JOIN document d          ON d.id = dk.document_id AND d.project_id = ?
             WHERE k.id IN ($placeholders)
               AND d.year BETWEEN ? AND ?
             GROUP BY k.id, k.term, d.year
             ORDER BY k.term, d.year",
            $params
        );

        $allYears = range($yearFrom, $yearTo);

        $pivot = [];
        foreach ($rows as $row) {
            $pivot[$row['id']]['term'] = $row['term'];
            $pivot[$row['id']]['years'][$row['year']] = (int) $row['count'];
        }

        foreach ($pivot as &$kw) {
            foreach ($allYears as $y) {
                if (!isset($kw['years'][$y])) {
                    $kw['years'][$y] = 0;
                }
            }
            ksort($kw['years']);
        }

        $fp = fopen('php://temp', 'r+');
        fwrite($fp, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel

        $headers = array_merge(['Palavra-Chave'], array_map(fn($y) => (string)$y, $allYears), ['Total']);
        fputcsv($fp, $headers, ';');

        foreach ($pivot as $kw) {
            $rowValues = [$kw['term']];
            $total = 0;
            foreach ($allYears as $y) {
                $count = $kw['years'][$y];
                $rowValues[] = $count;
                $total += $count;
            }
            $rowValues[] = $total;
            fputcsv($fp, $rowValues, ';');
        }

        rewind($fp);
        $csvContent = stream_get_contents($fp);
        fclose($fp);

        $response = new Response($csvContent);
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $projectNameSlug = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $project->getTitle());
        $filename = sprintf('evolucao-keywords-%s-%d-%d.csv', substr($projectNameSlug, 0, 30), $yearFrom, $yearTo);
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $response;
    }

    // ── 6 Reports ─────────────────────────────────────────────────────────────

    #[Route('/authors', name: 'app_report_authors', methods: ['GET'])]
    public function authors(int $id): Response
    {
        $project = $this->getProject($id);
        $data = $this->reportService->getAuthorsReport($project->getId());
        return $this->render('report/authors.html.twig', [
            'project'        => $project,
            'list'           => $data['list'],
            'kpis'           => $data['kpis'],
            'lotka_observed' => $data['lotka_observed'],
            'lotka_expected' => $data['lotka_expected'],
        ]);
    }

    #[Route('/sources', name: 'app_report_sources', methods: ['GET'])]
    public function sources(int $id): Response
    {
        $project = $this->getProject($id);
        $data = $this->reportService->getSourcesReport($project->getId());
        return $this->render('report/sources.html.twig', [
            'project'  => $project,
            'list'     => $data['list'],
            'kpis'     => $data['kpis'],
            'bradford' => $data['bradford'],
        ]);
    }

    #[Route('/documents', name: 'app_report_documents', methods: ['GET'])]
    public function documents(int $id): Response
    {
        $project = $this->getProject($id);
        $data = $this->reportService->getDocumentsReport($project->getId());
        return $this->render('report/documents.html.twig', [
            'project' => $project,
            'list'    => $data['list'],
            'kpis'    => $data['kpis'],
        ]);
    }

    #[Route('/keywords', name: 'app_report_keywords', methods: ['GET'])]
    public function keywords(int $id): Response
    {
        $project = $this->getProject($id);
        $data = $this->reportService->getKeywordsReport($project->getId());
        return $this->render('report/keywords.html.twig', [
            'project' => $project,
            'list'    => $data['list'],
            'kpis'    => $data['kpis'],
        ]);
    }

    #[Route('/countries', name: 'app_report_countries', methods: ['GET'])]
    public function countries(int $id): Response
    {
        $project = $this->getProject($id);
        $data = $this->reportService->getCountriesReport($project->getId());
        return $this->render('report/countries.html.twig', [
            'project' => $project,
            'list'    => $data['list'],
            'kpis'    => $data['kpis'],
        ]);
    }

    #[Route('/institutions', name: 'app_report_institutions', methods: ['GET'])]
    public function institutions(int $id): Response
    {
        $project = $this->getProject($id);
        $data = $this->reportService->getInstitutionsReport($project->getId());
        return $this->render('report/institutions.html.twig', [
            'project' => $project,
            'list'    => $data['list'],
            'kpis'    => $data['kpis'],
        ]);
    }

    #[Route('/general', name: 'app_report_general', methods: ['GET'])]
    public function general(int $id): Response
    {
        $project = $this->getProject($id);
        $data    = $this->reportService->getGeneralReport($project->getId());

        return $this->render('report/general.html.twig', [
            'project'      => $project,
            'kpis'         => $data['kpis'],
            'annual'       => $data['annual'],
            'topAuthors'   => $data['topAuthors'],
            'topSources'   => $data['topSources'],
            'topKeywords'  => $data['topKeywords'],
            'topDocs'      => $data['topDocs'],
            'topCountries' => $data['topCountries'],
        ]);
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
