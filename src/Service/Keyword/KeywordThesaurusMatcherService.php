<?php

namespace App\Service\Keyword;

use App\Entity\Keyword;
use App\Entity\ThesaurusConcept;
use App\Entity\ThesaurusMatch;
use App\Service\KeywordTreatment\KeywordFuzzyMatcherService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Dedicated service for matching keywords to ThesaurusConcepts.
 *
 * Flow:
 * 1. Exact match on ThesaurusLabel.normalizedLabel
 * 2. Exact match on ThesaurusConcept.normalizedLabel
 * 3. Fuzzy matching via KeywordFuzzyMatcherService
 */
class KeywordThesaurusMatcherService
{
    /** @var array<string, int> normalizedLabel → concept_id */
    private array $labelMap = [];

    /** @var array<string, int> normalizedLabel → concept_id */
    private array $conceptMap = [];

    /** @var array<int, array{id: int, preferred_label: string, normalized_label: string}> */
    private array $conceptById = [];

    private bool $mapsLoaded = false;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly KeywordFuzzyMatcherService $fuzzyMatcher
    ) {}

    /**
     * Pre-loads all labels and concepts from the keyword thesaurus scheme
     * using raw SQL to avoid memory issues with large datasets.
     */
    public function loadMaps(): void
    {
        $this->labelMap = [];
        $this->conceptMap = [];
        $this->conceptById = [];

        $conn = $this->em->getConnection();

        $schemeId = $conn->fetchOne("SELECT id FROM thesaurus_scheme WHERE slug = 'keyword' LIMIT 1");
        if (!$schemeId) {
            $this->mapsLoaded = true;
            return;
        }

        // Load concepts as lightweight arrays
        $conceptRows = $conn->fetchAllAssociative(
            'SELECT id, preferred_label, normalized_label FROM thesaurus_concept WHERE scheme_id = ?',
            [$schemeId]
        );
        foreach ($conceptRows as $row) {
            $id = (int)$row['id'];
            $this->conceptById[$id] = $row;
            $this->conceptMap[$row['normalized_label']] = $id;
        }

        // Load labels
        $labelRows = $conn->fetchAllAssociative(
            'SELECT tl.normalized_label, tl.concept_id FROM thesaurus_label tl
             JOIN thesaurus_concept tc ON tl.concept_id = tc.id
             WHERE tc.scheme_id = ?',
            [$schemeId]
        );
        foreach ($labelRows as $row) {
            $this->labelMap[$row['normalized_label']] = (int)$row['concept_id'];
        }

        $this->mapsLoaded = true;
    }

    /**
     * Tries to match a keyword to a ThesaurusConcept.
     *
     * @return array{conceptId: ?int, conceptLabel: ?string, method: string, score: float, ambiguous: bool}
     */
    public function match(Keyword $keyword, float $minAutoScore = 95.0, float $minReviewScore = 75.0): array
    {
        if (!$this->mapsLoaded) {
            $this->loadMaps();
        }

        $normalized = $keyword->getKeywordNormalized();
        if ($normalized === '') {
            return ['conceptId' => null, 'conceptLabel' => null, 'method' => 'skipped', 'score' => 0.0, 'ambiguous' => false];
        }

        // 1. Exact match on label
        if (isset($this->labelMap[$normalized])) {
            $cid = $this->labelMap[$normalized];
            return [
                'conceptId' => $cid,
                'conceptLabel' => $this->conceptById[$cid]['preferred_label'] ?? '',
                'method' => 'exact_label',
                'score' => 100.0,
                'ambiguous' => false
            ];
        }

        // 2. Exact match on concept normalizedLabel
        if (isset($this->conceptMap[$normalized])) {
            $cid = $this->conceptMap[$normalized];
            return [
                'conceptId' => $cid,
                'conceptLabel' => $this->conceptById[$cid]['preferred_label'] ?? '',
                'method' => 'exact_concept',
                'score' => 100.0,
                'ambiguous' => false
            ];
        }

        // 3. Fuzzy matching against all labels and concept labels
        $bestScore = 0.0;
        $bestConceptId = null;
        $secondBestScore = 0.0;

        $allCandidates = array_unique(array_merge(
            array_keys($this->labelMap),
            array_keys($this->conceptMap)
        ));

        $firstChar = mb_substr($normalized, 0, 1);

        foreach ($allCandidates as $candidateNorm) {
            if (mb_substr($candidateNorm, 0, 1) !== $firstChar) {
                continue;
            }

            $score = $this->fuzzyMatcher->getSimilarityScore($normalized, $candidateNorm);
            if ($score > $bestScore) {
                $secondBestScore = $bestScore;
                $bestScore = $score;
                $bestConceptId = $this->labelMap[$candidateNorm] ?? $this->conceptMap[$candidateNorm] ?? null;
            } elseif ($score > $secondBestScore) {
                $secondBestScore = $score;
            }
        }

        if ($bestConceptId === null || $bestScore < $minReviewScore) {
            return ['conceptId' => null, 'conceptLabel' => null, 'method' => 'no_match', 'score' => $bestScore, 'ambiguous' => false];
        }

        $ambiguous = ($bestScore - $secondBestScore) < 3.0 && $secondBestScore >= $minReviewScore;

        $method = 'fuzzy';
        if ($bestScore >= $minAutoScore && !$ambiguous) {
            $method = 'fuzzy_auto';
        }

        return [
            'conceptId' => $bestConceptId,
            'conceptLabel' => $this->conceptById[$bestConceptId]['preferred_label'] ?? '',
            'method' => $method,
            'score' => $bestScore,
            'ambiguous' => $ambiguous,
        ];
    }

    /**
     * Resolves a concept ID to a ThesaurusConcept entity (lazy-loads from DB).
     */
    public function getConceptEntity(int $conceptId): ?ThesaurusConcept
    {
        return $this->em->getRepository(ThesaurusConcept::class)->find($conceptId);
    }

    /**
     * Records a ThesaurusMatch entity for audit.
     */
    public function recordMatch(
        Keyword $keyword,
        ThesaurusConcept $concept,
        string $method,
        float $confidence,
        string $status = 'automatic'
    ): ThesaurusMatch {
        $match = new ThesaurusMatch();
        $match->setEntityType('keyword');
        $match->setEntityId($keyword->getId());
        $match->setKeyword($keyword);
        $match->setOriginalValue($keyword->getKeywordOriginal());
        $match->setNormalizedValue($keyword->getKeywordNormalized());
        $match->setConcept($concept);
        $match->setConfidence($confidence);
        $match->setMatchMethod($method);
        $match->setStatus($status);

        $this->em->persist($match);
        return $match;
    }
}
