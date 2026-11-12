<?php

namespace App\Service\Analytics;

use Doctrine\DBAL\Connection;

class ReportService
{
    public function __construct(private readonly Connection $conn) {}

    /**
     * Pares (autor, documento) distintos só do projeto: filtrar dentro da derivada evita varrer
     * document_author inteira (o filtro de projeto no JOIN de fora não é empurrado para dentro).
     */
    private static function authorDocPairs(int $projectId): string
    {
        return 'SELECT DISTINCT dax.effective_author_id, dax.document_id
                FROM document_author dax
                JOIN document dd ON dd.id = dax.document_id
                WHERE dd.project_id = ' . (int) $projectId;
    }

    // ── 1. Authors Report ─────────────────────────────────────────────────────

    public function getAuthorsReport(int $projectId, int $limit = 100, ?string $search = null): array
    {
        $searching = $search !== null && trim($search) !== '';
        $pairs = self::authorDocPairs($projectId);

        // 1. Lotka e totais no banco: quantos autores têm n documentos (todos os autores, sem filtro de busca).
        // Nada é trazido ao PHP além de uma linha por valor de n.
        $lotkaObserved = [];
        $totalAuthors = 0;
        $lotkaRows = $this->conn->fetchAllAssociative(
            'SELECT t.doc_count, COUNT(*) AS authors
             FROM (
                 SELECT da.effective_author_id, COUNT(*) AS doc_count
                 FROM (' . $pairs . ') da
                 GROUP BY da.effective_author_id
             ) t
             GROUP BY t.doc_count
             ORDER BY t.doc_count ASC'
        );
        foreach ($lotkaRows as $row) {
            $lotkaObserved[(int) $row['doc_count']] = (int) $row['authors'];
            $totalAuthors += (int) $row['authors'];
        }

        // 2. Lista da tabela: só as linhas exibidas (LIMIT), na mesma ordem de sempre; empate: menor id.
        $params = [$projectId];
        $searchSql = '';
        if ($searching) {
            $searchSql = ' AND a.preferred_name LIKE ?';
            $params[] = '%' . trim($search) . '%';
        }
        $rowLimit = $searching ? 250 : max(0, $limit);

        $authorsList = $this->conn->fetchAllAssociative(
            'SELECT a.id, a.preferred_name AS name,
                    COUNT(DISTINCT da.document_id) AS doc_count,
                    SUM(COALESCE(d.cited_by, 0))   AS citation_count,
                    ROUND(AVG(COALESCE(d.cited_by, 0)), 1) AS avg_citations,
                    MIN(d.year) AS first_year,
                    MAX(d.year) AS last_year
             FROM author_identity a
             JOIN (' . $pairs . ') da ON a.id = da.effective_author_id
             JOIN document d         ON d.id = da.document_id AND d.project_id = ?' . $searchSql . '
             GROUP BY a.id, a.preferred_name
             ORDER BY doc_count DESC, citation_count DESC, a.id ASC
             LIMIT ' . (int) $rowLimit,
            $params
        );

        // 3. Fetch citation counts only for the displayed authors to calculate H/G-index
        if (!empty($authorsList)) {
            $authorIds = array_map(fn($a) => (int)$a['id'], $authorsList);
            $placeholders = implode(',', $authorIds);
            
            $citRows = $this->conn->fetchAllAssociative(
                "SELECT da.effective_author_id AS author_id, COALESCE(d.cited_by, 0) AS cited_by
                 FROM document d
                 JOIN (SELECT DISTINCT effective_author_id, document_id FROM document_author WHERE effective_author_id IN ($placeholders)) da ON da.document_id = d.id
                 WHERE d.project_id = ?
                 ORDER BY da.effective_author_id, d.cited_by DESC",
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

        // 4. Autor mais produtivo (sempre sem filtro de busca): sem busca é a primeira linha da lista
        $topAuthorName = 'N/A';
        $topAuthorDocs = 0;
        if ($searching) {
            $top = $this->conn->fetchAssociative(
                'SELECT a.preferred_name AS name, COUNT(DISTINCT da.document_id) AS doc_count,
                        SUM(COALESCE(d.cited_by, 0)) AS citation_count
                 FROM author_identity a
                 JOIN (' . $pairs . ') da ON a.id = da.effective_author_id
                 JOIN document d ON d.id = da.document_id AND d.project_id = ?
                 GROUP BY a.id, a.preferred_name
                 ORDER BY doc_count DESC, citation_count DESC, a.id ASC
                 LIMIT 1',
                [$projectId]
            );
        } else {
            $top = $authorsList[0] ?? null;
        }
        if ($top) {
            $topAuthorName = $top['name'];
            $topAuthorDocs = (int) $top['doc_count'];
        }

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
                'total_authors'   => $totalAuthors,
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
                    MIN(qualis) AS qualis, -- melhor estrato (texto: A1 < A2 < ... < C)
                    COUNT(*) AS doc_count,
                    SUM(COALESCE(cited_by, 0)) AS citation_count,
                    ROUND(AVG(COALESCE(cited_by, 0)), 1) AS avg_citations,
                    MIN(year) AS first_year, MAX(year) AS last_year
             FROM document
             WHERE project_id = ? AND source_title IS NOT NULL AND source_title != \'\'
             GROUP BY source_title
             ORDER BY doc_count DESC, citation_count DESC, source_title ASC',
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

        // Mesmo corte do Bibliometrix (bradford): a zona é definida pela frequência
        // acumulada INCLUINDO a própria fonte — até 33% → Zona 1, até 67% → Zona 2.
        // A primeira fonte fica sempre no núcleo, mesmo que sozinha passe de 1/3.
        $cut1 = round($totalDocs * 0.33);
        $cut2 = round($totalDocs * 0.67);
        $cumul = 0;

        foreach ($sourcesSortedByDocDesc as $i => $s) {
            $cumul += (int) $s['doc_count'];
            $zone = ($i === 0 || $cumul <= $cut1) ? 'zone1' : ($cumul <= $cut2 ? 'zone2' : 'zone3');
            $zones[$zone][] = [
                'source_title' => $s['source_title'],
                'doc_count'    => (int) $s['doc_count'],
            ];
        }

        return array_merge($zones, ['target_per_zone' => $target]);
    }

    /**
     * Filtro "primeiro vínculo da identidade efetiva no documento" (alias do vínculo: $da). Dois vínculos de um
     * documento agrupados na mesma identidade aparecem uma vez nas listas de autores, na posição do primeiro.
     */
    public static function firstEffectiveAuthorFilter(string $da = 'da'): string
    {
        return "NOT EXISTS (SELECT 1 FROM document_author dx WHERE dx.document_id = {$da}.document_id"
            . " AND dx.effective_author_id = {$da}.effective_author_id"
            . " AND (dx.position < {$da}.position OR (dx.position = {$da}.position AND dx.id < {$da}.id)))";
    }

    /** Subconsulta com os nomes EFETIVOS dos autores do documento (ordem de posição, sem repetir a identidade). */
    public static function effectiveAuthorNamesSql(string $docIdExpr, string $separator = '; '): string
    {
        return "(SELECT GROUP_CONCAT(ae.preferred_name ORDER BY dae.position ASC, dae.id ASC SEPARATOR '{$separator}')"
            . " FROM document_author dae JOIN author_identity ae ON ae.id = dae.effective_author_id"
            . " WHERE dae.document_id = {$docIdExpr} AND " . self::firstEffectiveAuthorFilter('dae') . ')';
    }

    // ── 3. Documents Report ───────────────────────────────────────────────────

    public function getDocumentsReport(int $projectId, int $limit = 100): array
    {
        $documents = $this->conn->fetchAllAssociative(
            "SELECT d.id, d.title, d.year, d.source_title, d.doi, d.url, d.qualis, COALESCE(d.cited_by, 0) AS cited_by,
                    " . self::effectiveAuthorNamesSql('d.id') . " AS authors_str,
                    (SELECT GROUP_CONCAT(p.sigla ORDER BY p.common_name ASC SEPARATOR ',')
                     FROM paises p
                     JOIN documento_paises dp ON p.id = dp.country_id
                     WHERE dp.document_id = d.id) AS country_siglas,
                    (SELECT GROUP_CONCAT(p.common_name ORDER BY p.common_name ASC SEPARATOR '; ')
                     FROM paises p
                     JOIN documento_paises dp ON p.id = dp.country_id
                     WHERE dp.document_id = d.id) AS country_names
              FROM document d
              WHERE d.project_id = ? AND d.title IS NOT NULL AND d.title != ''
              ORDER BY cited_by DESC, d.id ASC
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
            // Filtra DEPOIS de agrupar: acha o conceito pelo rótulo ou por qualquer variante,
            // mas a frequência continua contando todas as variantes do conceito.
            $searchSql = ' HAVING term LIKE ? OR SUM(k.keyword_display LIKE ?) > 0';
            $params[] = '%' . trim($search) . '%';
            $params[] = '%' . trim($search) . '%';
        }

        $targetLimit = ($search !== null && trim($search) !== '') ? 300 : $limit;

        // Uma linha por conceito (sem keyword_type no GROUP BY): freq = documentos distintos
        // em qualquer origem, igual à lista de "Ver trabalhos". A frequência por origem vem
        // em author_freq / indexed_freq.
        $keywords = $this->conn->fetchAllAssociative(
            "SELECT dk.effective_keyword_id AS id,
                    COALESCE(tc.preferred_label, ke.keyword_display, k.keyword_display) AS term,
                    CASE WHEN COUNT(DISTINCT k.keyword_type) > 1 THEN 'both'
                         WHEN MAX(k.keyword_type) = 'author_keyword' THEN 'author'
                         WHEN MAX(k.keyword_type) = 'indexed_keyword' THEN 'indexed'
                         ELSE MAX(k.keyword_type)
                    END AS type,
                    COUNT(DISTINCT dk.document_id) AS freq,
                    COUNT(DISTINCT CASE WHEN k.keyword_type = 'author_keyword'  THEN dk.document_id END) AS author_freq,
                    COUNT(DISTINCT CASE WHEN k.keyword_type = 'indexed_keyword' THEN dk.document_id END) AS indexed_freq,
                    MIN(d.year) AS first_year, MAX(d.year) AS last_year
             FROM document_keyword dk
             JOIN keyword k ON k.id = dk.keyword_id
             LEFT JOIN thesaurus_concept tc ON tc.id = IF(dk.effective_keyword_id < 0, -dk.effective_keyword_id, NULL)
             LEFT JOIN keyword ke ON ke.id = IF(dk.effective_keyword_id <> dk.keyword_id, dk.effective_keyword_id, NULL)
             JOIN document d         ON dk.document_id = d.id AND d.project_id = ?
             GROUP BY dk.effective_keyword_id, COALESCE(tc.preferred_label, ke.keyword_display, k.keyword_display){$searchSql}
             ORDER BY freq DESC, term ASC, id ASC
             LIMIT {$targetLimit}",
            $params
        );
        foreach ($keywords as &$kw) {
            $kw['freq']         = (int) $kw['freq'];
            $kw['author_freq']  = (int) $kw['author_freq'];
            $kw['indexed_freq'] = (int) $kw['indexed_freq'];
        }
        unset($kw);

        $summary = $this->conn->fetchAssociative(
            "SELECT COUNT(DISTINCT dk.effective_keyword_id) AS total_kw_count,
                    COUNT(DISTINCT CASE WHEN k.keyword_type = 'author_keyword'  THEN dk.effective_keyword_id END) AS author_kw_count,
                    COUNT(DISTINCT CASE WHEN k.keyword_type = 'indexed_keyword' THEN dk.effective_keyword_id END) AS indexed_kw_count
             FROM document_keyword dk
             JOIN keyword k ON k.id = dk.keyword_id
             JOIN document d         ON dk.document_id = d.id AND d.project_id = ?",
            [$projectId]
        );

        return [
            'list' => $keywords,
            'kpis' => [
                // Conceitos distintos: um termo que é de autor e indexado ao mesmo tempo conta uma vez
                'total_keywords'   => (int)($summary['total_kw_count'] ?? 0),
                'author_keywords'  => (int)($summary['author_kw_count']  ?? 0),
                'indexed_keywords' => (int)($summary['indexed_kw_count'] ?? 0),
            ]
        ];
    }

    // ── 5. Countries Report ───────────────────────────────────────────────────

    public function getCountriesReport(int $projectId): array
    {
        $rows = $this->conn->fetchAllAssociative(
            'SELECT p.common_name AS country, p.sigla, p.continente AS continent, COUNT(DISTINCT d.id) AS doc_count, SUM(COALESCE(d.cited_by, 0)) AS citation_count
             FROM document d
             JOIN documento_paises dp ON d.id = dp.document_id
             JOIN paises p ON p.id = dp.country_id
             WHERE d.project_id = ?
             GROUP BY p.id, p.common_name, p.sigla, p.continente
             ORDER BY doc_count DESC, citation_count DESC, p.id ASC',
            [$projectId]
        );

        $list = [];
        foreach ($rows as $row) {
            $freq = (int)$row['doc_count'];
            $citCount = (int)$row['citation_count'];
            $list[] = [
                'country'        => $row['country'],
                'sigla'          => $row['sigla'],
                'continent'      => $row['continent'],
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
            $topCountryDocs = $list[0]['freq'];
        }

        return [
            'list' => $list,
            'kpis' => [
                'total_countries' => count($list),
                'top_country'     => $topCountryName,
                'top_country_docs'=> $topCountryDocs,
            ]
        ];
    }

    // ── 6. Institutions Report ────────────────────────────────────────────────

    public function getInstitutionsReport(int $projectId): array
    {
        $rows = $this->conn->fetchAllAssociative(
            // Pares (documento, instituição) distintos: um vínculo repetido não soma a citação duas vezes
            'SELECT i.official_name AS institution,
                    COUNT(DISTINCT di.document_id) AS doc_count,
                    SUM(COALESCE(d.cited_by, 0)) AS citation_count
             FROM (SELECT DISTINCT dix.document_id, dix.institution_id
                   FROM documento_instituicoes dix
                   JOIN document dd ON dd.id = dix.document_id
                   WHERE dd.project_id = ?) di
             JOIN document d ON di.document_id = d.id
             JOIN instituicoes_ensino i ON di.institution_id = i.id
             GROUP BY i.id, i.official_name
             ORDER BY doc_count DESC, citation_count DESC, i.id ASC',
            [$projectId]
        );

        $list = [];
        foreach ($rows as $row) {
            $freq = (int)$row['doc_count'];
            $citCount = (int)$row['citation_count'];
            $list[] = [
                'institution'    => $row['institution'],
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
                'total_institutions' => count($list),
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
                    COUNT(DISTINCT NULLIF(source_title, \'\')) AS total_sources
             FROM document WHERE project_id = ?',
            [$projectId]
        );

        $kpis['total_authors'] = (int) $this->conn->fetchOne(
            'SELECT COUNT(DISTINCT da.effective_author_id) FROM document_author da
             JOIN document d ON d.id = da.document_id AND d.project_id = ?',
            [$projectId]
        );

        $kpis['total_keywords'] = (int) $this->conn->fetchOne(
            'SELECT COUNT(DISTINCT dk.effective_keyword_id) FROM document_keyword dk
             JOIN document d ON dk.document_id = d.id AND d.project_id = ?',
            [$projectId]
        );

        // Annual production
        $annual = IndicatorService::fillYearGaps($this->conn->fetchAllAssociative(
            'SELECT year, COUNT(*) AS doc_count, SUM(COALESCE(cited_by,0)) AS citation_count
             FROM document WHERE project_id = ? AND year IS NOT NULL
             GROUP BY year ORDER BY year ASC',
            [$projectId]
        ), ['doc_count' => 0, 'citation_count' => 0]);

        // Top 5 authors
        $topAuthors = $this->conn->fetchAllAssociative(
            'SELECT a.preferred_name AS name, COUNT(DISTINCT da.document_id) AS doc_count,
                    SUM(COALESCE(d.cited_by, 0)) AS citation_count
             FROM author_identity a
             JOIN (' . self::authorDocPairs($projectId) . ') da ON a.id = da.effective_author_id
             JOIN document d ON d.id = da.document_id AND d.project_id = ?
             GROUP BY a.id, a.preferred_name
             ORDER BY doc_count DESC, a.id ASC LIMIT 10',
            [$projectId]
        );

        // Top 5 sources
        $topSources = $this->conn->fetchAllAssociative(
            "SELECT source_title, COUNT(*) AS doc_count,
                    SUM(COALESCE(cited_by,0)) AS citation_count
             FROM document
             WHERE project_id = ? AND source_title IS NOT NULL AND source_title != ''
             GROUP BY source_title ORDER BY doc_count DESC, source_title ASC LIMIT 10",
            [$projectId]
        );

        // Top 5 keywords
        $topKeywords = $this->conn->fetchAllAssociative(
            // Uma linha por conceito, como no relatório de Palavras-chave (origem 'both' quando aparece nas duas)
            "SELECT COALESCE(tc.preferred_label, ke.keyword_display, k.keyword_display) AS term,
                    CASE WHEN COUNT(DISTINCT k.keyword_type) > 1 THEN 'both'
                         WHEN MAX(k.keyword_type) = 'author_keyword' THEN 'author'
                         WHEN MAX(k.keyword_type) = 'indexed_keyword' THEN 'indexed'
                         ELSE MAX(k.keyword_type)
                    END AS type,
                    COUNT(DISTINCT dk.document_id) AS freq
             FROM document_keyword dk
             JOIN keyword k ON k.id = dk.keyword_id
             LEFT JOIN thesaurus_concept tc ON tc.id = IF(dk.effective_keyword_id < 0, -dk.effective_keyword_id, NULL)
             LEFT JOIN keyword ke ON ke.id = IF(dk.effective_keyword_id <> dk.keyword_id, dk.effective_keyword_id, NULL)
             JOIN document d         ON dk.document_id = d.id AND d.project_id = ?
             GROUP BY dk.effective_keyword_id, COALESCE(tc.preferred_label, ke.keyword_display, k.keyword_display)
             ORDER BY freq DESC, term ASC LIMIT 20",
            [$projectId]
        );

        // Top 5 documents by citations
        $topDocs = $this->conn->fetchAllAssociative(
            "SELECT d.title, d.year, d.source_title, d.doi,
                    COALESCE(d.cited_by, 0) AS cited_by,
                    " . self::effectiveAuthorNamesSql('d.id') . " AS authors_str
             FROM document d
             WHERE d.project_id = ? AND d.title IS NOT NULL AND d.title != ''
             ORDER BY cited_by DESC, d.id ASC LIMIT 10",
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
                JOIN author_identity a ON a.id = da.effective_author_id
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
            "SELECT da.document_id, GROUP_CONCAT(a.preferred_name ORDER BY da.position ASC, da.id ASC SEPARATOR '; ') AS authors_str
             FROM document_author da
             JOIN author_identity a ON a.id = da.effective_author_id
             WHERE da.document_id IN ($placeholders) AND " . self::firstEffectiveAuthorFilter('da') . "
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
                'kpis'       => ['total_classified' => 0, 'total_cit' => 0, 'total_assignments' => 0, 'total_groups' => 0, 'year_min' => null, 'year_max' => null],
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

        // 3. Top 3 cited documents per group: primeiro só os ids (ROW_NUMBER por grupo, empate: menor id do
        // documento), depois resumo, autores e palavras-chave apenas desses documentos.
        $topRows = $this->conn->fetchAllAssociative(
            "SELECT t.group_id, t.doc_id
             FROM (
                 SELECT dc.group_id, d.id AS doc_id,
                        ROW_NUMBER() OVER (PARTITION BY dc.group_id ORDER BY COALESCE(d.cited_by, 0) DESC, d.id ASC) AS rn
                 FROM document_classification dc
                 JOIN document d ON d.id = dc.document_id
                 WHERE dc.project_id = ? AND dc.group_id IN ($placeholders)
             ) t
             WHERE t.rn <= 3
             ORDER BY t.group_id, t.rn",
            array_merge([$projectId], $groupIds)
        );
        $topDocIds = array_values(array_unique(array_map(fn($r) => (int) $r['doc_id'], $topRows)));
        $docDetails = [];
        if ($topDocIds) {
            $docPlaceholders = implode(',', $topDocIds);
            $detailRows = $this->conn->fetchAllAssociative(
                "SELECT d.id, d.title, d.year, d.source_title,
                        COALESCE(d.cited_by, 0) AS cited_by, COALESCE(d.doi, '') AS doi,
                        COALESCE(d.abstract_text, '') AS abstract,
                        " . self::effectiveAuthorNamesSql('d.id') . " AS author_names,
                        (
                            SELECT GROUP_CONCAT(DISTINCT COALESCE(kc2.keyword_display, k2.keyword_display) ORDER BY COALESCE(kc2.keyword_display, k2.keyword_display) SEPARATOR '; ')
                            FROM document_keyword dk2
                            JOIN keyword k2 ON k2.id = dk2.keyword_id
                            LEFT JOIN keyword kc2 ON k2.keyword_concept_id = kc2.id
                            WHERE dk2.document_id = d.id
                        ) AS keyword_terms
                 FROM document d
                 WHERE d.id IN ($docPlaceholders)"
            );
            foreach ($detailRows as $r) {
                $docDetails[(int) $r['id']] = $r;
            }
        }
        $top3 = [];
        foreach ($topRows as $tr) {
            $gid = (int) $tr['group_id'];
            $r = $docDetails[(int) $tr['doc_id']] ?? null;
            if ($r === null) {
                continue;
            }
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

        // 5. Top 5 journals per group (no banco: ROW_NUMBER por grupo; empate: título da revista)
        $journalRows = $this->conn->fetchAllAssociative(
            "SELECT t.group_id, t.source_title, t.n
             FROM (
                 SELECT dc.group_id, d.source_title, COUNT(*) AS n,
                        ROW_NUMBER() OVER (PARTITION BY dc.group_id ORDER BY COUNT(*) DESC, d.source_title ASC) AS rn
                 FROM document_classification dc
                 JOIN document d ON d.id = dc.document_id
                 WHERE dc.project_id = ? AND dc.group_id IN ($placeholders)
                   AND d.source_title IS NOT NULL AND d.source_title != ''
                 GROUP BY dc.group_id, d.source_title
             ) t
             WHERE t.rn <= 5
             ORDER BY t.group_id, t.rn",
            array_merge([$projectId], $groupIds)
        );
        $journals = [];
        foreach ($journalRows as $r) {
            $journals[(int)$r['group_id']][] = ['name' => $r['source_title'], 'n' => (int)$r['n']];
        }

        // 6. All distinct years in this project (for heatmap/growth x-axis)
        $years = $this->conn->fetchFirstColumn(
            'SELECT DISTINCT d.year FROM document d WHERE d.project_id = ? AND d.year IS NOT NULL ORDER BY d.year',
            [$projectId]
        );
        $years = array_map('intval', $years);

        // 7. Global KPIs — artigos distintos: um documento em 2 grupos conta uma vez
        // (a soma por grupo, total_assignments, é a base da pizza de concentração)
        $distinct = $this->conn->fetchAssociative(
            "SELECT COUNT(*) AS docs, COALESCE(SUM(cit), 0) AS cit
             FROM (
                 SELECT DISTINCT d.id, COALESCE(d.cited_by, 0) AS cit
                 FROM document_classification dc
                 JOIN document d ON d.id = dc.document_id
                 WHERE dc.project_id = ? AND dc.group_id IN ($placeholders)
             ) t",
            array_merge([$projectId], $groupIds)
        );
        $totalClassified = (int) ($distinct['docs'] ?? 0);
        $totalCit        = (int) ($distinct['cit'] ?? 0);
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
                'total_classified'  => $totalClassified,
                'total_cit'         => $totalCit,
                'total_assignments' => array_sum(array_column($statsRows, 'total')),
                'total_groups'      => count($groupRows),
                'year_min'         => $yearMin,
                'year_max'         => $yearMax,
            ],
        ];
    }
}
