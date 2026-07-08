<?php

namespace App\Controller;

use App\Entity\BibliometricProject;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/projects/{id}/reports/geography')]
#[IsGranted('ROLE_USER')]
class GeographicReportController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em
    ) {}

    #[Route('', name: 'app_report_geography', methods: ['GET'])]
    public function index(int $id, Request $request): Response
    {
        $project = $this->getProject($id);
        $conn = $this->em->getConnection();

        $countryId = $request->query->get('countryId');
        $filteredCountry = null;
        if ($countryId) {
            $filteredCountry = $this->em->getRepository(\App\Entity\Country::class)->find((int)$countryId);
        }

        // 1. Continents distribution
        $continentsQuery = 'SELECT co.continente AS label, COUNT(DISTINCT d.id) AS doc_count, SUM(COALESCE(d.cited_by, 0)) AS citation_count
             FROM document d
             JOIN documento_paises dp ON dp.document_id = d.id
             JOIN paises co ON co.id = dp.country_id
             WHERE d.project_id = ? AND co.continente IS NOT NULL';
        $continentsParams = [$project->getId()];
        if ($filteredCountry) {
            $continentsQuery .= ' AND co.id = ?';
            $continentsParams[] = $filteredCountry->getId();
        }
        $continentsQuery .= ' GROUP BY co.continente ORDER BY doc_count DESC';
        $continents = $conn->fetchAllAssociative($continentsQuery, $continentsParams);

        // 2. Brazilian Regions distribution
        $regionsQuery = 'SELECT r.name AS label, COUNT(DISTINCT d.id) AS doc_count, SUM(COALESCE(d.cited_by, 0)) AS citation_count
             FROM document d
             JOIN documento_estados de ON de.document_id = d.id
             JOIN estados s ON s.id = de.state_id
             JOIN regioes r ON r.id = s.region_id
             WHERE d.project_id = ?';
        $regionsParams = [$project->getId()];
        if ($filteredCountry) {
            $regionsQuery .= ' AND s.country_id = ?';
            $regionsParams[] = $filteredCountry->getId();
        }
        $regionsQuery .= ' GROUP BY r.id ORDER BY doc_count DESC';
        $regions = $conn->fetchAllAssociative($regionsQuery, $regionsParams);

        // 3. States distribution (Top 15)
        $statesQuery = 'SELECT s.official_name AS label, s.sigla AS sigla, c.common_name AS country, COUNT(DISTINCT d.id) AS doc_count, SUM(COALESCE(d.cited_by, 0)) AS citation_count
             FROM document d
             JOIN documento_estados de ON de.document_id = d.id
             JOIN estados s ON s.id = de.state_id
             JOIN paises c ON c.id = s.country_id
             WHERE d.project_id = ?';
        $statesParams = [$project->getId()];
        if ($filteredCountry) {
            $statesQuery .= ' AND s.country_id = ?';
            $statesParams[] = $filteredCountry->getId();
        }
        $statesQuery .= ' GROUP BY s.id ORDER BY doc_count DESC LIMIT 15';
        $states = $conn->fetchAllAssociative($statesQuery, $statesParams);

        // 4. Cities distribution (Top 15)
        $citiesQuery = 'SELECT ct.official_name AS label, s.sigla AS state_sigla, c.common_name AS country, COUNT(DISTINCT d.id) AS doc_count, SUM(COALESCE(d.cited_by, 0)) AS citation_count
             FROM document d
             JOIN documento_cidades dc ON dc.document_id = d.id
             JOIN cidades ct ON ct.id = dc.city_id
             JOIN paises c ON c.id = ct.country_id
             LEFT JOIN estados s ON s.id = ct.state_id
             WHERE d.project_id = ?';
        $citiesParams = [$project->getId()];
        if ($filteredCountry) {
            $citiesQuery .= ' AND ct.country_id = ?';
            $citiesParams[] = $filteredCountry->getId();
        }
        $citiesQuery .= ' GROUP BY ct.id ORDER BY doc_count DESC LIMIT 15';
        $cities = $conn->fetchAllAssociative($citiesQuery, $citiesParams);

        // 5. Institution Nature
        $natureQuery = 'SELECT i.natureza AS label, COUNT(DISTINCT d.id) AS doc_count, SUM(COALESCE(d.cited_by, 0)) AS citation_count
             FROM document d
             JOIN documento_instituicoes di ON di.document_id = d.id
             JOIN instituicoes_ensino i ON i.id = di.institution_id
             WHERE d.project_id = ? AND i.natureza IS NOT NULL';
        $natureParams = [$project->getId()];
        if ($filteredCountry) {
            $natureQuery .= ' AND i.country_id = ?';
            $natureParams[] = $filteredCountry->getId();
        }
        $natureQuery .= ' GROUP BY i.natureza ORDER BY doc_count DESC';
        $nature = $conn->fetchAllAssociative($natureQuery, $natureParams);

        // 6. Institution Type
        $typesQuery = 'SELECT i.institution_type AS label, COUNT(DISTINCT d.id) AS doc_count, SUM(COALESCE(d.cited_by, 0)) AS citation_count
             FROM document d
             JOIN documento_instituicoes di ON di.document_id = d.id
             JOIN instituicoes_ensino i ON i.id = di.institution_id
             WHERE d.project_id = ? AND i.institution_type IS NOT NULL';
        $typesParams = [$project->getId()];
        if ($filteredCountry) {
            $typesQuery .= ' AND i.country_id = ?';
            $typesParams[] = $filteredCountry->getId();
        }
        $typesQuery .= ' GROUP BY i.institution_type ORDER BY doc_count DESC';
        $types = $conn->fetchAllAssociative($typesQuery, $typesParams);

        // Count mapped documents vs total
        $totalDocs = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM document WHERE project_id = ?',
            [$project->getId()]
        );

        $mappedQuery = 'SELECT COUNT(DISTINCT d.id) FROM document d
                        LEFT JOIN documento_instituicoes di ON di.document_id = d.id
                        LEFT JOIN documento_paises dp ON dp.document_id = d.id
                        WHERE d.project_id = ?';
        $mappedParams = [$project->getId()];
        if ($filteredCountry) {
            $mappedQuery .= ' AND (di.institution_id IN (SELECT id FROM instituicoes_ensino WHERE country_id = ?) OR dp.country_id = ?)';
            $mappedParams[] = $filteredCountry->getId();
            $mappedParams[] = $filteredCountry->getId();
        } else {
            $mappedQuery .= ' AND (di.id IS NOT NULL OR dp.document_id IS NOT NULL)';
        }
        $mappedDocs = (int) $conn->fetchOne($mappedQuery, $mappedParams);

        // Fetch all countries that have documents in this project to display in the filter dropdown
        $allCountries = $conn->fetchAllAssociative(
            'SELECT DISTINCT c.id, c.common_name
             FROM documento_paises dp
             JOIN paises c ON dp.country_id = c.id
             JOIN document d ON dp.document_id = d.id
             WHERE d.project_id = ?
             ORDER BY c.common_name ASC',
            [$project->getId()]
        );

        return $this->render('report/geography_report.html.twig', [
            'project' => $project,
            'continents' => $continents,
            'regions' => $regions,
            'states' => $states,
            'cities' => $cities,
            'nature' => $nature,
            'types' => $types,
            'total_docs' => $totalDocs,
            'mapped_docs' => $mappedDocs,
            'all_countries' => $allCountries,
            'selected_country_id' => $filteredCountry ? $filteredCountry->getId() : null,
        ]);
    }

    #[Route('/cities/export', name: 'app_report_geography_cities_export', methods: ['GET'])]
    public function exportCitiesCsv(int $id): Response
    {
        $project = $this->getProject($id);
        $conn = $this->em->getConnection();

        $rows = $conn->fetchAllAssociative(
            'SELECT ct.official_name AS city, s.official_name AS state, s.sigla AS state_sigla, 
                    c.common_name AS country, c.continente AS continent, 
                    COUNT(DISTINCT d.id) AS doc_count, SUM(COALESCE(d.cited_by, 0)) AS citation_count
             FROM document d
             JOIN documento_cidades dc ON dc.document_id = d.id
             JOIN cidades ct ON ct.id = dc.city_id
             JOIN paises c ON c.id = ct.country_id
             LEFT JOIN estados s ON s.id = ct.state_id
             WHERE d.project_id = ?
             GROUP BY ct.id, s.id, c.id
             ORDER BY doc_count DESC',
            [$project->getId()]
        );

        $csv = \League\Csv\Writer::createFromString('');
        $csv->insertOne(['city', 'state', 'state_sigla', 'country', 'continent', 'document_count', 'citation_count']);

        foreach ($rows as $row) {
            $csv->insertOne([
                $row['city'],
                $row['state'] ?? '',
                $row['state_sigla'] ?? '',
                $row['country'],
                $row['continent'] ?? '',
                $row['doc_count'],
                $row['citation_count']
            ]);
        }

        $response = new Response($csv->toString());
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="cidades_projeto_' . $id . '.csv"');

        return $response;
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
