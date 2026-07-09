<?php

namespace App\Service\Keyword;

use App\Entity\Keyword;
use App\Entity\ThesaurusConcept;

/**
 * Centralized service for resolving the effective label, key, and concept
 * for any Keyword entity, following the cascading resolution rule:
 *
 * 1. thesaurusConcept.preferredLabel  (official grouper)
 * 2. keywordConcept.keywordDisplay    (legacy fallback)
 * 3. keyword.keywordDisplay
 * 4. keyword.keywordOriginal
 */
final class KeywordResolverService
{
    /**
     * Returns the display label to use in reports and UI.
     */
    public function getEffectiveKeywordLabel(Keyword $keyword): string
    {
        if ($keyword->getThesaurusConcept()) {
            return $keyword->getThesaurusConcept()->getPreferredLabel();
        }

        if ($keyword->getKeywordConcept()) {
            return $keyword->getKeywordConcept()->getKeywordDisplay()
                ?: $keyword->getKeywordConcept()->getKeywordOriginal();
        }

        return $keyword->getKeywordDisplay() ?: $keyword->getKeywordOriginal();
    }

    /**
     * Returns a unique aggregation key to avoid collisions between
     * ThesaurusConcepts and Keywords that may share the same label.
     *
     * Format: "thesaurus:{id}" | "keyword:{id}"
     */
    public function getEffectiveKeywordKey(Keyword $keyword): string
    {
        if ($keyword->getThesaurusConcept()) {
            return 'thesaurus:' . $keyword->getThesaurusConcept()->getId();
        }

        if ($keyword->getKeywordConcept()) {
            return 'keyword:' . $keyword->getKeywordConcept()->getId();
        }

        return 'keyword:' . $keyword->getId();
    }

    /**
     * Returns the numeric ID of the effective grouper entity.
     */
    public function getEffectiveKeywordId(Keyword $keyword): int
    {
        if ($keyword->getThesaurusConcept()) {
            return $keyword->getThesaurusConcept()->getId();
        }

        if ($keyword->getKeywordConcept()) {
            return $keyword->getKeywordConcept()->getId();
        }

        return $keyword->getId();
    }

    /**
     * Returns the ThesaurusConcept if available, null otherwise.
     */
    public function getEffectiveConcept(Keyword $keyword): ?ThesaurusConcept
    {
        return $keyword->getThesaurusConcept();
    }

    /**
     * Alias for getEffectiveKeywordLabel — used in report contexts.
     */
    public function getDisplayLabelForReports(Keyword $keyword): string
    {
        return $this->getEffectiveKeywordLabel($keyword);
    }

    /**
     * Generates the SQL COALESCE fragment for aggregating keywords by their
     * effective concept. Use this in raw SQL queries.
     *
     * @param string $kwAlias The SQL alias for the keyword table (e.g., 'k')
     * @return string SQL fragment like "COALESCE(k.thesaurus_concept_id, k.keyword_concept_id, k.id)"
     */
    public function buildSqlCoalesceId(string $kwAlias = 'k'): string
    {
        return "COALESCE({$kwAlias}.thesaurus_concept_id, {$kwAlias}.keyword_concept_id, {$kwAlias}.id)";
    }

    /**
     * Generates the SQL for resolving the effective display label.
     * Requires JOINs to thesaurus_concept (tc) and keyword concept (kc).
     *
     * @param string $kwAlias   Alias for keyword table
     * @param string $tcAlias   Alias for LEFT JOIN thesaurus_concept
     * @param string $kcAlias   Alias for LEFT JOIN keyword AS concept keyword
     * @return string SQL fragment
     */
    public function buildSqlCoalesceLabel(string $kwAlias = 'k', string $tcAlias = 'tc', string $kcAlias = 'kc'): string
    {
        return "COALESCE({$tcAlias}.preferred_label, {$kcAlias}.keyword_display, {$kwAlias}.keyword_display, {$kwAlias}.keyword_original)";
    }

    /**
     * Returns the standard LEFT JOINs needed for label resolution in raw SQL.
     *
     * @param string $kwAlias Alias for keyword table
     * @param string $tcAlias Alias for thesaurus_concept table
     * @param string $kcAlias Alias for keyword concept table
     * @return string SQL JOIN fragment
     */
    public function buildSqlJoins(string $kwAlias = 'k', string $tcAlias = 'tc', string $kcAlias = 'kc'): string
    {
        return "LEFT JOIN thesaurus_concept {$tcAlias} ON {$tcAlias}.id = {$kwAlias}.thesaurus_concept_id "
             . "LEFT JOIN keyword {$kcAlias} ON {$kcAlias}.id = {$kwAlias}.keyword_concept_id";
    }
}
