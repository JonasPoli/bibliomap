<?php

namespace App\Service\Classification;

use Doctrine\ORM\EntityManagerInterface;

class GroupComparisonService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Compare groups summary (volume, citations, % corpus)
     */
    public function getGroupSummaryComparison(int $projectId, array $groupIds): array
    {
        $conn = $this->em->getConnection();

        $totalProjectDocs = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM document WHERE project_id = ?',
            [$projectId]
        );

        $sql = 'SELECT g.id AS group_id, g.name AS group_name, g.color, g.icon, g.type,
                       COUNT(DISTINCT dc.document_id) AS doc_count,
                       COALESCE(SUM(d.cited_by), 0) AS total_citations,
                       ROUND(COALESCE(AVG(d.cited_by), 0), 2) AS avg_citations
                FROM classification_group g
                LEFT JOIN document_classification dc ON dc.group_id = g.id AND dc.project_id = g.project_id
                LEFT JOIN document d ON d.id = dc.document_id
                WHERE g.project_id = ? AND g.id IN (?)
                GROUP BY g.id
                ORDER BY doc_count DESC';

        $rows = $conn->fetchAllAssociative(
            $sql,
            [$projectId, $groupIds],
            [\Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ArrayParameterType::INTEGER]
        );

        foreach ($rows as &$r) {
            $count = (int)$r['doc_count'];
            $r['doc_count'] = $count;
            $r['total_citations'] = (int)$r['total_citations'];
            $r['avg_citations'] = (float)$r['avg_citations'];
            $r['percentage'] = $totalProjectDocs > 0 ? round(($count / $totalProjectDocs) * 100, 1) : 0;
        }

        return [
            'totalProjectDocs' => $totalProjectDocs,
            'groups'           => $rows,
        ];
    }

    /**
     * Temporal evolution (year by year) for selected groups
     */
    public function getTemporalEvolutionComparison(int $projectId, array $groupIds): array
    {
        $conn = $this->em->getConnection();

        $sql = 'SELECT d.year, g.id AS group_id, g.name AS group_name, g.color,
                       COUNT(DISTINCT dc.document_id) AS doc_count
                FROM document_classification dc
                JOIN document d ON d.id = dc.document_id
                JOIN classification_group g ON g.id = dc.group_id
                WHERE dc.project_id = ? AND dc.group_id IN (?) AND d.year IS NOT NULL AND d.year > 1900
                GROUP BY d.year, g.id
                ORDER BY d.year ASC, g.position ASC';

        $rows = $conn->fetchAllAssociative(
            $sql,
            [$projectId, $groupIds],
            [\Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ArrayParameterType::INTEGER]
        );

        $years = [];
        $groupSeries = [];

        foreach ($rows as $r) {
            $year = (int)$r['year'];
            $gId  = (int)$r['group_id'];
            $gName = $r['group_name'];
            $gColor = $r['color'] ?: '#4f8ef7';

            if (!in_array($year, $years, true)) {
                $years[] = $year;
            }

            if (!isset($groupSeries[$gId])) {
                $groupSeries[$gId] = [
                    'id'    => $gId,
                    'name'  => $gName,
                    'color' => $gColor,
                    'years' => [],
                ];
            }
            $groupSeries[$gId]['years'][$year] = (int)$r['doc_count'];
        }

        sort($years);

        return [
            'years'  => array_values($years),
            'series' => array_values($groupSeries),
        ];
    }

    /**
     * Keyword overlap and Jaccard similarity index between groups using Thesaurus
     */
    public function getSharedKeywordOverlap(int $projectId, array $groupIds): array
    {
        $conn = $this->em->getConnection();

        // Top keywords / concepts per group
        $sql = 'SELECT dc.group_id, g.name AS group_name,
                       COALESCE(tc.preferred_label, k.keyword_display, dk.original_term) AS concept_label,
                       COUNT(DISTINCT dc.document_id) AS freq
                FROM document_classification dc
                JOIN classification_group g ON g.id = dc.group_id
                JOIN document_keyword dk ON dk.document_id = dc.document_id
                LEFT JOIN keyword k ON k.id = dk.keyword_id
                LEFT JOIN thesaurus_concept tc ON tc.id = k.thesaurus_concept_id
                WHERE dc.project_id = ? AND dc.group_id IN (?)
                GROUP BY dc.group_id, concept_label
                HAVING freq >= 2
                ORDER BY dc.group_id, freq DESC';

        $rows = $conn->fetchAllAssociative(
            $sql,
            [$projectId, $groupIds],
            [\Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ArrayParameterType::INTEGER]
        );

        $groupKeywords = [];
        foreach ($rows as $r) {
            $gId = (int)$r['group_id'];
            $label = trim((string)$r['concept_label']);
            if ($label === '') continue;

            if (!isset($groupKeywords[$gId])) {
                $groupKeywords[$gId] = [];
            }
            $groupKeywords[$gId][$label] = (int)$r['freq'];
        }

        // Calculate pairwise Jaccard index
        $pairs = [];
        $gIds = array_keys($groupKeywords);
        $count = count($gIds);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $idA = $gIds[$i];
                $idB = $gIds[$j];

                $setA = array_keys($groupKeywords[$idA] ?? []);
                $setB = array_keys($groupKeywords[$idB] ?? []);

                $intersection = array_intersect($setA, $setB);
                $union = array_unique(array_merge($setA, $setB));

                $jaccard = !empty($union) ? round(count($intersection) / count($union), 3) : 0;

                $gNameA = $conn->fetchOne('SELECT name FROM classification_group WHERE id = ?', [$idA]);
                $gNameB = $conn->fetchOne('SELECT name FROM classification_group WHERE id = ?', [$idB]);

                $pairs[] = [
                    'groupA'       => $gNameA,
                    'groupB'       => $gNameB,
                    'intersection' => count($intersection),
                    'union'        => count($union),
                    'jaccard'      => $jaccard,
                    'topShared'    => array_slice(array_values($intersection), 0, 10),
                ];
            }
        }

        return [
            'pairwiseJaccard' => $pairs,
            'groupKeywords'   => $groupKeywords,
        ];
    }

    /**
     * Geographic profile (Top countries and continents) per group
     */
    public function getGeographicProfileComparison(int $projectId, array $groupIds): array
    {
        $conn = $this->em->getConnection();

        $sql = 'SELECT dc.group_id, g.name AS group_name, g.color,
                       COALESCE(c.common_name, "Não Informado") AS country_name,
                       COALESCE(c.continente, "Não Informado") AS continente,
                       COUNT(DISTINCT dc.document_id) AS doc_count
                FROM document_classification dc
                JOIN classification_group g ON g.id = dc.group_id
                JOIN documento_instituicoes di ON di.document_id = dc.document_id
                JOIN instituicoes_ensino i ON i.id = di.institution_id
                LEFT JOIN paises c ON c.id = i.country_id
                WHERE dc.project_id = ? AND dc.group_id IN (?)
                GROUP BY dc.group_id, country_name, continente
                ORDER BY dc.group_id, doc_count DESC';

        $rows = $conn->fetchAllAssociative(
            $sql,
            [$projectId, $groupIds],
            [\Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ArrayParameterType::INTEGER]
        );

        $byGroup = [];
        foreach ($rows as $r) {
            $gId = (int)$r['group_id'];
            if (!isset($byGroup[$gId])) {
                $byGroup[$gId] = [
                    'name' => $r['group_name'],
                    'color' => $r['color'],
                    'countries' => [],
                    'continents' => [],
                ];
            }
            $cnt = $r['country_name'];
            $cont = $r['continente'];
            $count = (int)$r['doc_count'];

            $byGroup[$gId]['countries'][$cnt] = ($byGroup[$gId]['countries'][$cnt] ?? 0) + $count;
            $byGroup[$gId]['continents'][$cont] = ($byGroup[$gId]['continents'][$cont] ?? 0) + $count;
        }

        return $byGroup;
    }

    /**
     * Institutional profile (Natureza jurídica e top instituições) per group
     */
    public function getInstitutionalProfileComparison(int $projectId, array $groupIds): array
    {
        $conn = $this->em->getConnection();

        $sql = 'SELECT dc.group_id, g.name AS group_name, g.color,
                       COALESCE(i.official_name, i.sigla) AS inst_name,
                       COALESCE(i.natureza, i.institution_type, "Não Informado") AS natureza,
                       COUNT(DISTINCT dc.document_id) AS doc_count
                FROM document_classification dc
                JOIN classification_group g ON g.id = dc.group_id
                JOIN documento_instituicoes di ON di.document_id = dc.document_id
                JOIN instituicoes_ensino i ON i.id = di.institution_id
                WHERE dc.project_id = ? AND dc.group_id IN (?)
                GROUP BY dc.group_id, inst_name, natureza
                ORDER BY dc.group_id, doc_count DESC';

        $rows = $conn->fetchAllAssociative(
            $sql,
            [$projectId, $groupIds],
            [\Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ArrayParameterType::INTEGER]
        );

        $byGroup = [];
        foreach ($rows as $r) {
            $gId = (int)$r['group_id'];
            if (!isset($byGroup[$gId])) {
                $byGroup[$gId] = [
                    'name' => $r['group_name'],
                    'color' => $r['color'],
                    'institutions' => [],
                    'natures' => [],
                ];
            }
            $inst = $r['inst_name'];
            $nat = $r['natureza'];
            $count = (int)$r['doc_count'];

            $byGroup[$gId]['institutions'][$inst] = ($byGroup[$gId]['institutions'][$inst] ?? 0) + $count;
            $byGroup[$gId]['natures'][$nat] = ($byGroup[$gId]['natures'][$nat] ?? 0) + $count;
        }

        return $byGroup;
    }

    /**
     * Qualis journal strata distribution (A1, A2, B1, etc.) per group
     */
    public function getQualisImpactComparison(int $projectId, array $groupIds): array
    {
        $conn = $this->em->getConnection();

        $sql = 'SELECT dc.group_id, g.name AS group_name, g.color,
                       COALESCE(q.qualis, "Sem Qualis") AS estrato,
                       COUNT(DISTINCT dc.document_id) AS doc_count
                FROM document_classification dc
                JOIN classification_group g ON g.id = dc.group_id
                JOIN document d ON d.id = dc.document_id
                LEFT JOIN qualis_journal q ON (q.normalized_issn = d.issn OR q.issn = d.issn)
                WHERE dc.project_id = ? AND dc.group_id IN (?)
                GROUP BY dc.group_id, estrato
                ORDER BY dc.group_id, doc_count DESC';

        $rows = $conn->fetchAllAssociative(
            $sql,
            [$projectId, $groupIds],
            [\Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ArrayParameterType::INTEGER]
        );

        $byGroup = [];
        foreach ($rows as $r) {
            $gId = (int)$r['group_id'];
            if (!isset($byGroup[$gId])) {
                $byGroup[$gId] = [
                    'name' => $r['group_name'],
                    'color' => $r['color'],
                    'qualis' => [],
                ];
            }
            $est = $r['estrato'];
            $count = (int)$r['doc_count'];
            $byGroup[$gId]['qualis'][$est] = ($byGroup[$gId]['qualis'][$est] ?? 0) + $count;
        }

        return $byGroup;
    }
}
