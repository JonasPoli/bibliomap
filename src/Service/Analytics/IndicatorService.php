<?php

namespace App\Service\Analytics;

use Doctrine\DBAL\Connection;

/**
 * Pure-DBAL analytics service.
 * All queries use native SQL for maximum performance on large datasets.
 * No ORM hydration — returns plain arrays ready for JSON serialisation.
 */
class IndicatorService
{
    public function __construct(private readonly Connection $conn) {}

    // ── Annual production ─────────────────────────────────────────────────────

    public function annualProduction(int $projectId): array
    {
        return $this->conn->fetchAllAssociative(
            'SELECT year, COUNT(*) AS count, SUM(cited_by) AS total_citations
             FROM document
             WHERE project_id = ? AND year IS NOT NULL
             GROUP BY year
             ORDER BY year',
            [$projectId]
        );
    }

    // ── Top authors ───────────────────────────────────────────────────────────

    public function topAuthors(int $projectId, int $limit = 20): array
    {
        return $this->conn->fetchAllAssociative(
            'SELECT a.preferred_name AS name, a.normalized_name, t.doc_count, t.total_citations
             FROM (
                 SELECT da.author_identity_id,
                        COUNT(DISTINCT da.document_id) AS doc_count,
                        SUM(d.cited_by)                AS total_citations
                 FROM document_author da
                 JOIN document d ON d.id = da.document_id AND d.project_id = ?
                 GROUP BY da.author_identity_id
                 ORDER BY doc_count DESC, total_citations DESC
                 LIMIT ' . (int)$limit . '
             ) t
             JOIN author_identity a ON a.id = t.author_identity_id',
            [$projectId]
        );
    }

    // ── Top sources / journals ────────────────────────────────────────────────

    public function topSources(int $projectId, int $limit = 20): array
    {
        return $this->conn->fetchAllAssociative(
            'SELECT source_title,
                    COUNT(*)        AS doc_count,
                    SUM(cited_by)   AS total_citations,
                    AVG(cited_by)   AS avg_citations
             FROM document
             WHERE project_id = ? AND source_title IS NOT NULL AND source_title != \'\'
             GROUP BY source_title
             ORDER BY doc_count DESC
             LIMIT ' . (int)$limit,
            [$projectId]
        );
    }

    // ── Top keywords ──────────────────────────────────────────────────────────

    public function topKeywords(int $projectId, string $type = 'author', int $limit = 50): array
    {
        $mappedType = $type === 'author' ? 'author_keyword' : ($type === 'indexed' ? 'indexed_keyword' : $type);
        // NOTE: LIMIT is inlined (not bound) to avoid MySQL < 8 quoting the
        // integer as a string when the parameter array contains mixed types.
        return $this->conn->fetchAllAssociative(
            'SELECT k.keyword_display AS term, k.keyword_normalized AS normalized_term, t.doc_count
             FROM (
                 SELECT COALESCE(k2.keyword_concept_id, k2.id) AS concept_id, COUNT(DISTINCT dk.document_id) AS doc_count
                 FROM document_keyword dk
                 JOIN document d ON d.id = dk.document_id AND d.project_id = ?
                 JOIN keyword k2 ON k2.id = dk.keyword_id AND k2.keyword_type = ?
                 GROUP BY COALESCE(k2.keyword_concept_id, k2.id)
                 ORDER BY doc_count DESC
                 LIMIT ' . (int)$limit . '
             ) t
             JOIN keyword k ON k.id = t.concept_id',
            [$projectId, $mappedType]
        );
    }

    // ── Document types ────────────────────────────────────────────────────────

    public function documentTypeDistribution(int $projectId): array
    {
        return $this->conn->fetchAllAssociative(
            'SELECT COALESCE(document_type, \'Unknown\') AS type, COUNT(*) AS count
             FROM document
             WHERE project_id = ?
             GROUP BY document_type
             ORDER BY count DESC',
            [$projectId]
        );
    }

    // ── Citations per year ────────────────────────────────────────────────────

    public function citationsPerYear(int $projectId): array
    {
        return $this->conn->fetchAllAssociative(
            'SELECT year,
                    SUM(cited_by)  AS total_citations,
                    AVG(cited_by)  AS avg_citations,
                    MAX(cited_by)  AS max_citations,
                    COUNT(*)       AS doc_count
             FROM document
             WHERE project_id = ? AND year IS NOT NULL AND cited_by IS NOT NULL
             GROUP BY year
             ORDER BY year',
            [$projectId]
        );
    }

    // ── H-Index ───────────────────────────────────────────────────────────────

    public function hIndex(int $projectId): int
    {
        // Fetch all citation counts sorted descending, compute h-index in PHP
        $rows = $this->conn->fetchAllAssociative(
            'SELECT cited_by FROM document
             WHERE project_id = ? AND cited_by IS NOT NULL
             ORDER BY cited_by DESC',
            [$projectId]
        );

        $h = 0;
        foreach ($rows as $i => $row) {
            $rank = $i + 1;
            if ($row['cited_by'] >= $rank) {
                $h = $rank;
            } else {
                break;
            }
        }
        return $h;
    }

    // ── Open Access ───────────────────────────────────────────────────────────

    public function openAccessStats(int $projectId): array
    {
        return $this->conn->fetchAllAssociative(
            'SELECT COALESCE(open_access_status, \'Unknown\') AS status, COUNT(*) AS count
             FROM document
             WHERE project_id = ?
             GROUP BY open_access_status
             ORDER BY count DESC',
            [$projectId]
        );
    }

    // ── Language distribution ─────────────────────────────────────────────────

    public function languageDistribution(int $projectId): array
    {
        return $this->conn->fetchAllAssociative(
            'SELECT COALESCE(language, \'Unknown\') AS language, COUNT(*) AS count
             FROM document
             WHERE project_id = ?
             GROUP BY language
             ORDER BY count DESC
             LIMIT 10',
            [$projectId]
        );
    }

    // ── Summary KPIs ──────────────────────────────────────────────────────────

    public function summary(int $projectId): array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT
               COUNT(*)                      AS total_docs,
               SUM(cited_by)                 AS total_citations,
               AVG(cited_by)                 AS avg_citations,
               MAX(cited_by)                 AS max_citations,
               COUNT(DISTINCT source_title)  AS total_sources,
               COUNT(DISTINCT year)          AS total_years,
               MIN(year)                     AS min_year,
               MAX(year)                     AS max_year,
               SUM(CASE WHEN open_access_status IS NOT NULL 
                         AND open_access_status != \'\' 
                         AND open_access_status != \'Unknown\'
                         THEN 1 ELSE 0 END) AS open_access_count
             FROM document
             WHERE project_id = ?',
            [$projectId]
        );

        $authorsCount = (int) $this->conn->fetchOne(
            'SELECT COUNT(DISTINCT da.author_identity_id) FROM document_author da
             JOIN document d ON d.id = da.document_id AND d.project_id = ?',
            [$projectId]
        );

        $keywordsCount = (int) $this->conn->fetchOne(
            'SELECT COUNT(DISTINCT COALESCE(k.keyword_concept_id, k.id)) FROM document_keyword dk
             JOIN keyword k ON k.id = dk.keyword_id
             JOIN document d ON d.id = dk.document_id AND d.project_id = ?',
            [$projectId]
        );


        return array_merge($row, [
            'total_authors'  => $authorsCount,
            'total_keywords' => $keywordsCount,
            'h_index'        => $this->hIndex($projectId),
        ]);
    }

    // ── Growth rate (annual % change) ─────────────────────────────────────────

    public function productionGrowthRate(int $projectId): array
    {
        $rows = $this->annualProduction($projectId);
        $result = [];
        $prev = null;
        foreach ($rows as $row) {
            $growth = null;
            if ($prev !== null && $prev['count'] > 0) {
                $growth = round((($row['count'] - $prev['count']) / $prev['count']) * 100, 1);
            }
            $result[] = [
                'year'        => $row['year'],
                'count'       => (int) $row['count'],
                'growth_rate' => $growth,
            ];
            $prev = $row;
        }
        return $result;
    }

    // ── Collaboration index ───────────────────────────────────────────────────

    public function collaborationIndex(int $projectId): array
    {
        return $this->conn->fetchAllAssociative(
            'SELECT year, AVG(author_count) AS avg_authors_per_doc
             FROM (
               SELECT d.year, COUNT(da.author_identity_id) AS author_count
               FROM document d
               JOIN document_author da ON da.document_id = d.id
               WHERE d.project_id = ? AND d.year IS NOT NULL
               GROUP BY d.id, d.year
             ) t
             GROUP BY year
             ORDER BY year',
            [$projectId]
        );
    }
}
