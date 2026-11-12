<?php

namespace App\Service\Matrix;

use Doctrine\ORM\EntityManagerInterface;

class MatrixEngineService
{
    /** Documentos lidos por vez: o projeto nunca fica inteiro na memória. */
    private const READ_CHUNK = 5000;

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
        bool $useThesaurus = true,
        bool $sparse = false
    ): array {
        $startTime = microtime(true);
        $conn = $this->em->getConnection();

        $needed = [$rowKey, $colKey];
        $needAuthors  = in_array('author', $needed, true);
        $kwTypes      = array_values(array_intersect(['keyword_author', 'keyword_indexed'], $needed));
        $needInst     = (bool) array_intersect(['institution', 'institution_nature', 'country', 'continent', 'state', 'city'], $needed);
        $needGroups   = in_array('thematic_group', $needed, true);
        // Qualis por ISSN só é consultado quando a dimensão é pedida
        $qualisByIssn = in_array('qualis', $needed, true) ? $this->fetchQualisByIssn($projectId) : null;

        // 1ª passada: lê os documentos em lotes, guarda só os valores das duas dimensões (strings
        // compartilhadas) e soma as frequências. Nenhum par é materializado aqui.
        $intern    = [];
        $rowsByDoc = [];
        $colsByDoc = [];
        $rowFreq   = [];
        $colFreq   = [];
        $totalDocs = 0;
        $sameDim   = $rowKey === $colKey;
        $lastId    = 0;

        while (true) {
            $docs = $conn->fetchAllAssociative(
                'SELECT d.id, d.year, d.issn FROM document d
                 WHERE d.project_id = ? AND d.id > ? ORDER BY d.id LIMIT ' . self::READ_CHUNK,
                [$projectId, $lastId]
            );
            if (!$docs) {
                break;
            }
            $firstId = (int) $docs[0]['id'];
            $lastId  = (int) $docs[count($docs) - 1]['id'];
            $totalDocs += count($docs);

            // Dimensões multivaloradas só do lote e só as pedidas
            $authorsByDoc         = $needAuthors ? $this->fetchAuthors($projectId, $useThesaurus, $firstId, $lastId) : [];
            $keywordsByDoc        = $kwTypes ? $this->fetchKeywords($projectId, $useThesaurus, $firstId, $lastId, $kwTypes) : [];
            $institutionsByDoc    = $needInst ? $this->fetchInstitutions($projectId, $useThesaurus, $firstId, $lastId) : [];
            $classificationsByDoc = $needGroups ? $this->fetchClassifications($projectId, $firstId, $lastId) : [];

            foreach ($docs as $doc) {
                $dId = (int)$doc['id'];
                $qualis = null;
                if ($qualisByIssn !== null) {
                    $key = $doc['issn'] !== null ? mb_strtolower($doc['issn']) : '';
                    $qualis = $qualisByIssn['normalized'][$key] ?? $qualisByIssn['issn'][$key] ?? null;
                }
                $docData = [
                    'id' => $dId,
                    'year' => $doc['year'] ? (string)$doc['year'] : null,
                    'source' => $doc['issn'] ? (string)$doc['issn'] : null,
                    'qualis' => $qualis ?: 'Sem Qualis',
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
                $nRows = count($rowValues);

                if ($sameDim) {
                    // Co-ocorrência na mesma dimensão: cada item faz par com os outros n-1 do documento
                    if ($nRows < 2) {
                        continue;
                    }
                    foreach ($rowValues as $i => $v) {
                        $rowValues[$i] = $intern[$v] ??= $v;
                        $rowFreq[$v] = ($rowFreq[$v] ?? 0) + $nRows - 1;
                    }
                    $rowsByDoc[] = $rowValues;
                    continue;
                }

                $colValues = $this->extractValuesForKey($colKey, $docData);
                $nCols = count($colValues);
                if ($nRows === 0 || $nCols === 0) {
                    continue;
                }
                foreach ($rowValues as $i => $v) {
                    $rowValues[$i] = $intern[$v] ??= $v;
                    $rowFreq[$v] = ($rowFreq[$v] ?? 0) + $nCols;
                }
                foreach ($colValues as $i => $v) {
                    $colValues[$i] = $intern[$v] ??= $v;
                    $colFreq[$v] = ($colFreq[$v] ?? 0) + $nRows;
                }
                $rowsByDoc[] = $rowValues;
                $colsByDoc[] = $colValues;
            }
            unset($docs, $authorsByDoc, $keywordsByDoc, $institutionsByDoc, $classificationsByDoc);
        }
        unset($intern);

        if ($totalDocs === 0) {
            return $this->buildEmptyResult($rowKey, $colKey);
        }

        // Filter and sort top rows and cols. Empate de frequência: ordem alfabética do rótulo
        // (antes dependia da ordem em que o banco devolvia os documentos).
        ksort($rowFreq, SORT_STRING);
        ksort($colFreq, SORT_STRING);
        arsort($rowFreq);
        arsort($colFreq);

        $selectedRows = array_slice(array_keys($rowFreq), 0, $maxRows);
        $selectedCols = $sameDim ? $selectedRows : array_slice(array_keys($colFreq), 0, $maxCols);
        unset($rowFreq, $colFreq);

        // 2ª passada: conta só as células cujos itens ficaram entre os escolhidos
        $rowSet = array_fill_keys($selectedRows, true);
        $colSet = array_fill_keys($selectedCols, true);
        $matrixMap = [];
        foreach ($rowsByDoc as $n => $rowValues) {
            if ($sameDim) {
                $vals = [];
                foreach ($rowValues as $v) {
                    if (isset($rowSet[$v])) {
                        $vals[] = $v;
                    }
                }
                $count = count($vals);
                for ($i = 0; $i < $count; $i++) {
                    for ($j = $i + 1; $j < $count; $j++) {
                        $matrixMap[$vals[$i]][$vals[$j]] = ($matrixMap[$vals[$i]][$vals[$j]] ?? 0) + 1;
                        $matrixMap[$vals[$j]][$vals[$i]] = ($matrixMap[$vals[$j]][$vals[$i]] ?? 0) + 1;
                    }
                }
                continue;
            }
            foreach ($rowValues as $r) {
                if (!isset($rowSet[$r])) {
                    continue;
                }
                foreach ($colsByDoc[$n] as $c) {
                    if (isset($colSet[$c])) {
                        $matrixMap[$r][$c] = ($matrixMap[$r][$c] ?? 0) + 1;
                    }
                }
            }
        }
        unset($rowsByDoc, $colsByDoc, $rowSet, $colSet);

        $finalMatrix = [];
        $finalRowTotals = [];
        $finalColTotals = [];
        $totalPairs = 0;
        $activeCells = 0;

        if ($sparse) {
            // Só as células > 0 (exportação de matrizes grandes); os totais são os mesmos
            $finalColTotals = array_fill_keys($selectedCols, 0);
            foreach ($selectedRows as $r) {
                $finalMatrix[$r] = [];
                $rTotal = 0;
                foreach ($matrixMap[$r] ?? [] as $c => $val) {
                    if ($r === $c || $val < $minWeight) {
                        continue;
                    }
                    $finalMatrix[$r][$c] = $val;
                    $rTotal += $val;
                    $finalColTotals[$c] += $val;
                    $activeCells++;
                    $totalPairs += $val;
                }
                $finalRowTotals[$r] = $rTotal;
            }
        } else {
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
            'totalDocuments' => $totalDocs,
            'processingTimeMs' => $elapsedMs,
        ];
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

    /** @return array{normalized: array<string,?string>, issn: array<string,?string>} */
    private function fetchQualisByIssn(int $projectId): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT q.issn, q.normalized_issn, q.qualis
             FROM qualis_journal q
             WHERE q.issn IN (SELECT d.issn FROM document d WHERE d.project_id = ?)
                OR q.normalized_issn IN (SELECT d.issn FROM document d WHERE d.project_id = ?)
             ORDER BY q.id',
            [$projectId, $projectId]
        );

        // Chaves em minúsculas: a comparação no MySQL (collation _ci) ignora maiúsculas
        $map = ['normalized' => [], 'issn' => []];
        foreach ($rows as $r) {
            if ($r['normalized_issn'] !== null) {
                $map['normalized'][mb_strtolower($r['normalized_issn'])] ??= $r['qualis'];
            }
            if ($r['issn'] !== null) {
                $map['issn'][mb_strtolower($r['issn'])] ??= $r['qualis'];
            }
        }
        return $map;
    }

    private function fetchAuthors(int $projectId, bool $useThesaurus, int $firstId, int $lastId): array
    {
        $conn = $this->em->getConnection();
        $sql = 'SELECT da.document_id, da.original_name AS author_name_raw, ai.preferred_name AS canonical_name
                FROM document_author da
                JOIN document d ON d.id = da.document_id
                LEFT JOIN author_identity ai ON ai.id = da.effective_author_id
                WHERE d.project_id = ? AND d.id BETWEEN ? AND ?';

        $map = [];
        foreach ($conn->iterateAssociative($sql, [$projectId, $firstId, $lastId]) as $r) {
            $docId = (int)$r['document_id'];
            $name = ($useThesaurus && !empty($r['canonical_name'])) ? $r['canonical_name'] : $r['author_name_raw'];
            if ($name) {
                $map[$docId][] = $name;
            }
        }
        return $map;
    }

    /**
     * Com o tesauro ligado, o rótulo é o do conceito efetivo, como nos relatórios, nas redes e no mapa:
     * rótulo preferido do tesauro, senão a forma da palavra-chave conceito (keyword_concept_id), senão a própria.
     *
     * @param string[] $dimensions 'keyword_author' e/ou 'keyword_indexed'
     */
    private function fetchKeywords(int $projectId, bool $useThesaurus, int $firstId, int $lastId, array $dimensions): array
    {
        $conn = $this->em->getConnection();
        $types = array_map(fn($d) => $d === 'keyword_author' ? 'author_keyword' : 'indexed_keyword', $dimensions);
        $sql = 'SELECT dk.document_id, dk.original_term, k.keyword_display, k.keyword_type, tc.preferred_label,
                       kc.keyword_display AS concept_display
                FROM document_keyword dk
                JOIN document d ON d.id = dk.document_id
                JOIN keyword k ON k.id = dk.keyword_id
                LEFT JOIN thesaurus_concept tc ON tc.id = IF(dk.effective_keyword_id < 0, -dk.effective_keyword_id, NULL)
                LEFT JOIN keyword kc ON kc.id = IF(dk.effective_keyword_id <> dk.keyword_id, dk.effective_keyword_id, NULL)
                WHERE d.project_id = ? AND d.id BETWEEN ? AND ? AND k.keyword_type IN (?)';

        $map = [];
        $rows = $conn->iterateAssociative(
            $sql,
            [$projectId, $firstId, $lastId, $types],
            [\Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ArrayParameterType::STRING]
        );
        foreach ($rows as $r) {
            $docId = (int)$r['document_id'];
            $label = self::keywordLabel($useThesaurus, $r['preferred_label'], $r['concept_display'], $r['keyword_display'], $r['original_term']);
            // Cada termo só entra na dimensão do seu tipo (antes ia para as duas)
            $type = match ($r['keyword_type']) {
                'author_keyword'  => 'author',
                'indexed_keyword' => 'indexed',
                default           => null,
            };
            if ($label && $type) {
                $map[$docId][$type][] = $label;
            }
        }
        return $map;
    }

    /** Rótulo da palavra-chave na matriz (ver fetchKeywords). Sem o tesauro: a forma tratada, senão a original. */
    public static function keywordLabel(bool $useThesaurus, ?string $preferredLabel, ?string $conceptDisplay, ?string $keywordDisplay, ?string $originalTerm): ?string
    {
        if ($useThesaurus) {
            if (!empty($preferredLabel)) {
                return $preferredLabel;
            }
            if ($conceptDisplay !== null && $conceptDisplay !== '') {
                return $conceptDisplay;
            }
        }
        return $keywordDisplay ?: $originalTerm;
    }

    private function fetchInstitutions(int $projectId, bool $useThesaurus, int $firstId, int $lastId): array
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
                WHERE d.project_id = ? AND d.id BETWEEN ? AND ?';

        $map = [];
        foreach ($conn->iterateAssociative($sql, [$projectId, $firstId, $lastId]) as $r) {
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

    private function fetchClassifications(int $projectId, int $firstId, int $lastId): array
    {
        $conn = $this->em->getConnection();
        $sql = 'SELECT dc.document_id, g.name AS group_name
                FROM document_classification dc
                JOIN classification_group g ON g.id = dc.group_id
                WHERE dc.project_id = ? AND dc.document_id BETWEEN ? AND ?';

        $map = [];
        foreach ($conn->iterateAssociative($sql, [$projectId, $firstId, $lastId]) as $r) {
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
