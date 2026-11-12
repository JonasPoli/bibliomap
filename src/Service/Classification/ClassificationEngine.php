<?php

namespace App\Service\Classification;

use App\Entity\BibliometricProject;
use App\Entity\ClassificationGroup;
use App\Repository\ClassificationGroupRepository;
use App\Repository\DocumentClassificationRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ClassificationEngine
 *
 * Replicates the full logic of classifica.php + gerar-vosviewer-categorias pipeline:
 *
 *   1. Temporal filter   — documents with year < minYear → noise group
 *   2. Noise groups      — if any noise term found → routed to the noise group
 *   3. Validator groups   — document MUST contain at least one validator term; otherwise → noise group
 *   4. Normal groups      — ALL matching normal groups are assigned (multi-classification)
 *   5. Unclassified group — document had validators but matched no normal group
 */
class ClassificationEngine
{
    /**
     * Versão da expansão pelo tesauro gravada no projeto a cada execução
     * (bibliometric_project.classification_expansion_version). NULL = antes da 2.
     * 2: rótulos do tesauro + variações ativas da palavra-chave do termo.
     * Subir o número faz o aviso de "resultado calculado com a expansão anterior" voltar.
     */
    public const EXPANSION_VERSION = 2;

    public const UNCLASSIFIED_NAME = 'Sem Classificação';

    /** Documentos lidos por vez em calculate(): o corpus nunca fica inteiro na memória. */
    private const READ_CHUNK = 1000;
    /** Linhas por INSERT em run(). */
    private const INSERT_BATCH = 500;

    public function __construct(
        private readonly EntityManagerInterface              $em,
        private readonly ClassificationGroupRepository      $groupRepo,
        private readonly DocumentClassificationRepository   $classificationRepo,
        private readonly ClassificationTermExpander         $expander,
    ) {}

    /**
     * Run full classification for all documents in a project.
     * Monta os termos e calcula tudo antes de apagar os resultados anteriores:
     * uma falha na expansão para a execução com os resultados e as movimentações manuais intactos.
     *
     * @return array{total: int, by_group: array<string, int>, unclassified: int, noise: int, multi: int, rejected_unassigned: int}
     */
    public function run(BibliometricProject $project): array
    {
        // 1. Cálculo completo, sem gravar nada (a expansão usa sempre o modo atual)
        $result = $this->calculate($project, ClassificationTermExpander::MODE_WITH_VARIATIONS);

        // O grupo "Sem Classificação" é criado antes da transação (não é resultado: pode ficar)
        $uncGroup = $this->findOrCreateUnclassified($project, $result['groups']);
        $conn     = $this->em->getConnection();
        $now      = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $pid      = $project->getId();

        // 2. Apaga e regrava numa transação só: se cair no meio, os resultados e as
        //    movimentações manuais anteriores continuam como estavam.
        $conn->beginTransaction();
        try {
            $this->classificationRepo->deleteByProject($pid);

            // 3. Insert results — one row per assigned group, em lotes
            $rows = [];
            foreach ($result['assignments'] as $docId => $assignedGroups) {
                foreach ($assignedGroups as $assignment) {
                    $group  = $assignment['unclassified'] ? $uncGroup : $assignment['group'];
                    $rows[] = [$docId, $group?->getId(), $pid, $assignment['term'], $now];
                    if (count($rows) >= self::INSERT_BATCH) {
                        $this->insertRows($conn, $rows);
                        $rows = [];
                    }
                }
            }
            $this->insertRows($conn, $rows);

            // Os INSERT são SQL direto: nada a limpar no EntityManager (um clear() soltaria o projeto)
            $project->setClassificationExpansionVersion(self::EXPANSION_VERSION);
            $this->em->flush();

            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            throw $e;
        }

        return $result['stats'];
    }

    /**
     * Cálculo puro da classificação: sem INSERT, sem DELETE e sem criar o grupo "Sem Classificação"
     * (quando ele ainda não existe, a atribuição vem com group = null e unclassified = true).
     * O modo "só tesauro" existe apenas para a comparação (app:classification:compare-expansion).
     * $withDocuments guarda ano e título de cada documento (memória proporcional ao projeto): quem
     * precisa deles só de alguns documentos deve consultá-los depois.
     *
     * @return array{
     *     groups: ClassificationGroup[],
     *     termMap: array<int, string[]>,
     *     assignments: array<int, list<array{group: ?ClassificationGroup, term: ?string, unclassified: bool}>>,
     *     documents: array<int, array{year: ?int, title: ?string}>,   (vazio, salvo com $withDocuments)
     *     stats: array{total: int, by_group: array<string, int>, unclassified: int, noise: int, multi: int, rejected_unassigned: int}
     * }
     */
    public function calculate(BibliometricProject $project, int $expansionMode = ClassificationTermExpander::MODE_WITH_VARIATIONS, bool $withDocuments = false): array
    {
        // Load groups ordered by processing priority
        $allGroups = $this->groupRepo->findByProjectOrdered($project->getId());

        $validators    = array_filter($allGroups, fn($g) => $g->getType() === ClassificationGroup::TYPE_VALIDATOR);
        $noiseGroups   = array_filter($allGroups, fn($g) => $g->getType() === ClassificationGroup::TYPE_NOISE);
        $normalGroups  = array_filter($allGroups, fn($g) => $g->getType() === ClassificationGroup::TYPE_NORMAL);
        $uncGroup      = null;
        foreach ($allGroups as $g) {
            if ($g->getType() === ClassificationGroup::TYPE_UNCLASSIFIED) {
                $uncGroup = $g;
                break;
            }
        }
        $uncName = $uncGroup?->getName() ?? self::UNCLASSIFIED_NAME;

        // Build term lists indexed by group, expanding with Thesaurus if enabled (antes de ler os documentos)
        $validatorTerms = $this->buildTermMap($validators, $expansionMode);
        $noiseTermMap   = $this->buildTermMap($noiseGroups, $expansionMode);
        $normalTermMap  = $this->buildTermMap($normalGroups, $expansionMode);

        $conn = $this->em->getConnection();
        // GROUP_CONCAT corta em 1.024 bytes por padrão (documentos com muitas palavras-chave/autores)
        $conn->executeStatement('SET SESSION group_concat_max_len = 1048576');

        // Autores e instituições só são consultados se algum grupo temático filtra por eles
        $needAuthors = false;
        $needGeo     = false;
        foreach ($normalGroups as $g) {
            $needAuthors = $needAuthors || !empty($g->getAuthorsFilter());
            $needGeo     = $needGeo || $g->getContinente() || !empty($g->getCountryIds()) || !empty($g->getInstitutionNature());
        }

        // Get temporal filter
        $minYear = $project->getClassificationMinYear();
        $pid     = $project->getId();

        // rejected_unassigned: reprovados (ano mínimo/validador) que foram para "Sem Classificação"
        // porque o projeto não tem grupo Ruído — contam como sem grupo, não como ruído.
        $stats       = ['total' => 0, 'by_group' => [], 'unclassified' => 0, 'noise' => 0, 'multi' => 0, 'rejected_unassigned' => 0];
        $assignments = [];
        $documents   = [];

        // 3. Documentos lidos em lotes pela ordem do id (cada documento é avaliado sozinho)
        $lastId = 0;
        while (true) {
            $docs = $conn->fetchAllAssociative(
                'SELECT id, title, abstract_text, year, qualis FROM document
                 WHERE project_id = ? AND id > ? ORDER BY id LIMIT ' . self::READ_CHUNK,
                [$pid, $lastId]
            );
            if (!$docs) {
                break;
            }
            $firstId = (int) $docs[0]['id'];
            $lastId  = (int) $docs[count($docs) - 1]['id'];
            $docMeta = $this->fetchChunkMeta($pid, $firstId, $lastId, $needAuthors, $needGeo);

            // Palavras de autor (DE) e indexadas (ID / Keywords Plus) separadas pelo keyword_type,
            // com a forma tratada e a original. "; " evita casar um termo juntando duas palavras-chave.
            // UNIÃO com a identidade EFETIVA: se o vínculo foi agrupado (mapeamento ou conceito agrupador) e a
            // identidade efetiva difere da natural da palavra, o nome do agrupador entra no texto além dos dois
            // acima (nada que casava deixa de casar). Sem agrupamentos as duas junções ficam nulas e o texto é igual.
            // A "natural" é a própria palavra ou o conceito de tesauro dela (como em buildSqlCoalesceId sem agrupar).
            $docKeywords = [];
            $kwRows = $conn->fetchAllAssociative(
                'SELECT d.id,
                        GROUP_CONCAT(DISTINCT CASE WHEN k.keyword_type = "author_keyword"
                            THEN CONCAT_WS(" ; ", k.keyword_display, dk.original_term, COALESCE(tc.preferred_label, ke.keyword_display)) END SEPARATOR " ; ") AS author_keywords,
                        GROUP_CONCAT(DISTINCT CASE WHEN k.keyword_type = "indexed_keyword"
                            THEN CONCAT_WS(" ; ", k.keyword_display, dk.original_term, COALESCE(tc.preferred_label, ke.keyword_display)) END SEPARATOR " ; ") AS indexed_keywords
                 FROM document d
                 JOIN document_keyword dk ON dk.document_id = d.id
                 JOIN keyword k ON k.id = dk.keyword_id
                 LEFT JOIN thesaurus_concept tc ON tc.id = IF(dk.effective_keyword_id < 0
                        AND dk.effective_keyword_id <> IF(k.thesaurus_concept_id IS NULL, k.id, -k.thesaurus_concept_id),
                        -dk.effective_keyword_id, NULL)
                 LEFT JOIN keyword ke ON ke.id = IF(dk.effective_keyword_id > 0 AND dk.effective_keyword_id <> dk.keyword_id,
                        dk.effective_keyword_id, NULL)
                 WHERE d.project_id = ? AND d.id BETWEEN ? AND ?
                 GROUP BY d.id',
                [$pid, $firstId, $lastId]
            );
            foreach ($kwRows as $kr) {
                $docKeywords[(int) $kr['id']] = $kr;
            }
            unset($kwRows);

            foreach ($docs as $row) {
                $docId   = (int)$row['id'];
                $row['author_keywords']  = $docKeywords[$docId]['author_keywords'] ?? null;
                $row['indexed_keywords'] = $docKeywords[$docId]['indexed_keywords'] ?? null;
                $docYear = $row['year'] !== null ? (int)$row['year'] : null;
                $meta    = ($docMeta[$docId] ?? []) + ['authors' => [], 'countries' => [], 'continents' => [], 'natures' => []];
                $meta['qualis'] = $row['qualis'] ?? null;
                $textCache = []; // texto em minúsculas por combinação de campos, só deste documento

                $assignedGroups = [];
                $isNoise = false;

                // Step A: Global temporal filter — year < minYear → noise
                if ($minYear !== null && $docYear !== null && $docYear > 0 && $docYear < $minYear) {
                    $noiseGroup = reset($noiseGroups) ?: null;
                    if ($noiseGroup) {
                        $assignedGroups[] = ['group' => $noiseGroup, 'term' => 'year<' . $minYear];
                    }
                    $isNoise = true;
                }

                // Step B: Check noise terms
                if (!$isNoise) {
                    foreach ($noiseTermMap as $groupId => $terms) {
                        $group = $this->findGroupById($allGroups, $groupId);
                        $text  = $this->documentText($textCache, $row, $group?->getMatchFields() ?? ['title', 'abstract', 'author_keywords', 'indexed_keywords']);

                        foreach ($terms as $term) {
                            if ($term !== '' && str_contains($text, $term)) {
                                $assignedGroups[] = ['group' => $group, 'term' => $term];
                                $isNoise = true;
                                break 2;
                            }
                        }
                    }
                }

                // Step C: Validate (must have at least one validator term)
                if (!$isNoise && !empty($validatorTerms)) {
                    $passedValidation = false;
                    foreach ($validatorTerms as $groupId => $terms) {
                        $group = $this->findGroupById($allGroups, $groupId);
                        $text  = $this->documentText($textCache, $row, $group?->getMatchFields() ?? ['title', 'abstract', 'author_keywords', 'indexed_keywords']);

                        foreach ($terms as $term) {
                            if ($term !== '' && str_contains($text, $term)) {
                                $passedValidation = true;
                                break 2;
                            }
                        }
                    }
                    if (!$passedValidation) {
                        $noiseGroup = reset($noiseGroups) ?: null;
                        if ($noiseGroup) {
                            $assignedGroups[] = ['group' => $noiseGroup, 'term' => null];
                        }
                        $isNoise = true;
                    }
                }

                // Step D: Match ALL normal groups (multi-classification)
                if (!$isNoise) {
                    foreach ($normalGroups as $group) {
                        // Check Group-specific Qualis strata
                        if (!empty($group->getQualisFilter())) {
                            $gQualis = array_map('mb_strtoupper', $group->getQualisFilter());
                            $docQualis = !empty($meta['qualis']) ? mb_strtoupper($meta['qualis']) : null;
                            if (!$docQualis || !in_array($docQualis, $gQualis, true)) {
                                continue;
                            }
                        }

                        // Check Group-specific year range
                        if ($group->getStartYear() !== null && ($docYear === null || $docYear < $group->getStartYear())) {
                            continue;
                        }
                        if ($group->getEndYear() !== null && ($docYear === null || $docYear > $group->getEndYear())) {
                            continue;
                        }

                        // Check Group-specific Continent
                        if ($group->getContinente() && !self::continentMatches($group->getContinente(), $meta['continents'])) {
                            continue;
                        }

                        // Check Group-specific Countries
                        if (!empty($group->getCountryIds())) {
                            $gCountries = array_map('mb_strtolower', $group->getCountryIds());
                            $hasCountry = false;
                            foreach ($meta['countries'] as $dc) {
                                if (in_array($dc, $gCountries, true)) {
                                    $hasCountry = true;
                                    break;
                                }
                            }
                            if (!$hasCountry) continue;
                        }

                        // Check Group-specific Institution Nature
                        if (!empty($group->getInstitutionNature())) {
                            $gNatures = array_map('mb_strtolower', $group->getInstitutionNature());
                            $hasNature = false;
                            foreach ($meta['natures'] as $dn) {
                                foreach ($gNatures as $gn) {
                                    if (str_contains($dn, $gn)) {
                                        $hasNature = true;
                                        break 2;
                                    }
                                }
                            }
                            if (!$hasNature) continue;
                        }

                        // Check Group-specific Authors filter
                        if (!empty($group->getAuthorsFilter())) {
                            $gAuthors = array_map('mb_strtolower', $group->getAuthorsFilter());
                            $hasAuthor = false;
                            foreach ($meta['authors'] as $da) {
                                foreach ($gAuthors as $ga) {
                                    if (str_contains($da, $ga)) {
                                        $hasAuthor = true;
                                        break 2;
                                    }
                                }
                            }
                            if (!$hasAuthor) continue;
                        }

                        // Check term matching with specific matchFields & optional Thesaurus expansion
                        $text = $this->documentText($textCache, $row, $group->getMatchFields());
                        $terms = $normalTermMap[$group->getId()] ?? [];

                        foreach ($terms as $term) {
                            if ($term !== '' && str_contains($text, $term)) {
                                $assignedGroups[] = [
                                    'group' => $group,
                                    'term'  => $term,
                                ];
                                break; // Found a match for this group, move to next group
                            }
                        }
                    }
                }

                // Step E: Unclassified if no match
                if (empty($assignedGroups)) {
                    $assignedGroups[] = ['group' => $uncGroup, 'term' => null, 'unclassified' => true];
                }
                foreach ($assignedGroups as $i => $a) {
                    $assignedGroups[$i]['unclassified'] = $a['unclassified'] ?? ($a['group']?->getType() === ClassificationGroup::TYPE_UNCLASSIFIED);
                }

                // Track multi-classification
                if (count($assignedGroups) > 1 && !$isNoise) {
                    $stats['multi']++;
                }

                // Resultado do documento — one row per assigned group (gravado só por run())
                $assignments[$docId] = $assignedGroups;
                if ($withDocuments) {
                    $documents[$docId] = ['year' => $docYear, 'title' => $row['title'] ?? null];
                }

                foreach ($assignedGroups as $assignment) {
                    $gName = $assignment['unclassified'] ? $uncName : $assignment['group']?->getName();
                    if ($gName !== null) {
                        $stats['by_group'][$gName] = ($stats['by_group'][$gName] ?? 0) + 1;
                    }
                }

                // Count noise and unclassified at document level (pelo grupo onde o documento ficou)
                $stats['total']++;
                $onlyUnclassified = count($assignedGroups) === 1 && $assignedGroups[0]['unclassified'];
                if ($isNoise && !$onlyUnclassified) {
                    $stats['noise']++;
                } elseif ($onlyUnclassified) {
                    $stats['unclassified']++;
                    if ($isNoise) {
                        $stats['rejected_unassigned']++;
                    }
                }
            }

            unset($docs, $docMeta, $docKeywords);
        }

        return [
            'groups'      => $allGroups,
            'termMap'     => $validatorTerms + $noiseTermMap + $normalTermMap,
            'assignments' => $assignments,
            'documents'   => $documents,
            'stats'       => $stats,
        ];
    }

    /** Texto do documento para os campos pedidos, calculado uma vez por combinação de campos. */
    private function documentText(array &$cache, array $row, array $fields): string
    {
        $key = implode('|', $fields);
        return $cache[$key] ??= $this->buildDocumentText($row, $fields);
    }

    /**
     * Autores, países, continentes e naturezas dos documentos de um lote (ids do projeto entre $firstId e $lastId).
     * Autores e instituições em consultas separadas: juntas, cada documento virava autores × instituições linhas.
     *
     * @return array<int, array{authors?: string[], countries?: string[], continents?: string[], natures?: string[]}>
     */
    private function fetchChunkMeta(int $projectId, int $firstId, int $lastId, bool $needAuthors, bool $needGeo): array
    {
        $conn = $this->em->getConnection();
        $meta = [];

        if ($needAuthors) {
            $rows = $conn->fetchAllAssociative(
                'SELECT d.id AS doc_id, GROUP_CONCAT(DISTINCT LOWER(da.original_name) SEPARATOR "|") AS authors
                 FROM document d
                 JOIN document_author da ON da.document_id = d.id
                 WHERE d.project_id = ? AND d.id BETWEEN ? AND ?
                 GROUP BY d.id',
                [$projectId, $firstId, $lastId]
            );
            foreach ($rows as $m) {
                $meta[(int) $m['doc_id']]['authors'] = array_filter(explode('|', $m['authors'] ?? ''));
            }
        }

        if ($needGeo) {
            $rows = $conn->fetchAllAssociative(
                'SELECT d.id AS doc_id,
                        GROUP_CONCAT(DISTINCT LOWER(c.common_name) SEPARATOR "|") AS countries,
                        GROUP_CONCAT(DISTINCT LOWER(c.sigla) SEPARATOR "|") AS country_siglas,
                        GROUP_CONCAT(DISTINCT LOWER(c.continente) SEPARATOR "|") AS continents,
                        GROUP_CONCAT(DISTINCT LOWER(COALESCE(i.natureza, i.institution_type)) SEPARATOR "|") AS natures
                 FROM document d
                 JOIN documento_instituicoes di ON di.document_id = d.id
                 JOIN instituicoes_ensino i ON i.id = di.institution_id
                 LEFT JOIN paises c ON c.id = i.country_id
                 WHERE d.project_id = ? AND d.id BETWEEN ? AND ?
                 GROUP BY d.id',
                [$projectId, $firstId, $lastId]
            );
            foreach ($rows as $m) {
                $id = (int) $m['doc_id'];
                $meta[$id]['countries']  = array_filter(array_merge(explode('|', $m['countries'] ?? ''), explode('|', $m['country_siglas'] ?? '')));
                $meta[$id]['continents'] = array_filter(explode('|', $m['continents'] ?? ''));
                $meta[$id]['natures']    = array_filter(explode('|', $m['natures'] ?? ''));
            }
        }

        return $meta;
    }

    /** @param list<array{0:int,1:?int,2:int,3:?string,4:string}> $rows */
    private function insertRows(\Doctrine\DBAL\Connection $conn, array $rows): void
    {
        if (!$rows) {
            return;
        }
        $params = [];
        foreach ($rows as $r) {
            array_push($params, ...$r);
        }
        $conn->executeStatement(
            'INSERT INTO document_classification (document_id, group_id, project_id, matched_term, run_at, manual_override)
             VALUES ' . implode(', ', array_fill(0, count($rows), '(?, ?, ?, ?, ?, 0)')) . '
             ON DUPLICATE KEY UPDATE matched_term = VALUES(matched_term), run_at = VALUES(run_at), manual_override = 0',
            $params
        );
    }

    /**
     * Build text for document matching based on group matchFields.
     * @param string[] $fields
     */
    private function buildDocumentText(array $row, array $fields): string
    {
        $parts = [];
        if (in_array('title', $fields, true)) {
            $parts[] = $row['title'] ?? '';
        }
        if (in_array('abstract', $fields, true)) {
            $parts[] = $row['abstract_text'] ?? '';
        }
        if (in_array('author_keywords', $fields, true)) {
            $parts[] = $row['author_keywords'] ?? '';
        }
        if (in_array('indexed_keywords', $fields, true)) {
            $parts[] = $row['indexed_keywords'] ?? '';
        }

        return mb_strtolower(implode(' ', array_filter($parts)));
    }

    /** @param ClassificationGroup[] $groups */
    private function buildTermMap(array $groups, int $expansionMode): array
    {
        $map = [];
        foreach ($groups as $g) {
            $terms = $g->getTerms();
            if ($g->isUseThesaurus()) {
                // Sem try/catch: uma falha sobe antes de qualquer resultado ser apagado
                $terms = $this->expander->expand($terms, $expansionMode);
            }
            // O texto do documento é comparado em minúsculas (mb_*: "Água" → "água")
            $map[$g->getId()] = array_values(array_unique(array_map(fn($t) => mb_strtolower(trim($t)), $terms)));
        }
        return $map;
    }

    /**
     * Continente do grupo × continentes das instituições do documento (minúsculas, mb_*).
     * Países transcontinentais ("Europa/Ásia") casam com Europa e com Ásia; escolher
     * "Europa/Ásia" no grupo casa só com eles.
     *
     * @param string[] $docContinents
     */
    public static function continentMatches(string $groupContinent, array $docContinents): bool
    {
        $g = mb_strtolower(trim($groupContinent));
        foreach ($docContinents as $dc) {
            $dc = mb_strtolower(trim((string) $dc));
            if ($dc === $g || in_array($g, array_map('trim', explode('/', $dc)), true)) {
                return true;
            }
        }
        return false;
    }

    /** @param ClassificationGroup[] $groups */
    private function findGroupById(array $groups, int $id): ?ClassificationGroup
    {
        foreach ($groups as $g) {
            if ($g->getId() === $id) return $g;
        }
        return null;
    }

    /**
     * Finds or creates a special "unclassified" group for this project.
     *
     * @param ClassificationGroup[] $allGroups
     */
    private function findOrCreateUnclassified(BibliometricProject $project, array $allGroups): ClassificationGroup
    {
        foreach ($allGroups as $g) {
            if ($g->getType() === ClassificationGroup::TYPE_UNCLASSIFIED) {
                return $g;
            }
        }

        $unc = new ClassificationGroup();
        $unc->setProject($project)
            ->setName(self::UNCLASSIFIED_NAME)
            ->setDescription('Documentos que não se enquadraram em nenhum grupo.')
            ->setType(ClassificationGroup::TYPE_UNCLASSIFIED)
            ->setColor('#6b7280')
            ->setIcon('bi-question-circle')
            ->setPosition(999);

        $this->em->persist($unc);
        $this->em->flush();

        return $unc;
    }
}
