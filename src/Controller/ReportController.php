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
    /** Teto de documentos por lote na mineração das lentes teóricas. */
    private const LENS_MAX_BATCH = 500;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $conn,
        private readonly \App\Service\Analytics\ReportService $reportService,
        private readonly \App\Service\Cache\AnalyticsCache $analyticsCache,
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
            'SELECT IF(k.thesaurus_concept_id IS NULL, COALESCE(k.keyword_concept_id, k.id), -k.thesaurus_concept_id) AS id,
                    COALESCE(tc.preferred_label, kc.keyword_display, k.keyword_display) AS term,
                    CASE WHEN COUNT(DISTINCT k.keyword_type) > 1 THEN \'both\'
                         WHEN MAX(k.keyword_type) = \'author_keyword\' THEN \'author\'
                         WHEN MAX(k.keyword_type) = \'indexed_keyword\' THEN \'indexed\'
                         ELSE MAX(k.keyword_type)
                    END AS type,
                    COUNT(DISTINCT dk.document_id) AS doc_count
             FROM keyword k
             LEFT JOIN thesaurus_concept tc ON tc.id = k.thesaurus_concept_id
            LEFT JOIN keyword kc ON k.keyword_concept_id = kc.id
             JOIN document_keyword dk ON dk.keyword_id = k.id
             JOIN document d          ON d.id = dk.document_id AND d.project_id = ?
             -- Um item por conceito (o gráfico soma as duas origens pelo mesmo id)
             GROUP BY IF(k.thesaurus_concept_id IS NULL, COALESCE(k.keyword_concept_id, k.id), -k.thesaurus_concept_id), COALESCE(tc.preferred_label, kc.keyword_display, k.keyword_display)
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
        $selectedKeywords = [];
        if (!empty($selectedIds)) {
            foreach ($selectedIds as $selId) {
                // ID efetivo negativo = conceito do tesauro (ver KeywordResolverService)
                $tc = $selId < 0
                    ? $this->conn->fetchAssociative('SELECT id, preferred_label AS term FROM thesaurus_concept WHERE id = ?', [-$selId])
                    : false;
                if ($tc) {
                    $selectedKeywords[] = [
                        'id' => (int)$selId,
                        'term' => $tc['term'],
                        'type' => 'thesaurus_concept',
                    ];
                } else {
                    // Otherwise fallback to keyword
                    $kw = $this->conn->fetchAssociative('SELECT id, keyword_display AS term, keyword_original, keyword_type AS type FROM keyword WHERE id = ?', [$selId]);
                    if ($kw) {
                        $selectedKeywords[] = [
                            'id' => (int)$kw['id'],
                            'term' => $kw['term'] ?: $kw['keyword_original'] ?? 'Sem rótulo',
                            'type' => $kw['type'] === 'author_keyword' ? 'author' : 'indexed',
                        ];
                    }
                }
            }
            // Sort by term alphabetically
            usort($selectedKeywords, fn($a, $b) => strcmp($a['term'], $b['term']));
        }

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
            "SELECT IF(k.thesaurus_concept_id IS NULL, COALESCE(k.keyword_concept_id, k.id), -k.thesaurus_concept_id) AS id,
                    COALESCE(tc.preferred_label, kc.keyword_display, k.keyword_display) AS term,
                    d.year,
                    COUNT(DISTINCT dk.document_id) AS count
             FROM keyword k
             LEFT JOIN thesaurus_concept tc ON tc.id = k.thesaurus_concept_id
            LEFT JOIN keyword kc ON k.keyword_concept_id = kc.id
             JOIN document_keyword dk ON dk.keyword_id = k.id
             JOIN document d          ON d.id = dk.document_id AND d.project_id = ?
             WHERE IF(k.thesaurus_concept_id IS NULL, COALESCE(k.keyword_concept_id, k.id), -k.thesaurus_concept_id) IN ($placeholders)
               AND d.year BETWEEN ? AND ?
             GROUP BY IF(k.thesaurus_concept_id IS NULL, COALESCE(k.keyword_concept_id, k.id), -k.thesaurus_concept_id), COALESCE(tc.preferred_label, kc.keyword_display, k.keyword_display), d.year
             ORDER BY term, d.year",
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
            "SELECT IF(k.thesaurus_concept_id IS NULL, COALESCE(k.keyword_concept_id, k.id), -k.thesaurus_concept_id) AS id,
                    COALESCE(tc.preferred_label, kc.keyword_display, k.keyword_display) AS term,
                    d.year,
                    COUNT(DISTINCT dk.document_id) AS count
             FROM keyword k
             LEFT JOIN thesaurus_concept tc ON tc.id = k.thesaurus_concept_id
            LEFT JOIN keyword kc ON k.keyword_concept_id = kc.id
             JOIN document_keyword dk ON dk.keyword_id = k.id
             JOIN document d          ON d.id = dk.document_id AND d.project_id = ?
             WHERE IF(k.thesaurus_concept_id IS NULL, COALESCE(k.keyword_concept_id, k.id), -k.thesaurus_concept_id) IN ($placeholders)
               AND d.year BETWEEN ? AND ?
             GROUP BY IF(k.thesaurus_concept_id IS NULL, COALESCE(k.keyword_concept_id, k.id), -k.thesaurus_concept_id), COALESCE(tc.preferred_label, kc.keyword_display, k.keyword_display), d.year
             ORDER BY term, d.year",
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
            $rowValues = [\App\Service\Analytics\CsvCell::escape($kw['term'])];
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
        $data = $this->cachedSearch($project->getId(), 'report.authors', ['limit' => 100], (string) $search,
            fn() => $this->reportService->getAuthorsReport($project->getId(), 100, $search));
        return $this->render('report/authors.html.twig', [
            'project'        => $project,
            'list'           => $data['list'],
            'kpis'           => $data['kpis'],
            'lotka_observed' => $data['lotka_observed'],
            'lotka_expected' => $data['lotka_expected'],
        ]);
    }

    #[Route('/authors/{authorId}/documents', name: 'app_report_author_documents', methods: ['GET'])]
    public function authorDocuments(int $id, int $authorId, Request $request): Response
    {
        $project = $this->getProject($id);

        // EXISTS em vez de JOIN: um autor repetido no mesmo documento não duplica a linha
        $documents = $this->fetchWorkList(
            $project->getId(),
            'EXISTS (SELECT 1 FROM document_author da WHERE da.document_id = d.id AND da.author_identity_id = ?)',
            [$authorId],
            $request
        );

        // Fetch author name
        $authorName = $this->conn->fetchOne(
            'SELECT preferred_name FROM author_identity WHERE id = ?',
            [$authorId]
        );

        return $this->json([
            'authorName' => $authorName ?: 'Autor desconhecido',
        ] + $documents);
    }

    /**
     * Lista de trabalhos de "Ver trabalhos" (autor ou palavra-chave).
     * Sem parâmetros devolve todos (como sempre, com o resumo, que o BibTeX usa).
     * ?limit=N (máx. 500) e ?offset=N paginam; ?abstract=0 omite o resumo (o campo mais pesado).
     * Com limit, a resposta traz também total e hasMore.
     *
     * @param array<int, mixed> $filterParams parâmetros do filtro (depois do id do projeto)
     * @return array{documents: list<array<string,mixed>>, total?: int, hasMore?: bool, limit?: int, offset?: int}
     */
    private function fetchWorkList(int $projectId, string $filterSql, array $filterParams, Request $request): array
    {
        $withAbstract = $request->query->get('abstract', '1') !== '0';
        $limitRaw     = $request->query->get('limit');
        $limit        = ($limitRaw !== null && $limitRaw !== '') ? min(500, max(1, (int) $limitRaw)) : null;
        $offset       = max(0, (int) $request->query->get('offset', 0));

        $params = array_merge([$projectId], $filterParams);
        $sql = 'SELECT d.id, d.title, d.year, d.source_title, d.doi, d.url, d.cited_by, d.document_type,
                       d.volume, d.issue, d.page_start, d.page_end, d.publisher, d.issn, d.isbn'
             . ($withAbstract ? ', d.abstract_text' : '') . ',
                       (
                           SELECT GROUP_CONCAT(a2.preferred_name ORDER BY da2.position SEPARATOR \'; \')
                           FROM document_author da2
                           JOIN author_identity a2 ON a2.id = da2.author_identity_id
                           WHERE da2.document_id = d.id
                       ) AS author_names
                FROM document d
                WHERE d.project_id = ? AND ' . $filterSql . '
                ORDER BY d.year DESC, d.title ASC, d.id ASC';

        if ($limit === null) {
            return ['documents' => $this->conn->fetchAllAssociative($sql, $params)];
        }

        // Uma linha a mais para saber se há próxima página, sem uma segunda consulta de contagem pesada
        $rows    = $this->conn->fetchAllAssociative($sql . ' LIMIT ' . ($limit + 1) . ' OFFSET ' . $offset, $params);
        $hasMore = count($rows) > $limit;
        $total   = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM document d WHERE d.project_id = ? AND ' . $filterSql, $params);

        return [
            'documents' => array_slice($rows, 0, $limit),
            'total'     => $total,
            'hasMore'   => $hasMore,
            'limit'     => $limit,
            'offset'    => $offset,
        ];
    }

    #[Route('/sources', name: 'app_report_sources', methods: ['GET'])]
    public function sources(int $id): Response
    {
        $project = $this->getProject($id);
        $data = $this->analyticsCache->get($project->getId(), 'report.sources', ['limit' => 100],
            fn() => $this->reportService->getSourcesReport($project->getId()));
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
        $data = $this->analyticsCache->get($project->getId(), 'report.documents', ['limit' => 100],
            fn() => $this->reportService->getDocumentsReport($project->getId()));
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
        $data = $this->cachedSearch($project->getId(), 'report.keywords', ['limit' => 150], (string) $search,
            fn() => $this->reportService->getKeywordsReport($project->getId(), 150, $search));
        return $this->render('report/keywords.html.twig', [
            'project' => $project,
            'list'    => $data['list'],
            'kpis'    => $data['kpis'],
        ]);
    }

    #[Route('/keywords/{keywordId}/documents', name: 'app_report_keyword_documents', methods: ['GET'])]
    public function keywordDocuments(int $id, int $keywordId, Request $request): Response
    {
        $project = $this->getProject($id);

        // O id efetivo identifica um conceito (negativo = conceito do tesauro). Primeiro os ids das
        // palavras-chave do conceito; depois EXISTS no índice de document_keyword, sem duplicar o
        // documento que tem duas variantes do mesmo conceito.
        $keywordIds = $this->conn->fetchFirstColumn(
            $keywordId < 0
                ? 'SELECT k.id FROM keyword k WHERE k.thesaurus_concept_id = ?'
                : 'SELECT k.id FROM keyword k WHERE k.thesaurus_concept_id IS NULL AND COALESCE(k.keyword_concept_id, k.id) = ?',
            [abs($keywordId)]
        );

        $documents = $keywordIds
            ? $this->fetchWorkList(
                $project->getId(),
                'EXISTS (SELECT 1 FROM document_keyword dk WHERE dk.document_id = d.id AND dk.keyword_id IN (' . implode(',', array_map('intval', $keywordIds)) . '))',
                [],
                $request
            )
            : ['documents' => []];

        // Fetch keyword term (coalesced concept display label)
        $keywordTerm = $this->conn->fetchOne(
            'SELECT COALESCE(tc.preferred_label, kc.keyword_display, k.keyword_display, k.keyword_original) AS term
             FROM keyword k
             LEFT JOIN thesaurus_concept tc ON tc.id = k.thesaurus_concept_id
             LEFT JOIN keyword kc ON k.keyword_concept_id = kc.id
             WHERE IF(k.thesaurus_concept_id IS NULL, COALESCE(k.keyword_concept_id, k.id), -k.thesaurus_concept_id) = ?
             LIMIT 1',
            [$keywordId]
        );

        return $this->json([
            'keyword' => $keywordTerm ?: 'Palavra-chave desconhecida',
        ] + $documents);
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

        $payload = $this->analyticsCache->get($project->getId(), 'report.countries', [], function () use ($project) {
            $data = $this->reportService->getCountriesReport($project->getId());

            $continents = $this->conn->fetchAllAssociative(
                // DISTINCT por (documento, continente): um artigo com 3 países europeus
                // conta 1 documento e suas citações uma única vez para a Europa.
                'SELECT label, COUNT(*) AS doc_count, SUM(cit) AS citation_count
                 FROM (
                     SELECT DISTINCT d.id, co.continente AS label, COALESCE(d.cited_by, 0) AS cit
                     FROM document d
                     JOIN documento_paises dp ON dp.document_id = d.id
                     JOIN paises co ON co.id = dp.country_id
                     WHERE d.project_id = ? AND co.continente IS NOT NULL
                 ) t
                 GROUP BY label ORDER BY doc_count DESC, label ASC',
                [$project->getId()]
            );

            // Base do "% de documentos": todos os documentos do projeto (um documento com
            // 2 países conta para os dois, por isso a coluna não soma 100%)
            $totalDocs = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM document WHERE project_id = ?', [$project->getId()]);

            return ['data' => $data, 'continents' => $continents, 'total_docs' => $totalDocs];
        });
        $data       = $payload['data'];
        $continents = $payload['continents'];
        $totalDocs  = $payload['total_docs'];

        return $this->render('report/countries.html.twig', [
            'project'    => $project,
            'list'       => $data['list'],
            'kpis'       => $data['kpis'],
            'continents' => $continents,
            'total_docs' => $totalDocs,
        ]);
    }

    #[Route('/institutions', name: 'app_report_institutions', methods: ['GET'])]
    public function institutions(int $id): Response
    {
        $project = $this->getProject($id);
        $data = $this->analyticsCache->get($project->getId(), 'report.institutions', [],
            fn() => $this->reportService->getInstitutionsReport($project->getId()));
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
        $data    = $this->analyticsCache->get($project->getId(), 'report.general', [],
            fn() => $this->reportService->getGeneralReport($project->getId()));

        return $this->render('report/general.html.twig', [
            'project'      => $project,
            'kpis'         => $data['kpis'],
            'annual'       => $data['annual'],
            'topAuthors'   => $data['topAuthors'],
            'topSources'   => $data['topSources'],
            'topKeywords'  => $data['topKeywords'],
            'topDocs'      => $data['topDocs'],
            'topCountries' => $data['topCountries'],
            'qualisDist'   => $data['qualisDist'] ?? [],
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
            // Cache de versão anterior (sem doc_match_count) é recalculado
            $stale = is_array($cachedData) && array_filter(
                $cachedData['theorists'] ?? [],
                fn($t) => !array_key_exists('doc_match_count', $t)
            );
            // Vencido quando os dados do sistema mudaram desde a mineração (versão diferente) ou quando o
            // arquivo não registra a versão (cache antigo ou gravado sem versão). Se a versão atual não pode
            // ser lida (tabela de versões ainda não criada), não há como comparar: o arquivo vale, senão
            // quem edita ficaria preso entre a tela de processamento e esta. Quem só visualiza continua vendo
            // o último resultado: só quem edita pode refazer a mineração.
            $currentVersion = $this->analyticsCache->currentVersion();
            $expired = is_array($cachedData) && $currentVersion !== null
                && ($cachedData['data_version'] ?? null) !== $currentVersion;
            if (is_array($cachedData) && !$stale && (!$expired || !$this->isGranted('edit', $project))) {
                return $this->render('report/theoretical_lenses.html.twig', [
                    'project'         => $project,
                    'theorists'       => $cachedData['theorists'],
                    'categories'      => $cachedData['categories'],
                    'total_docs'      => $cachedData['total_docs'],
                    'research_fields' => $cachedData['research_fields'],
                    'no_result'       => false,
                ]);
            }
        }

        // Sem resultado guardado: quem pode editar vai para a tela de processamento; quem só visualiza
        // (projeto público) vê uma mensagem, em vez de um erro na mineração que não pode executar.
        if (!$this->isGranted('edit', $project)) {
            return $this->render('report/theoretical_lenses.html.twig', [
                'project'         => $project,
                'theorists'       => [],
                'categories'      => [],
                'total_docs'      => 0,
                'research_fields' => [],
                'no_result'       => true,
            ]);
        }

        return $this->redirectToRoute('app_report_theoretical_lenses_loading', ['id' => $id]);
    }

    #[Route('/theoretical-lenses/loading', name: 'app_report_theoretical_lenses_loading', methods: ['GET'])]
    public function theoreticalLensesLoading(int $id): Response
    {
        $project = $this->getProject($id);
        // A mineração grava o resultado do projeto: só quem pode editar a executa
        if (!$this->isGranted('edit', $project)) {
            return $this->redirectToRoute('app_report_theoretical_lenses', ['id' => $id]);
        }
        return $this->render('report/theoretical_lenses_loading.html.twig', [
            'project' => $project,
        ]);
    }

    #[Route('/theoretical-lenses/recalculate', name: 'app_report_theoretical_lenses_recalculate', methods: ['POST'])]
    public function theoreticalLensesRecalculate(int $id, Request $request): Response
    {
        $project = $this->getProject($id);
        $this->denyAccessUnlessGranted('edit', $project);
        if (!$this->isCsrfTokenValid('lenses_recalculate_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de segurança inválido.');
            return $this->redirectToRoute('app_report_theoretical_lenses', ['id' => $id]);
        }
        $this->clearTheoreticalLensesCache($id);
        return $this->redirectToRoute('app_report_theoretical_lenses_loading', ['id' => $id]);
    }

    private function clearTheoreticalLensesCache(int $id): void
    {
        $cacheFile = $this->getParameter('kernel.project_dir') . '/var/theoretical_lenses_cache/project_' . $id . '.json';
        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }
    }

    #[Route('/theoretical-lenses/process-batch', name: 'app_report_theoretical_lenses_process_batch', methods: ['POST'])]
    public function theoreticalLensesProcessBatch(int $id, Request $request): Response
    {
        ini_set('memory_limit', '512M');
        $project = $this->getProject($id);
        // A mineração grava o resultado do projeto e usa CPU: só quem pode editar, e com o token da tela
        $this->denyAccessUnlessGranted('edit', $project);
        if (!$this->isCsrfTokenValid('lenses_batch_' . $id, (string) $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'Token de segurança inválido.'], 403);
        }

        $payload = json_decode($request->getContent(), true) ?? [];
        $step = max(0, (int) ($payload['step'] ?? 0));
        // Teto do lote: um valor enorme leria o corpus inteiro numa requisição
        $batchSize = min(self::LENS_MAX_BATCH, max(1, (int) ($payload['batchSize'] ?? 200)));

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
                    // Documentos distintos com o termo no título/resumo OU nas referências (base do %)
                    'doc_match_count' => 0,
                    'terms_lower' => array_map('strtolower', $terms),
                    'citations_lower' => array_map('strtolower', $citations),
                    // Versões em minúsculas Unicode: o str_contains que antecede a expressão regular
                    // (a regex ignora maiúsculas também em letras acentuadas)
                    'terms_fold' => array_map('mb_strtolower', $terms),
                    'citations_fold' => array_map('mb_strtolower', $citations),
                ];

            }
            
            $session->set('theorists_batch_' . $id, $theorists);
            // Paginação por id (WHERE id > último), não por OFFSET: cada lote custa o mesmo no fim do corpus
            $session->set('theorists_cursor_' . $id, 0);
            $session->set('theorists_done_' . $id, 0);
            // Versão dos dados no começo da mineração: se algo mudar durante ela, o resultado já nasce vencido
            $session->set('theorists_version_' . $id, $this->analyticsCache->currentVersion());
            
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
        
        $cursor = (int) $session->get('theorists_cursor_' . $id, 0);
        $documents = $this->conn->fetchAllAssociative(
            'SELECT d.id, d.title, d.abstract_text, d.year, d.references, d.doi, d.url
             FROM document d
             WHERE d.project_id = ? AND d.id > ?
             ORDER BY d.id
             LIMIT ' . (int)$batchSize,
            [$id, $cursor]
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
                // Auxiliares da pré-checagem: não vão para o resultado guardado
                unset($t['terms_fold'], $t['citations_fold']);
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
                    "SELECT da.document_id, GROUP_CONCAT(a.preferred_name ORDER BY da.position SEPARATOR ', ') AS author_names
                     FROM document_author da
                     JOIN author_identity a ON a.id = da.author_identity_id
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
                'data_version' => $session->get('theorists_version_' . $id),
                'theorists' => $displayTheorists,
                'categories' => $categories,
                'total_docs' => $totalDocs,
                'research_fields' => $researchFields,
            ];
            
            file_put_contents($cacheFile, json_encode($cachedData));
            
            // Clean up session
            $session->remove('theorists_batch_' . $id);
            $session->remove('theorists_cursor_' . $id);
            $session->remove('theorists_done_' . $id);
            $session->remove('theorists_version_' . $id);
            
            return $this->json(['status' => 'completed']);
        }
        
        // Match slice documents against theorists
        foreach ($documents as $doc) {
            $textLower = strtolower(($doc['title'] ?? '') . ' ' . ($doc['abstract_text'] ?? ''));
            $textFold  = mb_strtolower($textLower);
            $allRefsText = '';
            if (!empty($doc['references'])) {
                $refs = json_decode($doc['references'], true);
                if (is_array($refs)) {
                    $allRefsText = strtolower(implode(' || ', $refs));
                }
            }
            $refsFold = $allRefsText === '' ? '' : mb_strtolower($allRefsText);
            
            foreach ($theorists as &$t) {
                $terms = $t['terms_lower'];
                $citations = $t['citations_lower'];
                $termsFold = $t['terms_fold'] ?? $terms;
                $citationsFold = $t['citations_fold'] ?? $citations;
                
                if (empty($terms)) {
                    continue;
                }
                
                // 1. Direct match (Title/Abstract)
                $directMatched = false;
                foreach ($terms as $i => $term) {
                    if ($this->matchTerm($textLower, $term, $textFold, $termsFold[$i] ?? null)) {
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
                    foreach ($citations as $i => $cit) {
                        if ($this->matchTerm($allRefsText, $cit, $refsFold, $citationsFold[$i] ?? null)) {
                            $refMatched = true;
                            break;
                        }
                    }
                }
                
                if (!$refMatched) {
                    foreach ($terms as $i => $term) {
                        if ($this->matchTerm($allRefsText, $term, $refsFold, $termsFold[$i] ?? null)) {
                            $refMatched = true;
                            break;
                        }
                    }
                }
                
                if ($directMatched || $refMatched) {
                    $t['doc_match_count']++;
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
        $done = (int) $session->get('theorists_done_' . $id, 0) + count($documents);
        $session->set('theorists_cursor_' . $id, (int) end($documents)['id']);
        $session->set('theorists_done_' . $id, $done);
        
        return $this->json([
            'status' => 'processing',
            'processedDocs' => $done,
            'nextStep' => $step + 1
        ]);
    }

    #[Route('/theoretical-lenses/add', name: 'app_report_theoretical_lenses_add', methods: ['POST'])]
    public function addTheoreticalLens(int $id, Request $request): Response
    {
        $project = $this->getProject($id);
        // A tabela de lentes é global (vale para todos os projetos): só administradores cadastram
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('lens_add_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de segurança inválido.');
            return $this->redirectToRoute('app_report_theoretical_lenses', ['id' => $project->getId()]);
        }

        $name = trim($request->request->get('name', ''));
        $category = trim($request->request->get('category', ''));
        $researchField = trim($request->request->get('research_field', ''));
        $termsString = trim($request->request->get('terms', ''));
        $description = trim($request->request->get('description', ''));
        // Cor e ícone entram em atributos de estilo e classe: só #RRGGBB e bi-nome (senão, o padrão)
        $icon = preg_match('/^bi-[a-z0-9-]+$/', trim((string) $request->request->get('icon', ''))) === 1
            ? trim((string) $request->request->get('icon')) : 'bi-mortarboard';
        $color = ClassificationController::sanitizeColor((string) $request->request->get('color', ''));

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

        // A nova lente precisa entrar na mineração: descarta o resultado guardado deste projeto
        $this->clearTheoreticalLensesCache($id);

        $this->addFlash('success', sprintf('Lente teórica de "%s" cadastrada com sucesso!', $name));

        return $this->redirectToRoute('app_report_theoretical_lenses', ['id' => $project->getId()]);
    }

    // ── Classification Report ──────────────────────────────────────────────────

    #[Route('/classification', name: 'app_report_classification', methods: ['GET'])]
    public function classificationReport(int $id): Response
    {
        $project = $this->getProject($id);
        $data    = $this->analyticsCache->get($project->getId(), 'report.classification', [],
            fn() => $this->reportService->getClassificationReport($project->getId()));

        return $this->render('report/classification_report.html.twig', [
            'project' => $project,
            'data'    => $data,
        ]);
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    // ── Glossário de Termos ────────────────────────────────────────────────────

    /** Conceitos por página no glossário. */
    private const GLOSSARY_PER_PAGE = 60;

    #[Route('/glossary', name: 'app_report_glossary', methods: ['GET'])]
    public function glossary(int $id, Request $request): Response
    {
        $project = $this->getProject($id);

        $search = trim((string) $request->query->get('q', ''));
        $letter = trim((string) $request->query->get('letter', ''));
        $page   = max(1, (int) $request->query->get('page', 1));

        // O resultado depende só do projeto, da busca, da letra e da página
        $payload = ($search !== '' && mb_strlen($search) > 100) || mb_strlen($letter) > 3
            ? $this->glossaryData($id, $search, $letter, $page)
            : $this->analyticsCache->get($id, 'report.glossary', ['q' => $search, 'letter' => $letter, 'page' => $page],
                fn() => $this->glossaryData($id, $search, $letter, $page));
        $letters           = $payload['letters'];
        $letter            = $payload['letter'];
        $concepts          = $payload['concepts'];
        $labelsByConceptId = $payload['labelsByConceptId'];
        $totalConcepts     = $payload['totalConcepts'];
        $totalVariants     = $payload['totalVariants'];
        $page              = $payload['page'];
        $totalPages        = $payload['totalPages'];

        return $this->render('report/glossary.html.twig', [
            'project' => $project,
            'concepts' => $concepts,
            'labelsByConceptId' => $labelsByConceptId,
            'letters' => $letters,
            'currentLetter' => $letter,
            'search' => $search,
            'totalConcepts' => $totalConcepts,
            'totalVariants' => $totalVariants,
            'page' => $page,
            'totalPages' => $totalPages,
            'perPage' => self::GLOSSARY_PER_PAGE,
        ]);
    }

    /**
     * Cache de uma análise com busca de texto. Busca longa não vai para o cache: o texto é livre e cada
     * valor distinto criaria uma entrada nova.
     *
     * @param array<string,mixed> $params
     */
    private function cachedSearch(int $projectId, string $name, array $params, string $search, callable $compute): mixed
    {
        $search = trim($search);
        if (mb_strlen($search) > 100) {
            return $compute();
        }

        return $this->analyticsCache->get($projectId, $name, $params + ['q' => $search], $compute);
    }

    /**
     * Cálculo do glossário (conceitos do tesauro do projeto, filtrados e paginados).
     *
     * @return array{letters: array, letter: string, concepts: array, labelsByConceptId: array, totalConcepts: int, totalVariants: int, page: int, totalPages: int}
     */
    private function glossaryData(int $id, string $search, string $letter, int $page): array
    {
        // Letras disponíveis (conceitos ligados a palavras-chave deste projeto)
        $letters = $this->conn->fetchFirstColumn(
            "SELECT DISTINCT UPPER(LEFT(tc.preferred_label, 1)) AS letter
             FROM thesaurus_concept tc
             JOIN keyword k ON k.thesaurus_concept_id = tc.id
             JOIN document_keyword dk ON dk.keyword_id = k.id
             JOIN document d ON d.id = dk.document_id AND d.project_id = ?
             WHERE tc.scheme_id = (SELECT id FROM thesaurus_scheme WHERE slug = 'keyword' LIMIT 1)
             ORDER BY letter",
            [$id]
        );

        // Abre na primeira letra: o projeto inteiro de uma vez só com "Todos" (letter=all) ou numa busca
        if ($letter === '' && $search === '' && $letters) {
            $letter = (string) $letters[0];
        }
        $allLetters = $letter === 'all';

        // 1. Só os ids dos conceitos que atendem ao filtro (com o nº de variações, para os totais)
        $sql = "
            SELECT tc.id, COUNT(DISTINCT k.id) AS variant_count
            FROM thesaurus_concept tc
            JOIN keyword k ON k.thesaurus_concept_id = tc.id
            JOIN document_keyword dk ON dk.keyword_id = k.id
            JOIN document d ON d.id = dk.document_id AND d.project_id = ?
            WHERE tc.scheme_id = (SELECT id FROM thesaurus_scheme WHERE slug = 'keyword' LIMIT 1)
        ";
        $params = [$id];

        if ($search !== '') {
            $sql .= " AND (tc.preferred_label LIKE ? OR tc.normalized_label LIKE ?)";
            $params[] = '%' . $search . '%';
            $params[] = '%' . strtolower($search) . '%';
        }

        if ($letter !== '' && !$allLetters) {
            $sql .= " AND tc.preferred_label LIKE ?";
            $params[] = $letter . '%';
        }

        $sql .= " GROUP BY tc.id ORDER BY tc.preferred_label ASC, tc.id ASC";
        $matches = $this->conn->fetchAllAssociative($sql, $params);

        $totalConcepts = count($matches);
        $totalVariants = (int) array_sum(array_column($matches, 'variant_count'));
        $totalPages    = max(1, (int) ceil($totalConcepts / self::GLOSSARY_PER_PAGE));
        $page          = min($page, $totalPages);
        $pageIds       = array_map(
            fn($m) => (int) $m['id'],
            array_slice($matches, ($page - 1) * self::GLOSSARY_PER_PAGE, self::GLOSSARY_PER_PAGE)
        );

        // 2. Os números e os rótulos só dos conceitos da página
        $concepts = [];
        $labelsByConceptId = [];
        if ($pageIds) {
            $idList = implode(',', $pageIds);
            // Citações: soma uma vez por (conceito, documento); somar sobre as linhas
            // variante × documento contaria em dobro o documento com duas variantes.
            $concepts = $this->conn->fetchAllAssociative(
                "SELECT
                    tc.id,
                    tc.preferred_label,
                    tc.normalized_label,
                    COUNT(DISTINCT dk.document_id) AS doc_count,
                    COUNT(DISTINCT k.id) AS variant_count,
                    COALESCE(MAX(cc.total_citations), 0) AS total_citations
                FROM thesaurus_concept tc
                LEFT JOIN (
                    SELECT t.cid, SUM(t.cit) AS total_citations
                    FROM (
                        SELECT DISTINCT k2.thesaurus_concept_id AS cid, d2.id, COALESCE(d2.cited_by, 0) AS cit
                        FROM keyword k2
                        JOIN document_keyword dk2 ON dk2.keyword_id = k2.id
                        JOIN document d2 ON d2.id = dk2.document_id AND d2.project_id = ?
                        WHERE k2.thesaurus_concept_id IN ($idList)
                    ) t
                    GROUP BY t.cid
                ) cc ON cc.cid = tc.id
                JOIN keyword k ON k.thesaurus_concept_id = tc.id
                JOIN document_keyword dk ON dk.keyword_id = k.id
                JOIN document d ON d.id = dk.document_id AND d.project_id = ?
                WHERE tc.id IN ($idList)
                GROUP BY tc.id, tc.preferred_label, tc.normalized_label
                ORDER BY tc.preferred_label ASC, tc.id ASC",
                [$id, $id]
            );

            $labels = $this->conn->fetchAllAssociative(
                "SELECT concept_id, label, type FROM thesaurus_label WHERE concept_id IN ($idList) ORDER BY type ASC, label ASC"
            );
            foreach ($labels as $l) {
                $labelsByConceptId[$l['concept_id']][] = $l;
            }
        }

        return [
            'letters' => $letters,
            'letter' => $letter,
            'concepts' => $concepts,
            'labelsByConceptId' => $labelsByConceptId,
            'totalConcepts' => $totalConcepts,
            'totalVariants' => $totalVariants,
            'page' => $page,
            'totalPages' => $totalPages,
        ];
    }

    private function getProject(int $id): BibliometricProject
    {
        $project = $this->em->getRepository(BibliometricProject::class)->find($id);
        if (!$project) {
            throw $this->createNotFoundException();
        }
        // Leitura: dono ou projeto público (ProjectVoter). Ações de escrita checam 'edit'.
        $this->denyAccessUnlessGranted('view', $project);
        return $project;
    }

    /**
     * Palavra inteira (com acentos e hífen), sem diferenciar maiúsculas. O str_contains em minúsculas
     * Unicode ($textFold/$termFold) descarta de graça a maioria dos pares antes da expressão regular;
     * a regex continua decidindo, então o resultado é o mesmo de antes.
     */
    private function matchTerm(string $text, string $term, ?string $textFold = null, ?string $termFold = null): bool
    {
        if ($textFold !== null && $termFold !== null && !str_contains($textFold, $termFold)) {
            return false;
        }
        $termEscaped = preg_quote($term, '/');
        // Match exact word boundary, supporting accented characters and hyphens
        $pattern = '/(?<=^|[^a-zA-Z0-9áéíóúâêôçãõà\-])' . $termEscaped . '(?=$|[^a-zA-Z0-9áéíóúâêôçãõà\-])/iu';
        return (bool) preg_match($pattern, $text);
    }
}
