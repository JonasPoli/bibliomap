<?php

namespace App\Controller;

use App\Entity\BibliometricProject;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/projects/{id}/reports')]
#[IsGranted('ROLE_USER')]
class AnnualProductionController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $conn,
    ) {}

    #[Route('/annual-production', name: 'app_report_annual_production', methods: ['GET'])]
    public function index(int $id): Response
    {
        $project = $this->getProject($id);

        // Fetch per-year stats
        $rows = $this->conn->fetchAllAssociative(
            'SELECT year,
                    COUNT(*) AS doc_count,
                    SUM(COALESCE(cited_by, 0)) AS citation_count,
                    ROUND(AVG(COALESCE(cited_by, 0)), 1) AS avg_citations
             FROM document
             WHERE project_id = ? AND year IS NOT NULL
             GROUP BY year
             ORDER BY year ASC',
            [$id]
        );

        // Augment with cumulative, growth and moving average
        $cumulative = 0;
        $prevCount = null;
        $annualData = [];

        foreach ($rows as $row) {
            $cumulative += (int) $row['doc_count'];
            $growth = null;
            if ($prevCount !== null && $prevCount > 0) {
                $growth = round((($row['doc_count'] - $prevCount) / $prevCount) * 100, 1);
            }
            $annualData[] = [
                'year'          => (int) $row['year'],
                'doc_count'     => (int) $row['doc_count'],
                'citation_count'=> (int) $row['citation_count'],
                'avg_citations' => (float) $row['avg_citations'],
                'cumulative'    => $cumulative,
                'growth_pct'    => $growth,
            ];
            $prevCount = (int) $row['doc_count'];
        }

        // 3-year moving average
        $ma3 = [];
        for ($i = 0; $i < count($annualData); $i++) {
            if ($i < 2) {
                $ma3[] = null;
            } else {
                $ma3[] = round(
                    ($annualData[$i - 2]['doc_count'] + $annualData[$i - 1]['doc_count'] + $annualData[$i]['doc_count']) / 3,
                    1
                );
            }
        }

        // KPIs
        $totalDocs   = array_sum(array_column($annualData, 'doc_count'));
        $totalYears  = count($annualData);
        $bestYear    = $totalYears > 0 ? $annualData[array_search(max(array_column($annualData, 'doc_count')), array_column($annualData, 'doc_count'))]['year'] : 'N/A';
        $firstYear   = $totalYears > 0 ? $annualData[0]['year'] : 'N/A';
        $lastYear    = $totalYears > 0 ? $annualData[$totalYears - 1]['year'] : 'N/A';

        // Average annual growth (compound)
        $cagr = null;
        if ($totalYears >= 2) {
            $startCount = $annualData[0]['doc_count'];
            $endCount   = $annualData[$totalYears - 1]['doc_count'];
            if ($startCount > 0) {
                $cagr = round((pow($endCount / $startCount, 1 / ($totalYears - 1)) - 1) * 100, 1);
            }
        }

        return $this->render('report/annual_production.html.twig', [
            'project'    => $project,
            'annualData' => $annualData,
            'ma3'        => $ma3,
            'kpis'       => [
                'total_docs'  => $totalDocs,
                'total_years' => $totalYears,
                'best_year'   => $bestYear,
                'first_year'  => $firstYear,
                'last_year'   => $lastYear,
                'cagr'        => $cagr,
            ],
        ]);
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
