<?php

namespace App\Service\Classification;

use App\Entity\BibliometricProject;
use App\Entity\ClassificationGroup;
use App\Entity\DocumentClassification;
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
    public function __construct(
        private readonly EntityManagerInterface              $em,
        private readonly ClassificationGroupRepository      $groupRepo,
        private readonly DocumentClassificationRepository   $classificationRepo,
    ) {}

    /**
     * Run full classification for all documents in a project.
     * Clears previous results before inserting new ones.
     *
     * @return array{total: int, by_group: array<string, int>, unclassified: int, noise: int, multi: int}
     */
    public function run(BibliometricProject $project): array
    {
        // 1. Wipe previous results
        $this->classificationRepo->deleteByProject($project->getId());

        // 2. Load groups ordered by processing priority
        $allGroups = $this->groupRepo->findByProjectOrdered($project->getId());

        $validators    = array_filter($allGroups, fn($g) => $g->getType() === ClassificationGroup::TYPE_VALIDATOR);
        $noiseGroups   = array_filter($allGroups, fn($g) => $g->getType() === ClassificationGroup::TYPE_NOISE);
        $normalGroups  = array_filter($allGroups, fn($g) => $g->getType() === ClassificationGroup::TYPE_NORMAL);
        $uncGroup      = $this->findOrCreateUnclassified($project, $allGroups);

        // Build term lists indexed by group, expanding with Thesaurus if enabled
        $validatorTerms = $this->buildTermMap($validators);
        $noiseTermMap   = $this->buildTermMap($noiseGroups);
        $normalTermMap  = $this->buildTermMap($normalGroups);

        // Pre-fetch document metadata for institutional and geographic filtering
        $conn = $this->em->getConnection();
        $docMeta = [];
        $metaRows = $conn->fetchAllAssociative(
            'SELECT d.id AS doc_id,
                    d.qualis AS qualis,
                    GROUP_CONCAT(DISTINCT LOWER(da.original_name) SEPARATOR "|") AS authors,
                    GROUP_CONCAT(DISTINCT LOWER(c.common_name) SEPARATOR "|") AS countries,
                    GROUP_CONCAT(DISTINCT LOWER(c.sigla) SEPARATOR "|") AS country_siglas,
                    GROUP_CONCAT(DISTINCT LOWER(c.continente) SEPARATOR "|") AS continents,
                    GROUP_CONCAT(DISTINCT LOWER(COALESCE(i.natureza, i.institution_type)) SEPARATOR "|") AS natures
             FROM document d
             LEFT JOIN document_author da ON da.document_id = d.id
             LEFT JOIN documento_instituicoes di ON di.document_id = d.id
             LEFT JOIN instituicoes_ensino i ON i.id = di.institution_id
             LEFT JOIN paises c ON c.id = i.country_id
             WHERE d.project_id = ?
             GROUP BY d.id',
            [$project->getId()]
        );
        foreach ($metaRows as $m) {
            $docMeta[$m['doc_id']] = [
                'qualis'         => $m['qualis'] ?? null,
                'authors'        => array_filter(explode('|', $m['authors'] ?? '')),
                'countries'      => array_filter(array_merge(explode('|', $m['countries'] ?? ''), explode('|', $m['country_siglas'] ?? ''))),
                'continents'     => array_filter(explode('|', $m['continents'] ?? '')),
                'natures'        => array_filter(explode('|', $m['natures'] ?? '')),
            ];
        }

        // Get temporal filter
        $minYear = $project->getClassificationMinYear();

        // 3. Fetch all documents for the project via raw SQL
        $docs = $conn->fetchAllAssociative(
            'SELECT d.id, d.title, d.abstract_text, d.year,
                    GROUP_CONCAT(DISTINCT COALESCE(k.keyword_display, dk.original_term) SEPARATOR " ") AS keywords,
                    GROUP_CONCAT(DISTINCT dk.original_term SEPARATOR " ") AS keywords_original
             FROM document d
             LEFT JOIN document_keyword dk ON dk.document_id = d.id
             LEFT JOIN keyword k ON k.id = dk.keyword_id
             WHERE d.project_id = ?
             GROUP BY d.id',
            [$project->getId()]
        );

        $stats      = ['total' => 0, 'by_group' => [], 'unclassified' => 0, 'noise' => 0, 'multi' => 0];
        $batchSize  = 100;
        $count      = 0;

        $now = new \DateTimeImmutable();

        foreach ($docs as $row) {
            $docId   = (int)$row['id'];
            $docYear = $row['year'] !== null ? (int)$row['year'] : null;
            $meta    = $docMeta[$docId] ?? ['authors' => [], 'countries' => [], 'continents' => [], 'natures' => []];

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
                    $text  = $this->buildDocumentText($row, $group?->getMatchFields() ?? ['title', 'abstract', 'author_keywords', 'indexed_keywords']);

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
                    $text  = $this->buildDocumentText($row, $group?->getMatchFields() ?? ['title', 'abstract', 'author_keywords', 'indexed_keywords']);

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
                        $gQualis = array_map('strtoupper', $group->getQualisFilter());
                        $docQualis = !empty($meta['qualis']) ? strtoupper($meta['qualis']) : null;
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
                    if ($group->getContinente()) {
                        $gCont = strtolower($group->getContinente());
                        if (!in_array($gCont, $meta['continents'], true)) {
                            continue;
                        }
                    }

                    // Check Group-specific Countries
                    if (!empty($group->getCountryIds())) {
                        $gCountries = array_map('strtolower', $group->getCountryIds());
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
                        $gNatures = array_map('strtolower', $group->getInstitutionNature());
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
                        $gAuthors = array_map('strtolower', $group->getAuthorsFilter());
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
                    $text = $this->buildDocumentText($row, $group->getMatchFields());
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
                $assignedGroups[] = ['group' => $uncGroup, 'term' => null];
            }

            // Track multi-classification
            if (count($assignedGroups) > 1 && !$isNoise) {
                $stats['multi']++;
            }

            // Insert results — one row per assigned group
            foreach ($assignedGroups as $assignment) {
                $group = $assignment['group'];
                $matchedTerm = $assignment['term'];

                $this->em->getConnection()->executeStatement(
                    'INSERT INTO document_classification (document_id, group_id, project_id, matched_term, run_at, manual_override)
                     VALUES (?, ?, ?, ?, ?, 0)
                     ON DUPLICATE KEY UPDATE matched_term = VALUES(matched_term), run_at = VALUES(run_at), manual_override = 0',
                    [
                        $row['id'],
                        $group?->getId(),
                        $project->getId(),
                        $matchedTerm,
                        $now->format('Y-m-d H:i:s'),
                    ]
                );

                // Update stats
                if ($group) {
                    $gName = $group->getName();
                    $stats['by_group'][$gName] = ($stats['by_group'][$gName] ?? 0) + 1;
                }
            }

            // Count noise and unclassified at document level
            $stats['total']++;
            if ($isNoise) {
                $stats['noise']++;
            } elseif (count($assignedGroups) === 1 && $assignedGroups[0]['group']?->getType() === ClassificationGroup::TYPE_UNCLASSIFIED) {
                $stats['unclassified']++;
            }

            $count++;
            if ($count % $batchSize === 0) {
                $this->em->clear(DocumentClassification::class);
            }
        }

        return $stats;
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
            $parts[] = $row['keywords'] ?? '';
        }
        if (in_array('indexed_keywords', $fields, true)) {
            $parts[] = $row['keywords_original'] ?? '';
        }

        return strtolower(implode(' ', array_filter($parts)));
    }

    /** @param ClassificationGroup[] $groups */
    private function buildTermMap(array $groups): array
    {
        $map = [];
        foreach ($groups as $g) {
            $terms = $g->getTerms();
            if ($g->isUseThesaurus()) {
                $terms = $this->expandTermsWithThesaurus($terms);
            }
            $map[$g->getId()] = $terms;
        }
        return $map;
    }

    /**
     * Expands rule terms using Thesaurus concepts & labels and keyword variations.
     * @param string[] $terms
     * @return string[]
     */
    private function expandTermsWithThesaurus(array $terms): array
    {
        $conn = $this->em->getConnection();
        $expanded = [];

        foreach ($terms as $term) {
            $tNorm = strtolower(trim($term));
            if ($tNorm === '') continue;

            $expanded[] = $tNorm;

            try {
                // Find concepts linked in thesaurus_label or thesaurus_concept
                $conceptIds = $conn->fetchFirstColumn(
                    'SELECT concept_id FROM thesaurus_label WHERE LOWER(label) = ? OR LOWER(normalized_label) = ?',
                    [$tNorm, $tNorm]
                );

                if (empty($conceptIds)) {
                    $conceptIds = $conn->fetchFirstColumn(
                        'SELECT id FROM thesaurus_concept WHERE LOWER(preferred_label) = ? OR LOWER(normalized_label) = ?',
                        [$tNorm, $tNorm]
                    );
                }

                if (!empty($conceptIds)) {
                    $synonyms = $conn->fetchFirstColumn(
                        'SELECT label FROM thesaurus_label WHERE concept_id IN (?)',
                        [$conceptIds],
                        [\Doctrine\DBAL\ArrayParameterType::INTEGER]
                    );
                    foreach ($synonyms as $syn) {
                        $sNorm = strtolower(trim($syn));
                        if ($sNorm !== '') $expanded[] = $sNorm;
                    }
                }

                // Check keyword variations
                $kwIds = $conn->fetchFirstColumn(
                    'SELECT id FROM keyword WHERE LOWER(keyword_display) = ? OR LOWER(keyword_normalized) = ?',
                    [$tNorm, $tNorm]
                );
                if (!empty($kwIds)) {
                    $vars = $conn->fetchFirstColumn(
                        'SELECT raw_variation FROM keyword_variation WHERE keyword_id IN (?)',
                        [$kwIds],
                        [\Doctrine\DBAL\ArrayParameterType::INTEGER]
                    );
                    foreach ($vars as $v) {
                        $vNorm = strtolower(trim($v));
                        if ($vNorm !== '') $expanded[] = $vNorm;
                    }
                }
            } catch (\Throwable) {
                // Keep original term if query fails
            }
        }

        return array_values(array_unique($expanded));
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
            ->setName('Sem Classificação')
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
