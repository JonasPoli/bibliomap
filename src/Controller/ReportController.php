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

    #[Route('/theoretical-lenses', name: 'app_report_theoretical_lenses', methods: ['GET'])]
    public function theoreticalLenses(int $id): Response
    {
        $project = $this->getProject($id);

        // Fetch all documents with title and abstract for text-mining
        $documents = $this->conn->fetchAllAssociative(
            'SELECT d.id, d.title, d.abstract_text, d.year, 
                    (SELECT GROUP_CONCAT(a.name SEPARATOR ", ") 
                     FROM author a 
                     JOIN document_author da ON da.author_id = a.id 
                     WHERE da.document_id = d.id) AS author_names
             FROM document d
             WHERE d.project_id = ?',
            [$id]
        );

        $theorists = [
            'latour' => [
                'name' => 'Bruno Latour',
                'category' => 'Teoria Ator-Rede e Construtivismo',
                'terms' => ['latour', 'actor-network', 'ator-rede', 'non-human', 'não-humano', 'translation theory', 'teoria da tradução', 'actant', 'actante', 'simetria'],
                'description' => 'Foca nas redes sociotécnicas formadas simetricamente por atores humanos e não humanos. Ideal para analisar o chatbot (ator não humano) e o extensionista rural (ator humano) agindo em coprodução de conhecimento.',
                'icon' => 'bi-diagram-3-fill',
                'color' => 'var(--bm-accent)',
                'match_count' => 0,
                'docs' => [],
            ],
            'callon' => [
                'name' => 'Michel Callon',
                'category' => 'Teoria Ator-Rede e Construtivismo',
                'terms' => ['callon', 'sociotechnic', 'sociotécnico', 'problematization', 'problematização', 'interessement', 'interessamento', 'enrolment', 'recrutamento'],
                'description' => 'Estuda os processos de tradução de interesses e controvérsias em redes sociotécnicas. Ajuda a investigar como os extensionistas rurais aceitam, negociam ou resistem à introdução de um chatbot na sua rotina de trabalho.',
                'icon' => 'bi-arrow-left-right',
                'color' => '#4f8ef7',
                'match_count' => 0,
                'docs' => [],
            ],
            'bijker' => [
                'name' => 'Wiebe Bijker',
                'category' => 'Teoria Ator-Rede e Construtivismo',
                'terms' => ['bijker', 'social construction of technology', 'construção social da tecnologia', 'scot', 'relevant social groups', 'grupos sociais relevantes', 'interpretative flexibility', 'flexibilidade interpretativa', 'technological frame', 'quadro tecnológico'],
                'description' => 'Trabalha na Construção Social da Tecnologia (SCOT). Excelente para entender a "flexibilidade interpretativa" do chatbot: o que a tecnologia significa para o desenvolvedor vs. o que ela significa para o extensionista do campo.',
                'icon' => 'bi-shuffle',
                'color' => '#5da5da',
                'match_count' => 0,
                'docs' => [],
            ],
            'pinch' => [
                'name' => 'Trevor Pinch',
                'category' => 'Teoria Ator-Rede e Construtivismo',
                'terms' => ['pinch', 'social construction of facts', 'construção social dos fatos', 'stabilization', 'estabilização', 'closure', 'fechamento interpretativo'],
                'description' => 'Estuda a construção social dos fatos científicos e como as controvérsias em torno de novas tecnologias se estabilizam e fecham na sociedade.',
                'icon' => 'bi-lock-fill',
                'color' => '#3d5a80',
                'match_count' => 0,
                'docs' => [],
            ],
            'foucault' => [
                'name' => 'Michel Foucault',
                'category' => 'Sociologia e Filosofia Crítica',
                'terms' => ['foucault', 'power relations', 'relações de poder', 'discourse analysis', 'análise do discurso', 'biopower', 'biopoder', 'surveillance', 'vigilância', 'panopticon', 'panóptico', 'archeology of knowledge', 'arqueologia do saber'],
                'description' => 'Analisa o poder descentralizado, o discurso e formas de controle. Ideal se a sua dissertação pretende discutir como o chatbot atua como um dispositivo de poder, vigilância do trabalho ou direcionamento do conhecimento rural.',
                'icon' => 'bi-eye-fill',
                'color' => 'var(--bm-warning)',
                'match_count' => 0,
                'docs' => [],
            ],
            'bourdieu' => [
                'name' => 'Pierre Bourdieu',
                'category' => 'Sociologia e Filosofia Crítica',
                'terms' => ['bourdieu', 'habitus', 'social field', 'campo social', 'social capital', 'capital social', 'cultural capital', 'capital cultural', 'symbolic violence', 'violência simbólica'],
                'description' => 'Fornece a ótica de campo, habitus e capitais. Útil para investigar se os extensionistas com maior capital tecnológico ou cultural incorporam o chatbot de forma distinta, alterando as relações de poder no campo institucional da extensão rural.',
                'icon' => 'bi-award-fill',
                'color' => '#f28f3b',
                'match_count' => 0,
                'docs' => [],
            ],
            'marx' => [
                'name' => 'Karl Marx',
                'category' => 'Sociologia e Filosofia Crítica',
                'terms' => ['marx', 'capitalism', 'capitalismo', 'alienation', 'alienação', 'means of production', 'meios de produção', 'proletarianization', 'proletarização', 'labor force', 'força de trabalho'],
                'description' => 'Foca no trabalho, exploração e tecnologia como meio de controle da força produtiva. Ajuda a discutir se a automação da extensão rural via IA representa uma forma de alienação ou de otimização das forças produtivas agrícolas.',
                'icon' => 'bi-hammer',
                'color' => 'var(--bm-danger)',
                'match_count' => 0,
                'docs' => [],
            ],
            'habermas' => [
                'name' => 'Jürgen Habermas',
                'category' => 'Sociologia e Filosofia Crítica',
                'terms' => ['habermas', 'communicative action', 'ação comunicativa', 'public sphere', 'esfera pública', 'communicative rationality', 'racionalidade comunicativa'],
                'description' => 'Analisa a ação comunicativa e racionalidade do diálogo. Ideal para discutir a qualidade e a ética da comunicação entre o extensionista (humano) e o chatbot (sistema automatizado) a nível linguístico.',
                'icon' => 'bi-chat-quote-fill',
                'color' => '#9e2a2b',
                'match_count' => 0,
                'docs' => [],
            ],
            'dagnino' => [
                'name' => 'Renato Dagnino',
                'category' => 'Pensamento Latino-Americano em CTS',
                'terms' => ['dagnino', 'sociotechnical adequacy', 'adequação sociotécnica', 'social technology', 'tecnologia social', 'technological decision', 'decisão tecnológica', 'popular solidarity economy', 'economia solidária'],
                'description' => 'Principal expoente brasileiro do PLACTS. Discute a "Adequação Sociotécnica" das tecnologias. Essencial para analisar se um chatbot (tecnologia convencional/norte-americana) pode ser readequado sociotécnica e localmente para apoiar a agricultura familiar brasileira e assentamentos.',
                'icon' => 'bi-brightness-high-fill',
                'color' => 'var(--bm-success)',
                'match_count' => 0,
                'docs' => [],
            ],
            'herrera' => [
                'name' => 'Amílcar Herrera',
                'category' => 'Pensamento Latino-Americano em CTS',
                'terms' => ['herrera', 'scientific policy', 'política científica', 'explicit policy', 'política explícita', 'implicit policy', 'política implícita', 'latin american scientific project', 'projeto científico latino-americano'],
                'description' => 'Foca nas políticas de ciência e tecnologia implícitas vs. explícitas nos países em desenvolvimento. Excelente se a sua pesquisa avalia se a adoção de IA na extensão rural atende a uma política de desenvolvimento nacional ou apenas a interesses corporativos externos.',
                'icon' => 'bi-bank',
                'color' => '#2b9348',
                'match_count' => 0,
                'docs' => [],
            ],
            'varsavsky' => [
                'name' => 'Oscar Varsavsky',
                'category' => 'Pensamento Latino-Americano em CTS',
                'terms' => ['varsavsky', 'scientific rebellion', 'rebeldia científica', 'standard science', 'ciência padronizada', 'national science', 'ciência nacional', 'politicized science', 'ciência politizada'],
                'description' => 'Crítica à "ciência padrão" e propõe uma ciência com compromisso político social focada em resolver os problemas do povo e do território. Perfeito para defender a criação de um chatbot voltado a problemas rurais locais específicos de pequenos produtores, contra a IA comercial genérica.',
                'icon' => 'bi-shield-fire',
                'color' => '#80b918',
                'match_count' => 0,
                'docs' => [],
            ],
            'kuhn' => [
                'name' => 'Thomas Kuhn',
                'category' => 'Filosofia e História da Ciência',
                'terms' => ['kuhn', 'scientific paradigm', 'paradigma científico', 'scientific revolution', 'revolução científica', 'normal science', 'ciência normal', 'incommensurability', 'incomensurabilidade'],
                'description' => 'Foca em paradigmas e revoluções. Ajuda a analisar se a introdução de inteligência artificial generativa (chatbots) na extensão rural representa uma "ruptura de paradigma" no modo clássico de transferência de tecnologia.',
                'icon' => 'bi-infinity',
                'color' => '#f0883e',
                'match_count' => 0,
                'docs' => [],
            ],
            'haraway' => [
                'name' => 'Donna Haraway',
                'category' => 'Filosofia e História da Ciência',
                'terms' => ['haraway', 'cyborg', 'ciborgue', 'situated knowledges', 'saberes localizados', 'companion species', 'espécies companheiras', 'feminist epistemology', 'epistemologia feminista'],
                'description' => 'Traz a perspectiva de saberes localizados e a figura do ciborgue (híbrido humano-máquina). Excelente para discutir a simbiose entre o extensionista e o chatbot como uma "entidade híbrida" geradora de saberes contextualizados e adaptados à realidade rural.',
                'icon' => 'bi-gender-female',
                'color' => '#e0aaff',
                'match_count' => 0,
                'docs' => [],
            ],
        ];

        // Text mining loop
        foreach ($documents as $doc) {
            $textToSearch = strtolower(
                $doc['title'] . ' ' . 
                ($doc['abstract_text'] ?? '')
            );

            foreach ($theorists as $key => &$t) {
                $matched = false;
                foreach ($t['terms'] as $term) {
                    if (str_contains($textToSearch, $term)) {
                        $matched = true;
                        break;
                    }
                }

                if ($matched) {
                    $t['match_count']++;
                    if (count($t['docs']) < 5) {
                        $t['docs'][] = [
                            'id' => $doc['id'],
                            'title' => $doc['title'],
                            'year' => $doc['year'],
                            'authors' => $doc['author_names'] ?? 'Autores desconhecidos',
                        ];
                    }
                }
            }
        }
        unset($t);

        // Sort theorists by match_count descending
        uasort($theorists, function ($a, $b) {
            return $b['match_count'] <=> $a['match_count'];
        });

        // Group by category for visual cards
        $categories = [];
        foreach ($theorists as $key => $t) {
            $categories[$t['category']][] = array_merge($t, ['key' => $key]);
        }

        return $this->render('report/theoretical_lenses.html.twig', [
            'project'    => $project,
            'theorists'  => $theorists,
            'categories' => $categories,
            'total_docs' => count($documents),
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
