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
 * Replicates the logic of classifica.php:
 *   1. Validator groups   — document MUST contain at least one validator term; otherwise → noise group
 *   2. Noise groups       — if any noise term found → routed to the noise group (first noise group wins)
 *   3. Normal groups      — first matching normal group wins (ordered by position)
 *   4. Unclassified group — document had validators but matched no normal group
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
     * @return array{total: int, by_group: array<string, int>, unclassified: int, noise: int}
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

        // 3. Fetch all documents for the project via raw SQL for performance
        $conn = $this->em->getConnection();
        $docs = $conn->fetchAllAssociative(
            'SELECT d.id, d.title, d.abstract_text,
                    GROUP_CONCAT(DISTINCT k.term SEPARATOR " ") AS keywords
             FROM document d
             LEFT JOIN document_keyword dk ON dk.document_id = d.id
             LEFT JOIN keyword k ON k.id = dk.keyword_id
             WHERE d.project_id = ?
             GROUP BY d.id',
            [$project->getId()]
        );

        $stats      = ['total' => 0, 'by_group' => [], 'unclassified' => 0, 'noise' => 0];
        $batchSize  = 100;
        $count      = 0;

        $now = new \DateTimeImmutable();

        foreach ($docs as $row) {
            $text = strtolower(
                ($row['title'] ?? '') . ' ' .
                ($row['abstract_text'] ?? '') . ' ' .
                ($row['keywords'] ?? '')
            );

            $assignedGroup = null;
            $matchedTerm   = null;

            // Step A: Check noise terms first
            foreach ($noiseTermMap as $groupId => $terms) {
                foreach ($terms as $term) {
                    if ($term !== '' && str_contains($text, $term)) {
                        $assignedGroup = $this->findGroupById($allGroups, $groupId);
                        $matchedTerm   = $term;
                        break 2;
                    }
                }
            }

            // Step B: Validate (must have at least one validator term)
            if ($assignedGroup === null && !empty($validatorTerms)) {
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
                    $assignedGroup = reset($noiseGroups) ?: null;
                    $matchedTerm   = null;
                }
            }

            // Step C: Match normal groups
            if ($assignedGroup === null) {
                foreach ($normalTermMap as $groupId => $terms) {
                    foreach ($terms as $term) {
                        if ($term !== '' && str_contains($text, $term)) {
                            $assignedGroup = $this->findGroupById($allGroups, $groupId);
                            $matchedTerm   = $term;
                            break 2;
                        }
                    }
                }
            }

            // Step D: Unclassified if no match
            if ($assignedGroup === null) {
                $assignedGroup = $uncGroup;
            }

            // Insert result using raw SQL for bulk performance
            $this->em->getConnection()->executeStatement(
                'INSERT INTO document_classification (document_id, group_id, project_id, matched_term, run_at, manual_override)
                 VALUES (?, ?, ?, ?, ?, 0)
                 ON DUPLICATE KEY UPDATE group_id = VALUES(group_id), matched_term = VALUES(matched_term), run_at = VALUES(run_at), manual_override = 0',
                [
                    $row['id'],
                    $assignedGroup?->getId(),
                    $project->getId(),
                    $matchedTerm,
                    $now->format('Y-m-d H:i:s'),
                ]
            );

            // Update stats
            $stats['total']++;
            if ($assignedGroup) {
                $gName = $assignedGroup->getName();
                $stats['by_group'][$gName] = ($stats['by_group'][$gName] ?? 0) + 1;
                if ($assignedGroup->getType() === ClassificationGroup::TYPE_NOISE) {
                    $stats['noise']++;
                } elseif ($assignedGroup->getType() === ClassificationGroup::TYPE_UNCLASSIFIED) {
                    $stats['unclassified']++;
                }
            } else {
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
