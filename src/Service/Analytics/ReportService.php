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
            'SELECT a.id, a.preferred_name AS name,
                    COUNT(DISTINCT da.document_id) AS doc_count,
                    SUM(COALESCE(d.cited_by, 0))   AS citation_count
             FROM author_identity a
             JOIN document_author da ON a.id = da.author_identity_id
             JOIN document d         ON d.id = da.document_id AND d.project_id = ?
             GROUP BY a.id, a.preferred_name',
            [$projectId]
        );

        // 2. Fetch the target authors list (for the table)
        // If search is active, filter by name. Otherwise, just use all authors (and we will slice later).
        $params = [$projectId];
        $searchSql = '';
        if ($search !== null && trim($search) !== '') {
            $searchSql = ' AND a.preferred_name LIKE ?';
            $params[] = '%' . trim($search) . '%';
        }

        $listAuthors = $this->conn->fetchAllAssociative(
            'SELECT a.id, a.preferred_name AS name,
                    COUNT(DISTINCT da.document_id) AS doc_count,
                    SUM(COALESCE(d.cited_by, 0))   AS citation_count,
                    ROUND(AVG(COALESCE(d.cited_by, 0)), 1) AS avg_citations,
                    MIN(d.year) AS first_year,
                    MAX(d.year) AS last_year
             FROM author_identity a
             JOIN document_author da ON a.id = da.author_identity_id
             JOIN document d         ON d.id = da.document_id AND d.project_id = ?' . $searchSql . '
             GROUP BY a.id, a.preferred_name
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
                "SELECT da.author_identity_id AS author_id, COALESCE(d.cited_by, 0) AS cited_by
                 FROM document d
                 JOIN document_author da ON da.document_id = d.id
                 WHERE d.project_id = ? AND da.author_identity_id IN ($placeholders)
                 ORDER BY da.author_identity_id, d.cited_by DESC",
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

    /** G-index: largest g such that the top g papers have cumulatively ≥ g² citations (array must be sorted DESC) */
    private function calcGIndex(array $citationsSortedDesc): int
    {
        $g = 0;
        $sum = 0;
        foreach ($citationsSortedDesc as $i => $cit) {
            $rank = $i + 1;
            $sum += $cit;
            if ($sum >= ($rank * $rank)) {
                $g = $rank;
            } else {
                break;
            }
        }
        return $g;
    }

    // ── 2. Sources Report (Bradford Law) ──────────────────────────────────────

    public function getSourcesReport(int $projectId, int $limit = 100): array
    {
        $sources = $this->conn->fetchAllAssociative(
            'SELECT source_title, 
                    MAX(issn) AS issn,
                    MAX(qualis) AS qualis,
                    COUNT(*) AS doc_count,
                    SUM(COALESCE(cited_by, 0)) AS citation_count,
                    ROUND(AVG(COALESCE(cited_by, 0)), 1) AS avg_citations,
                    MIN(year) AS first_year, MAX(year) AS last_year
             FROM document
             WHERE project_id = ? AND source_title IS NOT NULL AND source_title != \'\'
             GROUP BY source_title
             ORDER BY doc_count DESC, citation_count DESC',
            [$projectId]
        );

        $bradford = $this->calcBradfordZones($sources);

        $topSourceName = 'N/A';
        $topSourceDocs = 0;
        if (!empty($sources)) {
            $topSourceName = $sources[0]['source_title'];
            $topSourceDocs = (int)$sources[0]['doc_count'];
        }

        return [
            'list'     => array_slice($sources, 0, $limit),
            'bradford' => $bradford,
            'kpis'     => [
                'total_sources' => count($sources),
                'top_source' => $topSourceName,
                'top_source_docs' => $topSourceDocs,
            ]
        ];
    }

    private function calcBradfordZones(array $sourcesSortedByDocDesc): array
    {
        $totalDocs = array_sum(array_column($sourcesSortedByDocDesc, 'doc_count'));
        if ($totalDocs === 0) {
            return ['zone1' => [], 'zone2' => [], 'zone3' => [], 'target_per_zone' => 0];
        }

        $target = round($totalDocs / 3);
        $zones = ['zone1' => [], 'zone2' => [], 'zone3' => []];
        $zoneKeys = ['zone1', 'zone2', 'zone3'];

        $cumul = 0;
        $zone = 1;

        foreach ($sourcesSortedByDocDesc as $s) {
            if ($zone < 3 && $cumul >= ($target * $zone)) {
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
            "SELECT d.id, d.title, d.year, d.source_title, d.doi, d.qualis, COALESCE(d.cited_by, 0) AS cited_by,
                    (SELECT GROUP_CONCAT(a.preferred_name ORDER BY da.position ASC SEPARATOR '; ')
                     FROM author_identity a
                     JOIN document_author da ON a.id = da.author_identity_id
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
            $searchSql = ' AND k.keyword_display LIKE ?';
            $params[] = '%' . trim($search) . '%';
        }

        $targetLimit = ($search !== null && trim($search) !== '') ? 300 : $limit;

        $keywords = $this->conn->fetchAllAssociative(
            "SELECT COALESCE(k.thesaurus_concept_id, k.keyword_concept_id, k.id) AS id,
                    COALESCE(tc.preferred_label, kc.keyword_display, k.keyword_display) AS term,
                    CASE WHEN k.keyword_type = 'author_keyword' THEN 'author' 
                         WHEN k.keyword_type = 'indexed_keyword' THEN 'indexed' 
                         ELSE k.keyword_type 
                    END AS type,
                    COUNT(DISTINCT dk.document_id) AS freq,
                    MIN(d.year) AS first_year, MAX(d.year) AS last_year
             FROM keyword k
             LEFT JOIN thesaurus_concept tc ON tc.id = k.thesaurus_concept_id
             LEFT JOIN keyword kc    ON k.keyword_concept_id = kc.id
             JOIN document_keyword dk ON k.id = dk.keyword_id
             JOIN document d         ON dk.document_id = d.id AND d.project_id = ?{$searchSql}
             GROUP BY COALESCE(k.thesaurus_concept_id, k.keyword_concept_id, k.id), COALESCE(tc.preferred_label, kc.keyword_display, k.keyword_display), k.keyword_type
             ORDER BY freq DESC
             LIMIT {$targetLimit}",
            $params
        );

        $summary = $this->conn->fetchAssociative(
            "SELECT COUNT(DISTINCT CASE WHEN k.keyword_type = 'author_keyword'  THEN COALESCE(k.thesaurus_concept_id, k.keyword_concept_id, k.id) END) AS author_kw_count,
                    COUNT(DISTINCT CASE WHEN k.keyword_type = 'indexed_keyword' THEN COALESCE(k.thesaurus_concept_id, k.keyword_concept_id, k.id) END) AS indexed_kw_count
             FROM keyword k
             LEFT JOIN thesaurus_concept tc ON tc.id = k.thesaurus_concept_id
             LEFT JOIN keyword kc    ON k.keyword_concept_id = kc.id
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
            'SELECT countries, COALESCE(cited_by, 0) AS cited_by FROM document WHERE project_id = ? AND countries IS NOT NULL',
            [$projectId]
        );

        $freqs = [];
        $citations = [];
        foreach ($rows as $row) {
            $arr = json_decode($row['countries'], true);
            if (is_array($arr)) {
                foreach ($arr as $country) {
                    $country = trim($country);
                    if ($country !== '') {
                        $freqs[$country] = ($freqs[$country] ?? 0) + 1;
                        $citations[$country] = ($citations[$country] ?? 0) + (int)$row['cited_by'];
                    }
                }
            }
        }

        arsort($freqs);

        $list = [];
        foreach ($freqs as $country => $freq) {
            $citCount = $citations[$country] ?? 0;
            $list[] = [
                'country'        => $country,
                'freq'           => $freq,
                'doc_count'      => $freq,
                'citation_count' => $citCount,
                'avg_citations'  => $freq > 0 ? round($citCount / $freq, 1) : 0,
            ];
        }

        $topCountryName = 'N/A';
        $topCountryDocs = 0;
        if (!empty($list)) {
            $topCountryName = $list[0]['country'];
            $topCountryDocs = (int)$list[0]['freq'];
        }

        return [
            'list' => $list,
            'kpis' => [
                'total_countries' => count($freqs),
                'top_country'     => $topCountryName,
                'top_country_docs'=> $topCountryDocs,
            ]
        ];
    }

    // ── 6. Institutions Report ────────────────────────────────────────────────

    public function getInstitutionsReport(int $projectId): array
    {
        $rows = $this->conn->fetchAllAssociative(
            'SELECT institutions, COALESCE(cited_by, 0) AS cited_by FROM document WHERE project_id = ? AND institutions IS NOT NULL',
            [$projectId]
        );

        $freqs = [];
        $citations = [];
        foreach ($rows as $row) {
            $arr = json_decode($row['institutions'], true);
            if (is_array($arr)) {
                foreach ($arr as $inst) {
                    $inst = trim($inst);
                    if ($inst !== '') {
                        $freqs[$inst] = ($freqs[$inst] ?? 0) + 1;
                        $citations[$inst] = ($citations[$inst] ?? 0) + (int)$row['cited_by'];
                    }
                }
            }
        }

        arsort($freqs);

        $list = [];
        foreach ($freqs as $inst => $freq) {
            $citCount = $citations[$inst] ?? 0;
            $list[] = [
                'institution'    => $inst,
                'freq'           => $freq,
                'doc_count'      => $freq,
                'citation_count' => $citCount,
                'avg_citations'  => $freq > 0 ? round($citCount / $freq, 1) : 0,
            ];
        }

        $topInstitutionName = 'N/A';
        $topInstitutionDocs = 0;
        if (!empty($list)) {
            $topInstitutionName = $list[0]['institution'];
            $topInstitutionDocs = (int)$list[0]['freq'];
        }

        return [
            'list' => $list,
            'kpis' => [
                'total_institutions' => count($freqs),
                'top_institution'     => $topInstitutionName,
                'top_institution_docs'=> $topInstitutionDocs,
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
            'SELECT COUNT(DISTINCT da.author_identity_id) FROM document_author da
             JOIN document d ON d.id = da.document_id AND d.project_id = ?',
            [$projectId]
        );

        $kpis['total_keywords'] = (int) $this->conn->fetchOne(
            'SELECT COUNT(DISTINCT COALESCE(k.thesaurus_concept_id, k.keyword_concept_id, k.id)) FROM keyword k
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
            'SELECT a.preferred_name AS name, COUNT(DISTINCT da.document_id) AS doc_count,
                    SUM(COALESCE(d.cited_by, 0)) AS citation_count
             FROM author_identity a
             JOIN document_author da ON a.id = da.author_identity_id
             JOIN document d ON d.id = da.document_id AND d.project_id = ?
             GROUP BY a.id, a.preferred_name
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
            "SELECT COALESCE(tc.preferred_label, kc.keyword_display, k.keyword_display) AS term,
                    CASE WHEN k.keyword_type = 'author_keyword' THEN 'author' 
                         WHEN k.keyword_type = 'indexed_keyword' THEN 'indexed' 
                         ELSE k.keyword_type 
                    END AS type,
                    COUNT(DISTINCT dk.document_id) AS freq
             FROM keyword k
             LEFT JOIN thesaurus_concept tc ON tc.id = k.thesaurus_concept_id
             LEFT JOIN keyword kc    ON k.keyword_concept_id = kc.id
             JOIN document_keyword dk ON k.id = dk.keyword_id
             JOIN document d         ON dk.document_id = d.id AND d.project_id = ?
             GROUP BY COALESCE(k.thesaurus_concept_id, k.keyword_concept_id, k.id), COALESCE(tc.preferred_label, kc.keyword_display, k.keyword_display), k.keyword_type
             ORDER BY freq DESC LIMIT 20",
            [$projectId]
        );

        // Top 5 documents by citations
        $topDocs = $this->conn->fetchAllAssociative(
            "SELECT d.title, d.year, d.source_title, d.doi,
                    COALESCE(d.cited_by, 0) AS cited_by,
                    (SELECT GROUP_CONCAT(a.preferred_name ORDER BY da2.position ASC SEPARATOR '; ')
                     FROM author_identity a JOIN document_author da2 ON a.id = da2.author_identity_id
                     WHERE da2.document_id = d.id) AS authors_str
             FROM document d
             WHERE d.project_id = ? AND d.title IS NOT NULL AND d.title != ''
             ORDER BY cited_by DESC LIMIT 10",
            [$projectId]
        );

        // Countries
        $countryData = $this->getCountriesReport($projectId);
        $topCountries = array_slice($countryData['list'], 0, 10);

        // Qualis distribution
        $qualisDist = $this->conn->fetchAllAssociative(
            'SELECT COALESCE(qualis, "Sem Qualis") AS qualis, COUNT(*) AS doc_count
             FROM document
             WHERE project_id = ?
             GROUP BY qualis
             ORDER BY CASE WHEN qualis = "A1" THEN 1
                           WHEN qualis = "A2" THEN 2
                           WHEN qualis = "A3" THEN 3
                           WHEN qualis = "A4" THEN 4
                           WHEN qualis = "B1" THEN 5
                           WHEN qualis = "B2" THEN 6
                           WHEN qualis = "B3" THEN 7
                           WHEN qualis = "B4" THEN 8
                           WHEN qualis = "B5" THEN 9
                           WHEN qualis = "C" THEN 10
                           ELSE 11 END',
            [$projectId]
        );

        return [
            'kpis'        => $kpis,
            'annual'      => $annual,
            'topAuthors'  => $topAuthors,
            'topSources'  => $topSources,
            'topKeywords' => $topKeywords,
            'topDocs'     => $topDocs,
            'topCountries'=> $topCountries,
            'qualisDist'  => $qualisDist,
        ];
    }

    public function searchDocuments(int $projectId, array $filters, int $limit = 500): array
    {
        $qb = $this->conn->createQueryBuilder();
        $qb->select('d.id', 'd.title', 'd.year', 'd.source_title', 'd.doi', 'd.url', 'COALESCE(d.cited_by, 0) AS cited_by', 'd.document_type', 'd.volume', 'd.issue', 'd.page_start', 'd.page_end', 'd.publisher', 'd.issn', 'd.isbn', 'd.abstract_text', 'd.qualis')
           ->from('document', 'd')
           ->where('d.project_id = :projectId')
           ->setParameter('projectId', $projectId);

        if (!empty($filters['author'])) {
            $qb->andWhere('EXISTS (
                SELECT 1 FROM document_author da
                JOIN author_identity a ON a.id = da.author_identity_id
                WHERE da.document_id = d.id AND a.preferred_name LIKE :author
            )')
            ->setParameter('author', '%' . trim($filters['author']) . '%');
        }

        if (!empty($filters['keyword'])) {
            $qb->andWhere('EXISTS (
                SELECT 1 FROM document_keyword dk
                JOIN keyword k ON k.id = dk.keyword_id
                WHERE dk.document_id = d.id AND k.keyword_display LIKE :keyword
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
            "SELECT da.document_id, GROUP_CONCAT(a.preferred_name ORDER BY da.position ASC SEPARATOR '; ') AS authors_str
             FROM document_author da
             JOIN author_identity a ON a.id = da.author_identity_id
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
                        SELECT GROUP_CONCAT(a2.preferred_name ORDER BY da2.position SEPARATOR '; ')
                        FROM document_author da2
                        JOIN author_identity a2 ON a2.id = da2.author_identity_id
                        WHERE da2.document_id = d.id
                    ) AS author_names,
                    (
                        SELECT GROUP_CONCAT(DISTINCT COALESCE(kc2.keyword_display, k2.keyword_display) ORDER BY COALESCE(kc2.keyword_display, k2.keyword_display) SEPARATOR '; ')
                        FROM document_keyword dk2
                        JOIN keyword k2 ON k2.id = dk2.keyword_id
                        LEFT JOIN keyword kc2 ON k2.keyword_concept_id = kc2.id
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
