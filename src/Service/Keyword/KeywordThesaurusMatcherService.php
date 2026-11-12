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

    /** @var array<string, string> normalizedLabel → grafia original (para detectar siglas no fuzzy) */
    private array $displayByNorm = [];

    /**
     * Candidatos da comparação aproximada por letra inicial, na ordem rótulos → conceitos:
     * letra => lista de [forma normalizada, concept_id]. Montado uma vez em loadMaps().
     *
     * @var array<string, list<array{string, int}>>
     */
    private array $candidatesByFirstChar = [];

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
        $this->displayByNorm = [];
        $this->candidatesByFirstChar = [];

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
            $this->displayByNorm[$row['normalized_label']] ??= $row['preferred_label'];
        }

        // Load labels
        $labelRows = $conn->fetchAllAssociative(
            'SELECT tl.normalized_label, tl.label, tl.concept_id FROM thesaurus_label tl
             JOIN thesaurus_concept tc ON tl.concept_id = tc.id
             WHERE tc.scheme_id = ?',
            [$schemeId]
        );
        foreach ($labelRows as $row) {
            $this->labelMap[$row['normalized_label']] = (int)$row['concept_id'];
            $this->displayByNorm[$row['normalized_label']] ??= $row['label'];
        }

        // Mesma ordem e mesmo conceito por forma que a lista única anterior (rótulo tem prioridade)
        foreach ($this->labelMap + $this->conceptMap as $norm => $cid) {
            $norm = (string) $norm;
            $this->candidatesByFirstChar[mb_substr($norm, 0, 1)][] = [$norm, $cid];
        }

        $this->mapsLoaded = true;
    }

    /**
     * Só a correspondência exata (rótulo, depois conceito), sem a comparação aproximada.
     * É o que a etapa 3 do tratamento usa; a aproximada fica para a etapa 4.
     *
     * @return array{conceptId: int, conceptLabel: string, method: string, score: float, ambiguous: bool}|null
     */
    public function matchExact(Keyword $keyword): ?array
    {
        if (!$this->mapsLoaded) {
            $this->loadMaps();
        }

        $normalized = $keyword->getKeywordNormalized();
        if ($normalized === '') {
            return null;
        }

        foreach (['exact_label' => $this->labelMap, 'exact_concept' => $this->conceptMap] as $method => $map) {
            if (isset($map[$normalized])) {
                $cid = $map[$normalized];
                return [
                    'conceptId' => $cid,
                    'conceptLabel' => $this->conceptById[$cid]['preferred_label'] ?? '',
                    'method' => $method,
                    'score' => 100.0,
                    'ambiguous' => false,
                ];
            }
        }

        return null;
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

        // 1 e 2. Rótulo exato, depois conceito exato
        $exact = $this->matchExact($keyword);
        if ($exact !== null) {
            return $exact;
        }

        // 3. Fuzzy matching against all labels and concept labels
        // Melhor score por conceito: dois rótulos do MESMO conceito não configuram empate
        $scoreByConcept = [];
        $display = $keyword->getKeywordDisplay() ?? $keyword->getKeywordOriginal();

        foreach ($this->candidatesByFirstChar[mb_substr($normalized, 0, 1)] ?? [] as [$candidateNorm, $cid]) {
            $score = $this->fuzzyMatcher->getSimilarityScore(
                $normalized,
                $candidateNorm,
                $display,
                $this->displayByNorm[$candidateNorm] ?? null
            );
            if ($score > ($scoreByConcept[$cid] ?? 0.0)) {
                $scoreByConcept[$cid] = $score;
            }
        }

        arsort($scoreByConcept);
        $bestConceptId = array_key_first($scoreByConcept);
        $bestScore = $bestConceptId !== null ? $scoreByConcept[$bestConceptId] : 0.0;
        $secondBestScore = (float)(array_values($scoreByConcept)[1] ?? 0.0);

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
