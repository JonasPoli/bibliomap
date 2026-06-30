<?php

namespace App\Controller;

use App\Entity\BibliometricProject;
use App\Entity\TheoreticalLens;
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
    public function authors(int $id, Request $request): Response
    {
        $project = $this->getProject($id);
        $search = $request->query->get('q');
        $data = $this->reportService->getAuthorsReport($project->getId(), 100, $search);
        return $this->render('report/authors.html.twig', [
            'project'        => $project,
            'list'           => $data['list'],
            'kpis'           => $data['kpis'],
            'lotka_observed' => $data['lotka_observed'],
            'lotka_expected' => $data['lotka_expected'],
        ]);
    }

    #[Route('/authors/{authorId}/documents', name: 'app_report_author_documents', methods: ['GET'])]
    public function authorDocuments(int $id, int $authorId): Response
    {
        $project = $this->getProject($id);
        
        // Fetch all documents for this author inside this project
        $documents = $this->conn->fetchAllAssociative(
            'SELECT d.id, d.title, d.year, d.source_title, d.doi, d.url, d.cited_by, d.document_type,
                    d.volume, d.issue, d.page_start, d.page_end, d.publisher, d.issn, d.isbn, d.abstract_text,
                    (
                        SELECT GROUP_CONCAT(a2.name ORDER BY da2.position SEPARATOR \'; \')
                        FROM document_author da2
                        JOIN author a2 ON a2.id = da2.author_id
                        WHERE da2.document_id = d.id
                    ) AS author_names
             FROM document d
             JOIN document_author da ON d.id = da.document_id
             WHERE d.project_id = ? AND da.author_id = ?
             ORDER BY d.year DESC, d.title ASC',
            [$project->getId(), $authorId]
        );

        // Fetch author name
        $authorName = $this->conn->fetchOne(
            'SELECT name FROM author WHERE id = ?',
            [$authorId]
        );

        return $this->json([
            'authorName' => $authorName ?: 'Autor desconhecido',
            'documents' => $documents,
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
    public function keywords(int $id, Request $request): Response
    {
        $project = $this->getProject($id);
        $search = $request->query->get('q');
        $data = $this->reportService->getKeywordsReport($project->getId(), 150, $search);
        return $this->render('report/keywords.html.twig', [
            'project' => $project,
            'list'    => $data['list'],
            'kpis'    => $data['kpis'],
        ]);
    }

    #[Route('/keywords/{keywordId}/documents', name: 'app_report_keyword_documents', methods: ['GET'])]
    public function keywordDocuments(int $id, int $keywordId): Response
    {
        $project = $this->getProject($id);
        
        // Fetch all documents for this keyword inside this project
        $documents = $this->conn->fetchAllAssociative(
            'SELECT d.id, d.title, d.year, d.source_title, d.doi, d.url, d.cited_by, d.document_type,
                    d.volume, d.issue, d.page_start, d.page_end, d.publisher, d.issn, d.isbn, d.abstract_text,
                    (
                        SELECT GROUP_CONCAT(a2.name ORDER BY da2.position SEPARATOR \'; \')
                        FROM document_author da2
                        JOIN author a2 ON a2.id = da2.author_id
                        WHERE da2.document_id = d.id
                    ) AS author_names
             FROM document d
             JOIN document_keyword dk ON d.id = dk.document_id
             WHERE d.project_id = ? AND dk.keyword_id = ?
             ORDER BY d.year DESC, d.title ASC',
            [$project->getId(), $keywordId]
        );

        // Fetch keyword term
        $keywordTerm = $this->conn->fetchOne(
            'SELECT term FROM keyword WHERE id = ?',
            [$keywordId]
        );

        return $this->json([
            'keyword' => $keywordTerm ?: 'Palavra-chave desconhecida',
            'documents' => $documents,
        ]);
    }

    #[Route('/documents/search', name: 'app_report_documents_search', methods: ['GET'])]
    public function search(int $id, Request $request): Response
    {
        $project = $this->getProject($id);
        
        $filters = [
            'author'   => $request->query->get('author', ''),
            'keyword'  => $request->query->get('keyword', ''),
            'abstract' => $request->query->get('abstract', ''),
            'title'    => $request->query->get('title', ''),
            'year'     => $request->query->get('year', ''),
        ];

        // Only run search if at least one filter is specified
        $hasSearch = !empty(array_filter($filters));
        $list = [];
        if ($hasSearch) {
            $list = $this->reportService->searchDocuments($project->getId(), $filters);
        }

        return $this->render('report/search.html.twig', [
            'project'   => $project,
            'filters'   => $filters,
            'list'      => $list,
            'hasSearch' => $hasSearch,
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

    #[Route('/theoretical-lenses', name: 'app_report_theoretical_lenses', methods: ['GET'])]
    public function theoreticalLenses(int $id): Response
    {

        ini_set('memory_limit', '512M');
        $project = $this->getProject($id);

        $cacheDir = $this->getParameter('kernel.project_dir') . '/var/theoretical_lenses_cache';
        $cacheFile = $cacheDir . '/project_' . $id . '.json';

        if (file_exists($cacheFile)) {
            $cachedData = json_decode(file_get_contents($cacheFile), true);
            if (is_array($cachedData)) {
                return $this->render('report/theoretical_lenses.html.twig', [
                    'project'         => $project,
                    'theorists'       => $cachedData['theorists'],
                    'categories'      => $cachedData['categories'],
                    'total_docs'      => $cachedData['total_docs'],
                    'research_fields' => $cachedData['research_fields'],
                ]);
            }
        }

        // Cache does not exist, redirect to the beautiful processing loading screen
        return $this->redirectToRoute('app_report_theoretical_lenses_loading', ['id' => $id]);
    }

    #[Route('/theoretical-lenses/loading', name: 'app_report_theoretical_lenses_loading', methods: ['GET'])]
    public function theoreticalLensesLoading(int $id): Response
    {
        $project = $this->getProject($id);
        return $this->render('report/theoretical_lenses_loading.html.twig', [
            'project' => $project,
        ]);
    }

    #[Route('/theoretical-lenses/recalculate', name: 'app_report_theoretical_lenses_recalculate', methods: ['GET'])]
    public function theoreticalLensesRecalculate(int $id): Response
    {
        $project = $this->getProject($id);
        $cacheDir = $this->getParameter('kernel.project_dir') . '/var/theoretical_lenses_cache';
        $cacheFile = $cacheDir . '/project_' . $id . '.json';
        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }
        return $this->redirectToRoute('app_report_theoretical_lenses_loading', ['id' => $id]);
    }

    #[Route('/theoretical-lenses/process-batch', name: 'app_report_theoretical_lenses_process_batch', methods: ['POST'])]
    public function theoreticalLensesProcessBatch(int $id, Request $request): Response
    {
        ini_set('memory_limit', '512M');
        $project = $this->getProject($id);
        
        $payload = json_decode($request->getContent(), true) ?? [];
        $step = (int) ($payload['step'] ?? 0);
        $batchSize = (int) ($payload['batchSize'] ?? 200);

        
        $session = $request->getSession();
        
        if ($step === 0) {
            // STEP 0: Initialization
            // Fetch all lenses matching fields efficiently from DB (lightweight, raw DBAL)
            $lenses = $this->conn->fetchAllAssociative(
                'SELECT id, name, terms, citation_formats FROM theoretical_lens'
            );
            
            $theorists = [];
            foreach ($lenses as $lens) {
                $terms = json_decode($lens['terms'], true) ?? [];
                $citations = json_decode($lens['citation_formats'], true) ?? [];
                $theorists[$lens['id']] = [
                    'id' => $lens['id'],
                    'name' => $lens['name'],
                    'terms' => $terms,
                    'match_count' => 0,
                    'docs' => [],
                    'ref_match_count' => 0,
                    'ref_docs' => [],
                    'terms_lower' => array_map('strtolower', $terms),
                    'citations_lower' => array_map('strtolower', $citations),
                ];

            }
            
            $session->set('theorists_batch_' . $id, $theorists);
            
            $totalDocs = (int) $this->conn->fetchOne(
                'SELECT COUNT(*) FROM document WHERE project_id = ?',
                [$id]
            );
            
            return $this->json([
                'status' => 'initialized',
                'totalDocs' => $totalDocs,
                'nextStep' => 1
            ]);
        }
        
        // STEP > 0: Process a batch slice of documents
        $theorists = $session->get('theorists_batch_' . $id);
        if (!$theorists) {
            return $this->json(['error' => 'Not initialized'], 400);
        }
        
        $offset = ($step - 1) * $batchSize;
        $documents = $this->conn->fetchAllAssociative(
            'SELECT d.id, d.title, d.abstract_text, d.year, d.references, d.doi, d.url
             FROM document d
             WHERE d.project_id = ?
             LIMIT ' . (int)$batchSize . ' OFFSET ' . (int)$offset,
            [$id]
        );

        
        if (empty($documents)) {
            // STEP FINAL: Finalization
            // Sort theorists by matches
            uasort($theorists, function ($a, $b) {
                $comp = $b['match_count'] <=> $a['match_count'];
                if ($comp === 0) {
                    return $b['ref_match_count'] <=> $a['ref_match_count'];
                }
                return $comp;
            });
            
            // Check if matches exist
            $hasMatches = false;
            foreach ($theorists as $t) {
                if ($t['match_count'] > 0 || $t['ref_match_count'] > 0) {
                    $hasMatches = true;
                    break;
                }
            }
            
            $displayTheorists = [];
            if ($hasMatches) {
                foreach ($theorists as $key => $t) {
                    if ($t['match_count'] > 0 || $t['ref_match_count'] > 0) {
                        $displayTheorists[$key] = $t;
                    }
                }
            } else {
                $displayTheorists = array_slice($theorists, 0, 20, true);
            }
            
            // Batch fetch full details (category, description, icon, color) ONLY for displayed theorists
            $displayLensIds = array_keys($displayTheorists);
            $fullLensMap = [];
            if (!empty($displayLensIds)) {
                $placeholders = implode(',', array_fill(0, count($displayLensIds), '?'));
                $fullLensRows = $this->conn->fetchAllAssociative(
                    "SELECT id, category, research_field, description, icon, color
                     FROM theoretical_lens
                     WHERE id IN ($placeholders)",
                    $displayLensIds
                );
                foreach ($fullLensRows as $row) {
                    $fullLensMap[$row['id']] = $row;
                }
            }
            
            // Merge details
            foreach ($displayTheorists as $key => &$t) {
                if (isset($fullLensMap[$key])) {
                    $t['category'] = $fullLensMap[$key]['category'] ?? 'Geral';
                    $t['researchField'] = $fullLensMap[$key]['research_field'] ?? 'Geral';
                    $t['description'] = $fullLensMap[$key]['description'] ?? '';
                    $t['icon'] = $fullLensMap[$key]['icon'] ?? 'bi-mortarboard';
                    $t['color'] = $fullLensMap[$key]['color'] ?? '#4f8ef7';
                } else {
                    $t['category'] = 'Geral';
                    $t['researchField'] = 'Geral';
                    $t['description'] = '';
                    $t['icon'] = 'bi-mortarboard';
                    $t['color'] = '#4f8ef7';
                }
            }
            unset($t);
            
            // Batch fetch author names for displayed sample documents
            $docIds = [];
            foreach ($displayTheorists as $t) {
                foreach ($t['docs'] as $d) {
                    $docIds[] = $d['id'];
                }
                foreach ($t['ref_docs'] as $d) {
                    $docIds[] = $d['id'];
                }
            }
            $docIds = array_unique($docIds);
            
            $authorMap = [];
            if (!empty($docIds)) {
                $placeholders = implode(',', array_fill(0, count($docIds), '?'));
                $authorRows = $this->conn->fetchAllAssociative(
                    "SELECT da.document_id, GROUP_CONCAT(a.name ORDER BY da.position SEPARATOR ', ') AS author_names
                     FROM document_author da
                     JOIN author a ON a.id = da.author_id
                     WHERE da.document_id IN ($placeholders)
                     GROUP BY da.document_id",
                    $docIds
                );
                foreach ($authorRows as $row) {
                    $authorMap[$row['document_id']] = $row['author_names'];
                }
            }
            
            // Fill authors
            foreach ($displayTheorists as &$t) {
                foreach ($t['docs'] as &$d) {
                    if (isset($authorMap[$d['id']])) {
                        $d['authors'] = $authorMap[$d['id']];
                    }
                }
                unset($d);
                foreach ($t['ref_docs'] as &$d) {
                    if (isset($authorMap[$d['id']])) {
                        $d['authors'] = $authorMap[$d['id']];
                    }
                }
                unset($d);
            }
            unset($t);
            
            // Group by category for visual cards
            $categories = [];
            foreach ($displayTheorists as $key => $t) {
                $categories[$t['category']][] = array_merge($t, ['key' => $key]);
            }
            
            // Extract unique research fields
            $researchFields = [];
            foreach ($displayTheorists as $t) {
                if ($t['researchField'] !== null && $t['researchField'] !== '') {
                    if (!in_array($t['researchField'], $researchFields)) {
                        $researchFields[] = $t['researchField'];
                    }
                }
            }
            sort($researchFields);
            
            $totalDocs = (int) $this->conn->fetchOne(
                'SELECT COUNT(*) FROM document WHERE project_id = ?',
                [$id]
            );
            
            // Save cache
            $cacheDir = $this->getParameter('kernel.project_dir') . '/var/theoretical_lenses_cache';
            if (!is_dir($cacheDir)) {
                mkdir($cacheDir, 0777, true);
            }
            $cacheFile = $cacheDir . '/project_' . $id . '.json';
            
            $cachedData = [
                'theorists' => $displayTheorists,
                'categories' => $categories,
                'total_docs' => $totalDocs,
                'research_fields' => $researchFields,
            ];
            
            file_put_contents($cacheFile, json_encode($cachedData));
            
            // Clean up session
            $session->remove('theorists_batch_' . $id);
            
            return $this->json(['status' => 'completed']);
        }
        
        // Match slice documents against theorists
        foreach ($documents as $doc) {
            $textLower = strtolower(($doc['title'] ?? '') . ' ' . ($doc['abstract_text'] ?? ''));
            $allRefsText = '';
            if (!empty($doc['references'])) {
                $refs = json_decode($doc['references'], true);
                if (is_array($refs)) {
                    $allRefsText = strtolower(implode(' || ', $refs));
                }
            }
            
            foreach ($theorists as &$t) {
                $terms = $t['terms_lower'];
                $citations = $t['citations_lower'];
                
                if (empty($terms)) {
                    continue;
                }
                
                // 1. Direct match (Title/Abstract)
                $directMatched = false;
                foreach ($terms as $term) {
                    if (str_contains($textLower, $term)) {
                        $directMatched = true;
                        break;
                    }
                }
                
                if ($directMatched) {
                    $t['match_count']++;
                    if (count($t['docs']) < 5) {
                        $t['docs'][] = [
                            'id' => $doc['id'],
                            'title' => $doc['title'],
                            'year' => $doc['year'],
                            'authors' => 'Autores desconhecidos',
                            'doi' => $doc['doi'] ?? null,
                            'url' => $doc['url'] ?? null,
                        ];
                    }
                }
                
                // 2. Cited References match
                $refMatched = false;
                if (!empty($citations)) {
                    foreach ($citations as $cit) {
                        if (str_contains($allRefsText, $cit)) {
                            $refMatched = true;
                            break;
                        }
                    }
                }
                
                if (!$refMatched) {
                    foreach ($terms as $term) {
                        if (str_contains($allRefsText, $term)) {
                            $refMatched = true;
                            break;
                        }
                    }
                }
                
                if ($refMatched) {
                    $t['ref_match_count']++;
                    if (count($t['ref_docs']) < 5) {
                        $t['ref_docs'][] = [
                            'id' => $doc['id'],
                            'title' => $doc['title'],
                            'year' => $doc['year'],
                            'authors' => 'Autores desconhecidos',
                            'doi' => $doc['doi'] ?? null,
                            'url' => $doc['url'] ?? null,
                        ];
                    }
                }
            }
            unset($t);
        }

        
        $session->set('theorists_batch_' . $id, $theorists);
        
        return $this->json([
            'status' => 'processing',
            'processedDocs' => $offset + count($documents),
            'nextStep' => $step + 1
        ]);
    }

    #[Route('/theoretical-lenses/add', name: 'app_report_theoretical_lenses_add', methods: ['POST'])]
    public function addTheoreticalLens(int $id, Request $request): Response
    {
        $project = $this->getProject($id);

        $name = trim($request->request->get('name', ''));
        $category = trim($request->request->get('category', ''));
        $researchField = trim($request->request->get('research_field', ''));
        $termsString = trim($request->request->get('terms', ''));
        $description = trim($request->request->get('description', ''));
        $icon = trim($request->request->get('icon', 'bi-mortarboard'));
        $color = trim($request->request->get('color', '#4f8ef7'));

        if ($name === '' || $category === '' || $description === '') {
            $this->addFlash('error', 'Nome, Categoria e Descrição são campos obrigatórios.');
            return $this->redirectToRoute('app_report_theoretical_lenses', ['id' => $project->getId()]);
        }

        // Clean research field
        if ($researchField === '') {
            $researchField = 'Geral';
        }

        // Parse terms
        $terms = array_filter(array_map('trim', explode(',', $termsString)));
        // Lowercase for unified matches
        $terms = array_map('strtolower', $terms);

        $lens = new TheoreticalLens();
        $lens->setName($name);
        $lens->setCategory($category);
        $lens->setResearchField($researchField);
        $lens->setTerms(array_values(array_unique($terms)));
        $lens->setDescription($description);
        $lens->setIcon($icon);
        $lens->setColor($color);

        $this->em->persist($lens);
        $this->em->flush();

        $this->addFlash('success', sprintf('Lente teórica de "%s" cadastrada com sucesso!', $name));

        return $this->redirectToRoute('app_report_theoretical_lenses', ['id' => $project->getId()]);
    }

    // ── Classification Report ──────────────────────────────────────────────────

    #[Route('/classification', name: 'app_report_classification', methods: ['GET'])]
    public function classificationReport(int $id): Response
    {
        $project = $this->getProject($id);
        $data    = $this->reportService->getClassificationReport($project->getId());

        return $this->render('report/classification_report.html.twig', [
            'project' => $project,
            'data'    => $data,
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

