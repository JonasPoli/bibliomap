<?php

namespace App\Service\KeywordTreatment;

/**
 * DTO holding all configurable options for a keyword treatment job run.
 */
final class KeywordTreatmentOptions
{
    public function __construct(
        public bool $dryRun = true,
        public ?int $limit = 5000,
        public int $batchSize = 500,
        public float $minAutoScore = 95.0,
        public float $minReviewScore = 75.0,
        public bool $autoCreateConcepts = false,
        public bool $processInvalids = true,
        public bool $processExact = true,
        public bool $processThesaurus = true,
        public bool $processFuzzy = true,
    ) {}

    public static function fromRequest(array $params): self
    {
        return new self(
            dryRun: ($params['mode'] ?? 'dry_run') !== 'execute',
            limit: isset($params['limit']) ? (int)$params['limit'] : 5000,
            batchSize: isset($params['batch_size']) ? (int)$params['batch_size'] : 500,
            minAutoScore: isset($params['min_score']) ? (float)$params['min_score'] : 95.0,
            minReviewScore: isset($params['min_review_score']) ? (float)$params['min_review_score'] : 75.0,
            autoCreateConcepts: (bool)($params['auto_create_concepts'] ?? false),
            processInvalids: (bool)($params['process_invalids'] ?? true),
            processExact: (bool)($params['process_exact'] ?? true),
            processThesaurus: (bool)($params['process_thesaurus'] ?? true),
            processFuzzy: (bool)($params['process_fuzzy'] ?? true),
        );
    }
}
