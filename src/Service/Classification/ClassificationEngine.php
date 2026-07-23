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

        // Build term lists indexed by group
        $validatorTerms = $this->buildTermMap($validators);
        $noiseTermMap   = $this->buildTermMap($noiseGroups);
        $normalTermMap  = $this->buildTermMap($normalGroups);

        // Get temporal filter
        $minYear = $project->getClassificationMinYear();

        // 3. Fetch all documents for the project via raw SQL for performance
        //    Include both keyword_display and original_term for full coverage (Keywords Plus)
        $conn = $this->em->getConnection();
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
            // Build the full text for analysis — mirrors classifica.php's approach:
            // TI + DE + ID + AB  (title + author_keywords + keywords_plus + abstract)
            $text = strtolower(
                ($row['title'] ?? '') . ' ' .
                ($row['abstract_text'] ?? '') . ' ' .
                ($row['keywords'] ?? '') . ' ' .
                ($row['keywords_original'] ?? '')
            );

            $assignedGroups = [];
            $isNoise = false;

            // Step A: Temporal filter — year < minYear → noise
            if ($minYear !== null) {
                $year = $row['year'] ?? null;
                if ($year !== null && (int)$year > 0 && (int)$year < $minYear) {
                    $noiseGroup = reset($noiseGroups) ?: null;
                    if ($noiseGroup) {
                        $assignedGroups[] = ['group' => $noiseGroup, 'term' => 'year<' . $minYear];
                    }
                    $isNoise = true;
                }
            }

            // Step B: Check noise terms
            if (!$isNoise) {
                foreach ($noiseTermMap as $groupId => $terms) {
                    foreach ($terms as $term) {
                        if ($term !== '' && str_contains($text, $term)) {
                            $assignedGroups[] = [
                                'group' => $this->findGroupById($allGroups, $groupId),
                                'term' => $term,
                            ];
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
                    foreach ($terms as $term) {
                        if ($term !== '' && str_contains($text, $term)) {
                            $passedValidation = true;
                            break 2;
                        }
                    }
                }
                if (!$passedValidation) {
                    // Fails validation → route to first noise group
                    $noiseGroup = reset($noiseGroups) ?: null;
                    if ($noiseGroup) {
                        $assignedGroups[] = ['group' => $noiseGroup, 'term' => null];
                    }
                    $isNoise = true;
                }
            }

            // Step D: Match ALL normal groups (multi-classification)
            if (!$isNoise) {
                foreach ($normalTermMap as $groupId => $terms) {
                    foreach ($terms as $term) {
                        if ($term !== '' && str_contains($text, $term)) {
                            $assignedGroups[] = [
                                'group' => $this->findGroupById($allGroups, $groupId),
                                'term' => $term,
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

    /** @param ClassificationGroup[] $groups */
    private function buildTermMap(array $groups): array
    {
        $map = [];
        foreach ($groups as $g) {
            $map[$g->getId()] = $g->getTerms();
        }
        return $map;
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
