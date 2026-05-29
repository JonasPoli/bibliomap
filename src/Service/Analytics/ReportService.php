<?php

namespace App\Service\Analytics;

use Doctrine\DBAL\Connection;

class ReportService
{
    public function __construct(private readonly Connection $conn) {}

    // ── 1. Authors Report ─────────────────────────────────────────────────────

    public function getAuthorsReport(int $projectId, int $limit = 100): array
    {
        // 1. Fetch per-author aggregates (all authors — needed for Lotka)
        $authors = $this->conn->fetchAllAssociative(
            'SELECT a.id, a.name,
                    COUNT(DISTINCT da.document_id) AS doc_count,
                    SUM(COALESCE(d.cited_by, 0))   AS citation_count,
                    ROUND(AVG(COALESCE(d.cited_by, 0)), 1) AS avg_citations,
                    MIN(d.year) AS first_year,
                    MAX(d.year) AS last_year
             FROM author a
             JOIN document_author da ON a.id = da.author_id
             JOIN document d         ON d.id = da.document_id AND d.project_id = ?
             GROUP BY a.id, a.name
             ORDER BY doc_count DESC, citation_count DESC',
            [$projectId]
        );

        // 2. Fetch ALL per-author per-document citations in ONE query
        //    keyed by author_id → sorted array of citation counts (DESC)
        $allCitRows = $this->conn->fetchAllAssociative(
            'SELECT da.author_id, COALESCE(d.cited_by, 0) AS cited_by
             FROM document d
             JOIN document_author da ON da.document_id = d.id
             WHERE d.project_id = ?
             ORDER BY da.author_id, d.cited_by DESC',
            [$projectId]
        );

        // Group into a map: author_id → [cit1, cit2, ...]
        $citsByAuthor = [];
        foreach ($allCitRows as $row) {
            $citsByAuthor[(int)$row['author_id']][] = (int)$row['cited_by'];
        }
        unset($allCitRows); // free memory immediately

        // 3. Assign H/G-index to each author
        foreach ($authors as &$auth) {
            $cits = $citsByAuthor[(int)$auth['id']] ?? [];
            $auth['h_index'] = $this->calcHIndex($cits);
            $auth['g_index'] = $this->calcGIndex($cits);
        }
        unset($auth, $citsByAuthor);

        // 4. Build Lotka distribution: how many authors have exactly N docs
        $lotkaObserved = [];
        foreach ($authors as $auth) {
            $n = (int) $auth['doc_count'];
            $lotkaObserved[$n] = ($lotkaObserved[$n] ?? 0) + 1;
        }
        ksort($lotkaObserved);

        // Lotka expected: A₁ / n²
        $a1 = $lotkaObserved[1] ?? 0;
        $lotkaExpected = [];
        foreach (array_keys($lotkaObserved) as $n) {
            $lotkaExpected[$n] = ($n > 0 && $a1 > 0) ? round($a1 / ($n * $n), 1) : 0;
        }

        // Limit list for display
        $authorsList = array_slice($authors, 0, $limit);

        // 5. KPIs
        $totalAuthors = count($authors);

        $topAuthor    = $authorsList[0] ?? null;

        return [
            'list'           => $authorsList,
            'lotka_observed' => $lotkaObserved,
            'lotka_expected' => $lotkaExpected,
            'kpis'           => [
                'total_authors'   => $totalAuthors,
                'top_author'      => $topAuthor ? $topAuthor['name'] : 'N/A',
                'top_author_docs' => $topAuthor ? (int)$topAuthor['doc_count'] : 0,
            ]
        ];
    }

    /** H-index: largest h such that h papers have ≥ h citations (array must be sorted DESC) */
    private function calcHIndex(array $citationsSortedDesc): int
    {
        $h = 0;
        foreach ($citationsSortedDesc as $i => $cit) {
            if ($cit >= ($i + 1)) {
                $h = $i + 1;
            } else {
                break;
            }
        }
        return $h;
    }

    /** G-index: largest g such that top g papers collectively have ≥ g² citations */
    private function calcGIndex(array $citationsSortedDesc): int
    {
        $cumulative = 0;
        $g = 0;
        foreach ($citationsSortedDesc as $i => $cit) {
            $cumulative += (int) $cit;
            $rank = $i + 1;
            if ($cumulative >= $rank * $rank) {
                $g = $rank;
            }
        }
        return $g;
    }

    // ── 2. Sources Report ─────────────────────────────────────────────────────

    public function getSourcesReport(int $projectId, int $limit = 100): array
    {
        // NOTE: LIMIT must be inlined as int (DBAL binds ? as string, MySQL rejects string LIMIT)
        $sources = $this->conn->fetchAllAssociative(
            "SELECT source_title,
                    COUNT(*) AS doc_count,
                    SUM(COALESCE(cited_by, 0)) AS citation_count,
                    ROUND(AVG(COALESCE(cited_by, 0)), 1) AS avg_citations,
                    MIN(year) AS first_year,
                    MAX(year) AS last_year
             FROM document
             WHERE project_id = ? AND source_title IS NOT NULL AND source_title != ''
             GROUP BY source_title
             ORDER BY doc_count DESC
             LIMIT {$limit}",
            [$projectId]
        );

        $totalSources = (int) $this->conn->fetchOne(
            "SELECT COUNT(DISTINCT source_title) FROM document WHERE project_id = ? AND source_title IS NOT NULL AND source_title != ''",
            [$projectId]
        );

        $topSource = !empty($sources) ? $sources[0] : null;

        // ── Bradford's Law zones ──────────────────────────────────────────────
        $totalDocs = array_sum(array_column($sources, 'doc_count'));
        $bradford  = $this->calcBradfordZones($sources, (int)$totalDocs);

        return [
            'list'     => $sources,
            'bradford' => $bradford,
            'kpis'     => [
                'total_sources'   => $totalSources,
                'top_source'      => $topSource ? $topSource['source_title'] : 'N/A',
                'top_source_docs' => $topSource ? (int)$topSource['doc_count'] : 0,
            ]
        ];
    }

    /**
     * Bradford's Law: divide journals into 3 zones of ~equal document output.
     */
    private function calcBradfordZones(array $sources, int $totalDocs): array
    {
        if ($totalDocs === 0 || empty($sources)) {
            return ['zone1' => [], 'zone2' => [], 'zone3' => [], 'target_per_zone' => 0];
        }

        $target   = intdiv($totalDocs, 3);
        $zone     = 1;
        $cumul    = 0;
        $zones    = ['zone1' => [], 'zone2' => [], 'zone3' => []];
        $zoneKeys = ['zone1', 'zone2', 'zone3'];

        foreach ($sources as $s) {
            if ($zone < 3 && $cumul >= $target * $zone) {
                $zone++;
            }
            $zones[$zoneKeys[$zone - 1]][] = [
                'source_title' => $s['source_title'],
                'doc_count'    => (int) $s['doc_count'],
            ];
            $cumul += (int) $s['doc_count'];
        }

        return array_merge($zones, ['target_per_zone' => $target]);
    }

    // ── 3. Documents Report ───────────────────────────────────────────────────

    public function getDocumentsReport(int $projectId, int $limit = 100): array
    {
        $documents = $this->conn->fetchAllAssociative(
            "SELECT d.id, d.title, d.year, d.source_title, d.doi, COALESCE(d.cited_by, 0) AS cited_by,
                    (SELECT GROUP_CONCAT(a.name ORDER BY da.position ASC SEPARATOR '; ')
                     FROM author a
                     JOIN document_author da ON a.id = da.author_id
                     WHERE da.document_id = d.id) AS authors_str
             FROM document d
             WHERE d.project_id = ? AND d.title IS NOT NULL AND d.title != ''
             ORDER BY cited_by DESC
             LIMIT {$limit}",
            [$projectId]
        );

        $kpis = $this->conn->fetchAssociative(
            'SELECT COUNT(*) AS total_docs,
                    SUM(COALESCE(cited_by, 0)) AS total_citations,
                    ROUND(AVG(COALESCE(cited_by, 0)), 1) AS avg_citations,
                    MAX(COALESCE(cited_by, 0)) AS max_citations
             FROM document
             WHERE project_id = ?',
            [$projectId]
        );

        return [
            'list' => $documents,
            'kpis' => $kpis ?: [
                'total_docs'      => 0,
                'total_citations' => 0,
                'avg_citations'   => 0,
                'max_citations'   => 0,
            ]
        ];
    }

    // ── 4. Keywords Report ────────────────────────────────────────────────────

    public function getKeywordsReport(int $projectId, int $limit = 150): array
    {
        $keywords = $this->conn->fetchAllAssociative(
            "SELECT k.term, k.type, COUNT(dk.document_id) AS freq,
                    MIN(d.year) AS first_year, MAX(d.year) AS last_year
             FROM keyword k
             JOIN document_keyword dk ON k.id = dk.keyword_id
             JOIN document d         ON dk.document_id = d.id AND d.project_id = ?
             GROUP BY k.id, k.term, k.type
             ORDER BY freq DESC
             LIMIT {$limit}",
            [$projectId]
        );

        $summary = $this->conn->fetchAssociative(
            "SELECT COUNT(DISTINCT CASE WHEN k.type = 'author'  THEN k.id END) AS author_kw_count,
                    COUNT(DISTINCT CASE WHEN k.type = 'indexed' THEN k.id END) AS indexed_kw_count
             FROM keyword k
             JOIN document_keyword dk ON k.id = dk.keyword_id
             JOIN document d         ON dk.document_id = d.id AND d.project_id = ?",
            [$projectId]
        );

        return [
            'list' => $keywords,
            'kpis' => [
                'total_keywords'   => ((int)($summary['author_kw_count'] ?? 0)) + ((int)($summary['indexed_kw_count'] ?? 0)),
                'author_keywords'  => (int)($summary['author_kw_count']  ?? 0),
                'indexed_keywords' => (int)($summary['indexed_kw_count'] ?? 0),
            ]
        ];
    }

    // ── 5. Countries Report ───────────────────────────────────────────────────

    public function getCountriesReport(int $projectId): array
    {
        $rows = $this->conn->fetchAllAssociative(
            'SELECT countries, COALESCE(cited_by, 0) AS cited_by
             FROM document
             WHERE project_id = ? AND countries IS NOT NULL',
            [$projectId]
        );

        $countryCounts = [];
        foreach ($rows as $row) {
            $countries = json_decode($row['countries'], true);
            if (is_array($countries)) {
                foreach ($countries as $c) {
                    $c = trim($c);
                    if ($c === '') continue;
                    if (!isset($countryCounts[$c])) {
                        $countryCounts[$c] = ['country' => $c, 'doc_count' => 0, 'citation_count' => 0];
                    }
                    $countryCounts[$c]['doc_count']++;
                    $countryCounts[$c]['citation_count'] += (int)$row['cited_by'];
                }
            }
        }

        foreach ($countryCounts as &$cc) {
            $cc['avg_citations'] = $cc['doc_count'] > 0 ? round($cc['citation_count'] / $cc['doc_count'], 1) : 0;
        }
        unset($cc);

        usort($countryCounts, fn($a, $b) => $b['doc_count'] <=> $a['doc_count']);

        $totalCountries = count($countryCounts);
        $topCountry     = $totalCountries > 0 ? $countryCounts[0]['country']   : 'N/A';
        $topCountryDocs = $totalCountries > 0 ? $countryCounts[0]['doc_count'] : 0;

        return [
            'list' => $countryCounts,
            'kpis' => [
                'total_countries'  => $totalCountries,
                'top_country'      => $topCountry,
                'top_country_docs' => $topCountryDocs,
            ]
        ];
    }

    // ── 6. Institutions Report ────────────────────────────────────────────────

    public function getInstitutionsReport(int $projectId): array
    {
        $rows = $this->conn->fetchAllAssociative(
            'SELECT institutions, COALESCE(cited_by, 0) AS cited_by
             FROM document
             WHERE project_id = ? AND institutions IS NOT NULL',
            [$projectId]
        );

        $instCounts = [];
        foreach ($rows as $row) {
            $institutions = json_decode($row['institutions'], true);
            if (is_array($institutions)) {
                foreach ($institutions as $inst) {
                    $inst = trim($inst);
                    if ($inst === '') continue;
                    if (!isset($instCounts[$inst])) {
                        $instCounts[$inst] = ['institution' => $inst, 'doc_count' => 0, 'citation_count' => 0];
                    }
                    $instCounts[$inst]['doc_count']++;
                    $instCounts[$inst]['citation_count'] += (int)$row['cited_by'];
                }
            }
        }

        foreach ($instCounts as &$ic) {
            $ic['avg_citations'] = $ic['doc_count'] > 0 ? round($ic['citation_count'] / $ic['doc_count'], 1) : 0;
        }
        unset($ic);

        usort($instCounts, fn($a, $b) => $b['doc_count'] <=> $a['doc_count']);

        $totalInst   = count($instCounts);
        $topInst     = $totalInst > 0 ? $instCounts[0]['institution'] : 'N/A';
        $topInstDocs = $totalInst > 0 ? $instCounts[0]['doc_count']   : 0;

        return [
            'list' => array_slice($instCounts, 0, 150),
            'kpis' => [
                'total_institutions'   => $totalInst,
                'top_institution'      => $topInst,
                'top_institution_docs' => $topInstDocs,
            ]
        ];
    }

    // ── 7. General Summary Report ─────────────────────────────────────────────

    public function getGeneralReport(int $projectId): array
    {
        // Overall KPIs
        $kpis = $this->conn->fetchAssociative(
            'SELECT COUNT(*) AS total_docs,
                    SUM(COALESCE(cited_by, 0)) AS total_citations,
                    ROUND(AVG(COALESCE(cited_by, 0)), 1) AS avg_citations,
                    MAX(COALESCE(cited_by, 0)) AS max_citations,
                    MIN(year) AS first_year,
                    MAX(year) AS last_year,
                    COUNT(DISTINCT source_title) AS total_sources
             FROM document WHERE project_id = ?',
            [$projectId]
        );

        $kpis['total_authors'] = (int) $this->conn->fetchOne(
            'SELECT COUNT(DISTINCT da.author_id) FROM document_author da
             JOIN document d ON d.id = da.document_id AND d.project_id = ?',
            [$projectId]
        );

        $kpis['total_keywords'] = (int) $this->conn->fetchOne(
            'SELECT COUNT(DISTINCT k.id) FROM keyword k
             JOIN document_keyword dk ON k.id = dk.keyword_id
             JOIN document d ON dk.document_id = d.id AND d.project_id = ?',
            [$projectId]
        );

        // Annual production
        $annual = $this->conn->fetchAllAssociative(
            'SELECT year, COUNT(*) AS doc_count, SUM(COALESCE(cited_by,0)) AS citation_count
             FROM document WHERE project_id = ? AND year IS NOT NULL
             GROUP BY year ORDER BY year ASC',
            [$projectId]
        );

        // Top 5 authors
        $topAuthors = $this->conn->fetchAllAssociative(
            'SELECT a.name, COUNT(DISTINCT da.document_id) AS doc_count,
                    SUM(COALESCE(d.cited_by, 0)) AS citation_count
             FROM author a
             JOIN document_author da ON a.id = da.author_id
             JOIN document d ON d.id = da.document_id AND d.project_id = ?
             GROUP BY a.id, a.name
             ORDER BY doc_count DESC LIMIT 10',
            [$projectId]
        );

        // Top 5 sources
        $topSources = $this->conn->fetchAllAssociative(
            "SELECT source_title, COUNT(*) AS doc_count,
                    SUM(COALESCE(cited_by,0)) AS citation_count
             FROM document
             WHERE project_id = ? AND source_title IS NOT NULL AND source_title != ''
             GROUP BY source_title ORDER BY doc_count DESC LIMIT 10",
            [$projectId]
        );

        // Top 5 keywords
        $topKeywords = $this->conn->fetchAllAssociative(
            'SELECT k.term, k.type, COUNT(dk.document_id) AS freq
             FROM keyword k
             JOIN document_keyword dk ON k.id = dk.keyword_id
             JOIN document d ON dk.document_id = d.id AND d.project_id = ?
             GROUP BY k.id, k.term, k.type
             ORDER BY freq DESC LIMIT 20',
            [$projectId]
        );

        // Top 5 documents by citations
        $topDocs = $this->conn->fetchAllAssociative(
            "SELECT d.title, d.year, d.source_title, d.doi,
                    COALESCE(d.cited_by, 0) AS cited_by,
                    (SELECT GROUP_CONCAT(a.name ORDER BY da2.position ASC SEPARATOR '; ')
                     FROM author a JOIN document_author da2 ON a.id = da2.author_id
                     WHERE da2.document_id = d.id) AS authors_str
             FROM document d
             WHERE d.project_id = ? AND d.title IS NOT NULL AND d.title != ''
             ORDER BY cited_by DESC LIMIT 10",
            [$projectId]
        );

        // Countries
        $countryData = $this->getCountriesReport($projectId);
        $topCountries = array_slice($countryData['list'], 0, 10);

        return [
            'kpis'        => $kpis,
            'annual'      => $annual,
            'topAuthors'  => $topAuthors,
            'topSources'  => $topSources,
            'topKeywords' => $topKeywords,
            'topDocs'     => $topDocs,
            'topCountries'=> $topCountries,
        ];
    }
}
