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
        // Negativo para conceitos do tesauro: não colide com IDs da tabela keyword
        if ($keyword->getThesaurusConcept()) {
            return -$keyword->getThesaurusConcept()->getId();
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
     * Fragmento SQL do id efetivo da palavra-chave, para agrupar nas análises.
     *
     * Com o alias do vínculo (`document_keyword`, ex.: 'dk') devolve a coluna `effective_keyword_id`, já
     * calculada e indexada (positivo = keyword.id do conceito; negativo = -thesaurus_concept.id), igual à
     * expressão antiga. Sem o alias do vínculo, mantém a expressão copiada de sempre sobre `keyword`
     * (para quem ainda não migrou).
     *
     * @param string      $kwAlias Alias da tabela keyword (ex.: 'k'); usado só sem $dkAlias
     * @param string|null $dkAlias Alias de document_keyword (ex.: 'dk')
     */
    public function buildSqlCoalesceId(string $kwAlias = 'k', ?string $dkAlias = null): string
    {
        if ($dkAlias !== null) {
            return "{$dkAlias}.effective_keyword_id";
        }

        return "IF({$kwAlias}.thesaurus_concept_id IS NULL, COALESCE({$kwAlias}.keyword_concept_id, {$kwAlias}.id), -{$kwAlias}.thesaurus_concept_id)";
    }

    /**
     * LEFT JOINs do rótulo a partir do id efetivo do vínculo: `tc` (conceito do tesauro, só quando o id é
     * negativo) e `ke` (palavra-chave do conceito, só quando o id difere da própria palavra do vínculo).
     * O NULL no ON evita buscas inúteis. Rótulo: COALESCE(tc.preferred_label, ke.keyword_display, k.keyword_display).
     */
    public function buildSqlEffectiveJoins(string $dkAlias = 'dk', string $tcAlias = 'tc', string $keAlias = 'ke'): string
    {
        return "LEFT JOIN thesaurus_concept {$tcAlias} ON {$tcAlias}.id = IF({$dkAlias}.effective_keyword_id < 0, -{$dkAlias}.effective_keyword_id, NULL) "
             . "LEFT JOIN keyword {$keAlias} ON {$keAlias}.id = IF({$dkAlias}.effective_keyword_id <> {$dkAlias}.keyword_id, {$dkAlias}.effective_keyword_id, NULL)";
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
