<?php

namespace App\Service\Matrix;

use Doctrine\ORM\EntityManagerInterface;

class MatrixEngineService
{
    private array $dimensions = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        $this->registerDefaultDimensions();
    }

    public function getAvailableDimensions(): array
    {
        return $this->dimensions;
    }

    public function getDimension(string $key): ?array
    {
        return $this->dimensions[$key] ?? null;
    }

    /**
     * Build cross contingency matrix between rowDimension and colDimension.
     *
     * @return array{
     *   rowDimension: string,
     *   colDimension: string,
     *   rows: string[],
     *   cols: string[],
     *   matrix: array<string, array<string, int>>,
     *   rowTotals: array<string, int>,
     *   colTotals: array<string, int>,
     *   totalPairs: int,
     *   density: float,
     *   sparsity: float
     * }
     */
    public function generateMatrix(
        int $projectId,
        string $rowKey,
        string $colKey,
        int $minWeight = 1,
        int $maxRows = 30,
        int $maxCols = 30,
        bool $useThesaurus = true
    ): array {
        $startTime = microtime(true);
        $conn = $this->em->getConnection();

        // 1. Fetch document metadata for project
        $docs = $conn->fetchAllAssociative(
            'SELECT d.id, d.title, d.year, d.issn,
                    q.qualis AS qualis
             FROM document d
             LEFT JOIN qualis_journal q ON (q.normalized_issn = d.issn OR q.issn = d.issn)
             WHERE d.project_id = ?',
            [$projectId]
        );

        $docIds = array_column($docs, 'id');
        if (empty($docIds)) {
            return $this->buildEmptyResult($rowKey, $colKey);
        }

        // 2. Conditionally pre-fetch multi-valued dimensions for maximum performance
        $needed = [$rowKey, $colKey];
        $authorsByDoc = in_array('author', $needed) ? $this->fetchAuthors($projectId, $useThesaurus) : [];
        $keywordsByDoc = (in_array('keyword_author', $needed) || in_array('keyword_indexed', $needed)) ? $this->fetchKeywords($projectId, $useThesaurus) : [];
        $instKeys = ['institution', 'institution_nature', 'country', 'continent', 'state', 'city'];
        $institutionsByDoc = array_intersect($instKeys, $needed) ? $this->fetchInstitutions($projectId, $useThesaurus) : [];
        $classificationsByDoc = in_array('thematic_group', $needed) ? $this->fetchClassifications($projectId) : [];

        // Map documents into structured arrays
        $matrixMap = [];
        $rowFreq = [];
        $colFreq = [];

        foreach ($docs as $doc) {
            $dId = (int)$doc['id'];
            $docData = [
                'id' => $dId,
                'year' => $doc['year'] ? (string)$doc['year'] : null,
                'source' => $doc['issn'] ? (string)$doc['issn'] : null,
                'qualis' => $doc['qualis'] ?: 'Sem Qualis',
                'authors' => $authorsByDoc[$dId] ?? [],
                'keywords_author' => $keywordsByDoc[$dId]['author'] ?? [],
                'keywords_indexed' => $keywordsByDoc[$dId]['indexed'] ?? [],
                'institutions' => $institutionsByDoc[$dId]['name'] ?? [],
                'natures' => $institutionsByDoc[$dId]['nature'] ?? [],
                'countries' => $institutionsByDoc[$dId]['country'] ?? [],
                'continents' => $institutionsByDoc[$dId]['continent'] ?? [],
                'states' => $institutionsByDoc[$dId]['state'] ?? [],
                'cities' => $institutionsByDoc[$dId]['city'] ?? [],
                'thematic_groups' => $classificationsByDoc[$dId] ?? [],
            ];

            $rowValues = $this->extractValuesForKey($rowKey, $docData);
            $colValues = $this->extractValuesForKey($colKey, $docData);

            if ($rowKey === $colKey) {
                // Co-occurrence matrix on same dimension (e.g. author x author, keyword x keyword)
                $countVals = count($rowValues);
                for ($i = 0; $i < $countVals; $i++) {
                    for ($j = $i + 1; $j < $countVals; $j++) {
                        $rVal = $rowValues[$i];
                        $cVal = $rowValues[$j];
                        $this->incrementCell($matrixMap, $rowFreq, $colFreq, $rVal, $cVal);
                        $this->incrementCell($matrixMap, $rowFreq, $colFreq, $cVal, $rVal);
                    }
                }
            } else {
                // Cross bipartite matrix between 2 different dimensions
                foreach ($rowValues as $rVal) {
                    foreach ($colValues as $cVal) {
                        $this->incrementCell($matrixMap, $rowFreq, $colFreq, $rVal, $cVal);
                    }
                }
            }
        }

        // Filter and sort top rows and cols
        arsort($rowFreq);
        arsort($colFreq);

        $selectedRows = array_slice(array_keys($rowFreq), 0, $maxRows);
        $selectedCols = ($rowKey === $colKey) ? $selectedRows : array_slice(array_keys($colFreq), 0, $maxCols);

        $finalMatrix = [];
        $finalRowTotals = [];
        $finalColTotals = [];
        $totalPairs = 0;
        $activeCells = 0;

        foreach ($selectedRows as $r) {
            $finalMatrix[$r] = [];
            $rTotal = 0;
            foreach ($selectedCols as $c) {
                $val = $matrixMap[$r][$c] ?? 0;
                // Enforce zero diagonal rule when row item equals column item
                if ($r === $c) {
                    $val = 0;
                } elseif ($val < $minWeight) {
                    $val = 0;
                }
                $finalMatrix[$r][$c] = $val;
                $rTotal += $val;
                $finalColTotals[$c] = ($finalColTotals[$c] ?? 0) + $val;
                if ($val > 0) {
                    $activeCells++;
                    $totalPairs += $val;
                }
            }
            $finalRowTotals[$r] = $rTotal;
        }

        $possibleCells = count($selectedRows) * count($selectedCols);
        $density = $possibleCells > 0 ? round(($activeCells / $possibleCells) * 100, 2) : 0;
        $sparsity = 100.0 - $density;
        $elapsedMs = round((microtime(true) - $startTime) * 1000, 1);

        return [
            'rowDimension' => $this->dimensions[$rowKey]['label'] ?? $rowKey,
            'colDimension' => $this->dimensions[$colKey]['label'] ?? $colKey,
            'rowKey' => $rowKey,
            'colKey' => $colKey,
            'rows' => $selectedRows,
            'cols' => $selectedCols,
            'matrix' => $finalMatrix,
            'rowTotals' => $finalRowTotals,
            'colTotals' => $finalColTotals,
            'totalPairs' => $totalPairs,
            'activeCells' => $activeCells,
            'density' => $density,
            'sparsity' => $sparsity,
            'totalDocuments' => count($docs),
            'processingTimeMs' => $elapsedMs,
        ];
    }

    private function incrementCell(array &$matrix, array &$rowFreq, array &$colFreq, string $r, string $c): void
    {
        $r = trim($r);
        $c = trim($c);
        if ($r === '' || $c === '') return;

        $matrix[$r][$c] = ($matrix[$r][$c] ?? 0) + 1;
        $rowFreq[$r] = ($rowFreq[$r] ?? 0) + 1;
        $colFreq[$c] = ($colFreq[$c] ?? 0) + 1;
    }

    private function extractValuesForKey(string $key, array $docData): array
    {
        $vals = match ($key) {
            'author' => $docData['authors'],
            'keyword_author' => $docData['keywords_author'],
            'keyword_indexed' => $docData['keywords_indexed'],
            'year' => $docData['year'] ? [$docData['year']] : [],
            'institution' => $docData['institutions'],
            'institution_nature' => $docData['natures'],
            'country' => $docData['countries'],
            'continent' => $docData['continents'],
            'state' => $docData['states'],
            'city' => $docData['cities'],
            'qualis' => [$docData['qualis']],
            'thematic_group' => $docData['thematic_groups'],
            default => [],
        };

        return array_values(array_unique(array_filter(array_map('trim', $vals))));
    }

    private function fetchAuthors(int $projectId, bool $useThesaurus): array
    {
        $conn = $this->em->getConnection();
        $sql = 'SELECT da.document_id, da.original_name AS author_name_raw, ai.preferred_name AS canonical_name
                FROM document_author da
                JOIN document d ON d.id = da.document_id
                LEFT JOIN author_identity ai ON ai.id = da.author_identity_id
                WHERE d.project_id = ?';

        $rows = $conn->fetchAllAssociative($sql, [$projectId]);
        $map = [];
        foreach ($rows as $r) {
            $docId = (int)$r['document_id'];
            $name = ($useThesaurus && !empty($r['canonical_name'])) ? $r['canonical_name'] : $r['author_name_raw'];
            if ($name) {
                $map[$docId][] = $name;
            }
        }
        return $map;
    }

    private function fetchKeywords(int $projectId, bool $useThesaurus): array
    {
        $conn = $this->em->getConnection();
        $sql = 'SELECT dk.document_id, dk.original_term, k.keyword_display, tc.preferred_label
                FROM document_keyword dk
                JOIN document d ON d.id = dk.document_id
                LEFT JOIN keyword k ON k.id = dk.keyword_id
                LEFT JOIN thesaurus_concept tc ON tc.id = k.thesaurus_concept_id
                WHERE d.project_id = ?';

        $rows = $conn->fetchAllAssociative($sql, [$projectId]);
        $map = [];
        foreach ($rows as $r) {
            $docId = (int)$r['document_id'];
            $label = ($useThesaurus && !empty($r['preferred_label'])) ? $r['preferred_label'] : ($r['keyword_display'] ?: $r['original_term']);
            if ($label) {
                $map[$docId]['author'][] = $label;
                $map[$docId]['indexed'][] = $label;
            }
        }
        return $map;
    }

    private function fetchInstitutions(int $projectId, bool $useThesaurus): array
    {
        $conn = $this->em->getConnection();
        $sql = 'SELECT di.document_id, i.official_name, i.sigla, i.natureza, i.institution_type,
                       c.common_name AS country, c.continente,
                       st.official_name AS state, ct.official_name AS city
                FROM documento_instituicoes di
                JOIN document d ON d.id = di.document_id
                JOIN instituicoes_ensino i ON i.id = di.institution_id
                LEFT JOIN paises c ON c.id = i.country_id
                LEFT JOIN estados st ON st.id = i.state_id
                LEFT JOIN cidades ct ON ct.id = i.city_id
                WHERE d.project_id = ?';

        $rows = $conn->fetchAllAssociative($sql, [$projectId]);
        $map = [];
        foreach ($rows as $r) {
            $docId = (int)$r['document_id'];
            $inst = ($useThesaurus && !empty($r['official_name'])) ? $r['official_name'] : ($r['sigla'] ?: $r['official_name']);
            if ($inst) $map[$docId]['name'][] = $inst;
            if ($r['natureza'] || $r['institution_type']) $map[$docId]['nature'][] = $r['natureza'] ?: $r['institution_type'];
            if ($r['country']) $map[$docId]['country'][] = $r['country'];
            if ($r['continente']) $map[$docId]['continent'][] = $r['continente'];
            if ($r['state']) $map[$docId]['state'][] = $r['state'];
            if ($r['city']) $map[$docId]['city'][] = $r['city'];
        }
        return $map;
    }

    private function fetchClassifications(int $projectId): array
    {
        $conn = $this->em->getConnection();
        $sql = 'SELECT dc.document_id, g.name AS group_name
                FROM document_classification dc
                JOIN classification_group g ON g.id = dc.group_id
                WHERE dc.project_id = ?';

        $rows = $conn->fetchAllAssociative($sql, [$projectId]);
        $map = [];
        foreach ($rows as $r) {
            $docId = (int)$r['document_id'];
            if ($r['group_name']) {
                $map[$docId][] = $r['group_name'];
            }
        }
        return $map;
    }

    private function registerDefaultDimensions(): void
    {
        $list = [
            'author' => ['label' => 'Autores', 'category' => 'Pessoas & Autoria'],
            'keyword_author' => ['label' => 'Palavras-chave do Autor', 'category' => 'Conceitos & Palavras-Chave'],
            'keyword_indexed' => ['label' => 'Keywords Plus (Indexadas)', 'category' => 'Conceitos & Palavras-Chave'],
            'year' => ['label' => 'Ano de Publicação', 'category' => 'Temporal'],
            'institution' => ['label' => 'Instituição de Ensino / Pesquisa', 'category' => 'Institucional'],
            'institution_nature' => ['label' => 'Natureza Jurídica da Instituição', 'category' => 'Institucional'],
            'country' => ['label' => 'País de Origem', 'category' => 'Geografia'],
            'continent' => ['label' => 'Continente / Região', 'category' => 'Geografia'],
            'state' => ['label' => 'Estado / UF', 'category' => 'Geografia'],
            'city' => ['label' => 'Cidade', 'category' => 'Geografia'],
            'qualis' => ['label' => 'Estrato Qualis (CAPES)', 'category' => 'Publicação & Impacto'],
            'thematic_group' => ['label' => 'Grupo Temático (Classificação)', 'category' => 'Classificação & Temas'],
        ];

        foreach ($list as $key => $meta) {
            $this->dimensions[$key] = [
                'key' => $key,
                'label' => $meta['label'],
                'category' => $meta['category'],
            ];
        }
    }

    private function buildEmptyResult(string $rowKey, string $colKey): array
    {
        return [
            'rowDimension' => $this->dimensions[$rowKey]['label'] ?? $rowKey,
            'colDimension' => $this->dimensions[$colKey]['label'] ?? $colKey,
            'rowKey' => $rowKey,
            'colKey' => $colKey,
            'rows' => [],
            'cols' => [],
            'matrix' => [],
            'rowTotals' => [],
            'colTotals' => [],
            'totalPairs' => 0,
            'activeCells' => 0,
            'density' => 0.0,
            'sparsity' => 100.0,
        ];
    }
}
