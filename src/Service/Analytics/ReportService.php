<?php

namespace App\Service\Analytics;

use Doctrine\DBAL\Connection;

class ReportService
{
    public function __construct(private readonly Connection $conn) {}

    // ── 1. Authors Report ─────────────────────────────────────────────────────

    public function getAuthorsReport(int $projectId, int $limit = 100, ?string $search = null): array
    {
        // 1. Fetch ALL authors for Lotka distribution & KPIs (always unfiltered)
        $allAuthors = $this->conn->fetchAllAssociative(
            'SELECT a.id, a.name,
                    COUNT(DISTINCT da.document_id) AS doc_count,
                    SUM(COALESCE(d.cited_by, 0))   AS citation_count
             FROM author a
             JOIN document_author da ON a.id = da.author_id
             JOIN document d         ON d.id = da.document_id AND d.project_id = ?
             GROUP BY a.id, a.name',
            [$projectId]
        );

        // 2. Fetch the target authors list (for the table)
        // If search is active, filter by name. Otherwise, just use all authors (and we will slice later).
        $params = [$projectId];
        $searchSql = '';
        if ($search !== null && trim($search) !== '') {
            $searchSql = ' AND a.name LIKE ?';
            $params[] = '%' . trim($search) . '%';
        }

        $listAuthors = $this->conn->fetchAllAssociative(
            'SELECT a.id, a.name,
                    COUNT(DISTINCT da.document_id) AS doc_count,
                    SUM(COALESCE(d.cited_by, 0))   AS citation_count,
                    ROUND(AVG(COALESCE(d.cited_by, 0)), 1) AS avg_citations,
                    MIN(d.year) AS first_year,
                    MAX(d.year) AS last_year
             FROM author a
             JOIN document_author da ON a.id = da.author_id
             JOIN document d         ON d.id = da.document_id AND d.project_id = ?' . $searchSql . '
             GROUP BY a.id, a.name
             ORDER BY doc_count DESC, citation_count DESC',
            $params
        );

        // Limit the list only if we are NOT searching, or if searching we can set a higher limit like 250 just in case
        if ($search !== null && trim($search) !== '') {
            $authorsList = array_slice($listAuthors, 0, 250);
        } else {
            $authorsList = array_slice($listAuthors, 0, $limit);
        }

        // 3. Fetch citation counts only for the displayed authors to calculate H/G-index
        if (!empty($authorsList)) {
            $authorIds = array_map(fn($a) => (int)$a['id'], $authorsList);
            $placeholders = implode(',', $authorIds);
            
            $citRows = $this->conn->fetchAllAssociative(
                "SELECT da.author_id, COALESCE(d.cited_by, 0) AS cited_by
                 FROM document d
                 JOIN document_author da ON da.document_id = d.id
                 WHERE d.project_id = ? AND da.author_id IN ($placeholders)
                 ORDER BY da.author_id, d.cited_by DESC",
                [$projectId]
            );

            // Group into a map: author_id → [cit1, cit2, ...]
            $citsByAuthor = [];
            foreach ($citRows as $row) {
                $citsByAuthor[(int)$row['author_id']][] = (int)$row['cited_by'];
            }

            // Assign H/G-index
            foreach ($authorsList as &$auth) {
                $cits = $citsByAuthor[(int)$auth['id']] ?? [];
                $auth['h_index'] = $this->calcHIndex($cits);
                $auth['g_index'] = $this->calcGIndex($cits);
            }
            unset($auth);
        }

        // 4. Build Lotka distribution based on ALL authors (unfiltered)
        $lotkaObserved = [];
        $topAuthorDocs = 0;
        $topAuthorName = 'N/A';
        
        // Sort allAuthors to easily find top author
        usort($allAuthors, function($a, $b) {
            if ($a['doc_count'] === $b['doc_count']) {
                return $b['citation_count'] <=> $a['citation_count'];
            }
            return $b['doc_count'] <=> $a['doc_count'];
        });

        if (!empty($allAuthors)) {
            $topAuthorName = $allAuthors[0]['name'];
            $topAuthorDocs = (int) $allAuthors[0]['doc_count'];
        }

        foreach ($allAuthors as $auth) {
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

        return [
            'list'           => $authorsList,
            'lotka_observed' => $lotkaObserved,
            'lotka_expected' => $lotkaExpected,
            'kpis'           => [
                'total_authors'   => count($allAuthors),
                'top_author'      => $topAuthorName,
                'top_author_docs' => $topAuthorDocs,
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

    public function getKeywordsReport(int $projectId, int $limit = 150, ?string $search = null): array
    {
        $params = [$projectId];
        $searchSql = '';
        if ($search !== null && trim($search) !== '') {
            $searchSql = ' AND k.term LIKE ?';
            $params[] = '%' . trim($search) . '%';
        }

        $targetLimit = ($search !== null && trim($search) !== '') ? 300 : $limit;

        $keywords = $this->conn->fetchAllAssociative(
            "SELECT k.id, k.term, k.type, COUNT(dk.document_id) AS freq,
                    MIN(d.year) AS first_year, MAX(d.year) AS last_year
             FROM keyword k
             JOIN document_keyword dk ON k.id = dk.keyword_id
             JOIN document d         ON dk.document_id = d.id AND d.project_id = ?{$searchSql}
             GROUP BY k.id, k.term, k.type
             ORDER BY freq DESC
             LIMIT {$targetLimit}",
            $params
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
            'SELECT c.common_name AS country, COUNT(dp.document_id) AS doc_count, SUM(COALESCE(d.cited_by, 0)) AS citation_count
             FROM documento_paises dp
             JOIN paises c ON dp.country_id = c.id
             JOIN document d ON dp.document_id = d.id
             WHERE d.project_id = ?
             GROUP BY c.id, c.common_name
             ORDER BY doc_count DESC',
            [$projectId]
        );

        $countryCounts = [];
        foreach ($rows as $row) {
            $c = $row['country'];
            $countryCounts[] = [
                'country' => $c,
                'doc_count' => (int)$row['doc_count'],
                'citation_count' => (int)$row['citation_count'],
                'avg_citations' => $row['doc_count'] > 0 ? round($row['citation_count'] / $row['doc_count'], 1) : 0
            ];
        }

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

    public function searchDocuments(int $projectId, array $filters, int $limit = 500): array
    {
        $qb = $this->conn->createQueryBuilder();
        $qb->select('d.id', 'd.title', 'd.year', 'd.source_title', 'd.doi', 'd.url', 'COALESCE(d.cited_by, 0) AS cited_by', 'd.document_type', 'd.volume', 'd.issue', 'd.page_start', 'd.page_end', 'd.publisher', 'd.issn', 'd.isbn', 'd.abstract_text')
           ->from('document', 'd')
           ->where('d.project_id = :projectId')
           ->setParameter('projectId', $projectId);

        if (!empty($filters['author'])) {
            $qb->andWhere('EXISTS (
                SELECT 1 FROM document_author da
                JOIN author a ON a.id = da.author_id
                WHERE da.document_id = d.id AND a.name LIKE :author
            )')
            ->setParameter('author', '%' . trim($filters['author']) . '%');
        }

        if (!empty($filters['keyword'])) {
            $qb->andWhere('EXISTS (
                SELECT 1 FROM document_keyword dk
                JOIN keyword k ON k.id = dk.keyword_id
                WHERE dk.document_id = d.id AND k.term LIKE :keyword
            )')
            ->setParameter('keyword', '%' . trim($filters['keyword']) . '%');
        }

        if (!empty($filters['abstract'])) {
            $qb->andWhere('d.abstract_text LIKE :abstract')
               ->setParameter('abstract', '%' . trim($filters['abstract']) . '%');
        }

        if (!empty($filters['title'])) {
            $qb->andWhere('d.title LIKE :title')
               ->setParameter('title', '%' . trim($filters['title']) . '%');
        }

        if (!empty($filters['year'])) {
            $qb->andWhere('d.year = :year')
               ->setParameter('year', (int)$filters['year']);
        }

        $qb->orderBy('d.year', 'DESC')
           ->addOrderBy('d.title', 'ASC')
           ->setMaxResults($limit);

        $documents = $qb->executeQuery()->fetchAllAssociative();

        if (empty($documents)) {
            return [];
        }

        // Fetch authors string for all returned documents
        $docIds = array_map(fn($d) => (int)$d['id'], $documents);
        $placeholders = implode(',', $docIds);

        $authorsRows = $this->conn->fetchAllAssociative(
            "SELECT da.document_id, GROUP_CONCAT(a.name ORDER BY da.position ASC SEPARATOR '; ') AS authors_str
             FROM document_author da
             JOIN author a ON a.id = da.author_id
             WHERE da.document_id IN ($placeholders)
             GROUP BY da.document_id"
        );

        $authorsMap = [];
        foreach ($authorsRows as $row) {
            $authorsMap[(int)$row['document_id']] = $row['authors_str'];
        }

        foreach ($documents as &$doc) {
            $doc['authors_str'] = $authorsMap[(int)$doc['id']] ?? 'Autor desconhecido';
        }
        unset($doc);

        return $documents;
    }

    // ── Classification Report ─────────────────────────────────────────────────

    public function getClassificationReport(int $projectId): array
    {
        // 1. Groups (only normal type, ordered by position then name)
        $groupRows = $this->conn->fetchAllAssociative(
            'SELECT g.id, g.name, g.color, g.icon, g.type, g.position,
                    COUNT(dc.id) AS total
             FROM classification_group g
             LEFT JOIN document_classification dc ON dc.group_id = g.id AND dc.project_id = ?
             WHERE g.project_id = ? AND g.type = \'normal\'
             GROUP BY g.id, g.name, g.color, g.icon, g.type, g.position
             ORDER BY g.position ASC, g.name ASC',
            [$projectId, $projectId]
        );

        if (empty($groupRows)) {
            return [
                'groups'     => [],
                'stats'      => [],
                'top3'       => [],
                'growth'     => [],
                'journals'   => [],
                'years'      => [],
                'kpis'       => ['total_classified' => 0, 'total_cit' => 0, 'total_groups' => 0, 'year_min' => null, 'year_max' => null],
            ];
        }

        $groupIds = array_column($groupRows, 'id');
        $placeholders = implode(',', array_fill(0, count($groupIds), '?'));

        // 2. Stats per group (total docs, total citations, avg, max)
        $statsRows = $this->conn->fetchAllAssociative(
            "SELECT dc.group_id,
                    COUNT(dc.id)                             AS total,
                    SUM(COALESCE(d.cited_by, 0))             AS total_cit,
                    ROUND(AVG(COALESCE(d.cited_by, 0)), 1)   AS avg_cit,
                    MAX(COALESCE(d.cited_by, 0))             AS max_cit
             FROM document_classification dc
             JOIN document d ON d.id = dc.document_id
             WHERE dc.project_id = ? AND dc.group_id IN ($placeholders)
             GROUP BY dc.group_id",
            array_merge([$projectId], $groupIds)
        );
        $stats = [];
        foreach ($statsRows as $r) {
            $stats[(int)$r['group_id']] = [
                'total'     => (int)$r['total'],
                'total_cit' => (int)$r['total_cit'],
                'avg_cit'   => (float)$r['avg_cit'],
                'max_cit'   => (int)$r['max_cit'],
            ];
        }

        // 3. Top 3 cited documents per group
        $allDocsRows = $this->conn->fetchAllAssociative(
            "SELECT dc.group_id, d.id, d.title, d.year, d.source_title,
                    COALESCE(d.cited_by, 0) AS cited_by, COALESCE(d.doi, '') AS doi,
                    COALESCE(d.abstract_text, '') AS abstract,
                    (
                        SELECT GROUP_CONCAT(a2.name ORDER BY da2.position SEPARATOR '; ')
                        FROM document_author da2
                        JOIN author a2 ON a2.id = da2.author_id
                        WHERE da2.document_id = d.id
                    ) AS author_names,
                    (
                        SELECT GROUP_CONCAT(k2.term ORDER BY k2.term SEPARATOR '; ')
                        FROM document_keyword dk2
                        JOIN keyword k2 ON k2.id = dk2.keyword_id
                        WHERE dk2.document_id = d.id
                    ) AS keyword_terms
             FROM document_classification dc
             JOIN document d ON d.id = dc.document_id
             WHERE dc.project_id = ? AND dc.group_id IN ($placeholders)
             ORDER BY dc.group_id, COALESCE(d.cited_by, 0) DESC",
            array_merge([$projectId], $groupIds)
        );
        $top3 = [];
        foreach ($allDocsRows as $r) {
            $gid = (int)$r['group_id'];
            if (!isset($top3[$gid])) {
                $top3[$gid] = [];
            }
            if (count($top3[$gid]) < 3) {
                $top3[$gid][] = [
                    'id'           => (int)$r['id'],
                    'title'        => $r['title'],
                    'year'         => (int)$r['year'],
                    'source_title' => $r['source_title'],
                    'cited_by'     => (int)$r['cited_by'],
                    'doi'          => $r['doi'],
                    'abstract'     => $r['abstract'],
                    'authors'      => $r['author_names'] ?? 'Autores desconhecidos',
                    'keywords'     => $r['keyword_terms'] ?? '',
                ];
            }
        }


        // 4. Annual production per group
        $growthRows = $this->conn->fetchAllAssociative(
            "SELECT dc.group_id, d.year, COUNT(*) AS n
             FROM document_classification dc
             JOIN document d ON d.id = dc.document_id
             WHERE dc.project_id = ? AND dc.group_id IN ($placeholders)
               AND d.year IS NOT NULL
             GROUP BY dc.group_id, d.year
             ORDER BY dc.group_id, d.year",
            array_merge([$projectId], $groupIds)
        );
        $growth = [];
        foreach ($growthRows as $r) {
            $gid = (int)$r['group_id'];
            if (!isset($growth[$gid])) {
                $growth[$gid] = [];
            }
            $growth[$gid][(int)$r['year']] = (int)$r['n'];
        }

        // 5. Top 5 journals per group
        $journalRows = $this->conn->fetchAllAssociative(
            "SELECT dc.group_id, d.source_title, COUNT(*) AS n
             FROM document_classification dc
             JOIN document d ON d.id = dc.document_id
             WHERE dc.project_id = ? AND dc.group_id IN ($placeholders)
               AND d.source_title IS NOT NULL AND d.source_title != ''
             GROUP BY dc.group_id, d.source_title
             ORDER BY dc.group_id, n DESC",
            array_merge([$projectId], $groupIds)
        );
        $journals = [];
        foreach ($journalRows as $r) {
            $gid = (int)$r['group_id'];
            if (!isset($journals[$gid])) {
                $journals[$gid] = [];
            }
            if (count($journals[$gid]) < 5) {
                $journals[$gid][] = ['name' => $r['source_title'], 'n' => (int)$r['n']];
            }
        }

        // 6. All distinct years in this project (for heatmap/growth x-axis)
        $years = $this->conn->fetchFirstColumn(
            'SELECT DISTINCT d.year FROM document d WHERE d.project_id = ? AND d.year IS NOT NULL ORDER BY d.year',
            [$projectId]
        );
        $years = array_map('intval', $years);

        // 7. Global KPIs
        $totalClassified = array_sum(array_column($statsRows, 'total'));
        $totalCit        = array_sum(array_column($statsRows, 'total_cit'));
        $yearMin = !empty($years) ? min($years) : null;
        $yearMax = !empty($years) ? max($years) : null;

        return [
            'groups'   => $groupRows,
            'stats'    => $stats,
            'top3'     => $top3,
            'growth'   => $growth,
            'journals' => $journals,
            'years'    => $years,
            'kpis'     => [
                'total_classified' => $totalClassified,
                'total_cit'        => $totalCit,
                'total_groups'     => count($groupRows),
                'year_min'         => $yearMin,
                'year_max'         => $yearMax,
            ],
        ];
    }
}

