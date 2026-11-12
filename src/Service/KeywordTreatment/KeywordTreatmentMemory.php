<?php

namespace App\Service\KeywordTreatment;

use Doctrine\DBAL\Connection;

/**
 * O que processamentos anteriores já registraram, para que rodar o tratamento
 * todo dia não repita sugestões e alertas:
 * - pares palavra-chave × conceito com sugestão pendente (fuzzy_review_required/pending)
 *   ou recusada (manual_rejected): a etapa 4 não registra nem conta de novo;
 * - pares palavra-chave × motivo com alerta de suspeito aplicado (suspicious/applied):
 *   a etapa 1 não registra nem conta de novo.
 */
class KeywordTreatmentMemory
{
    /** @var array<int, array<int, true>> palavra-chave => conceitos sugeridos */
    private array $suggestions = [];

    /** @var array<int, array<string, true>> palavra-chave => motivos de suspeito */
    private array $suspicious = [];

    /**
     * @param iterable<array{keyword_id: int|string, concept_id: int|string|null}> $suggestionRows
     * @param iterable<array{keyword_id: int|string, reason: string|null}> $suspiciousRows
     */
    public function __construct(iterable $suggestionRows = [], iterable $suspiciousRows = [])
    {
        foreach ($suggestionRows as $row) {
            if ($row['concept_id'] !== null) {
                $this->rememberSuggestion((int) $row['keyword_id'], (int) $row['concept_id']);
            }
        }
        foreach ($suspiciousRows as $row) {
            $this->rememberSuspicious((int) $row['keyword_id'], (string) $row['reason']);
        }
    }

    public static function load(Connection $conn): self
    {
        $suggestions = $conn->fetchAllAssociative(
            "SELECT keyword_id, new_thesaurus_concept_id AS concept_id
             FROM keyword_treatment_log
             WHERE new_thesaurus_concept_id IS NOT NULL
               AND ((action = 'fuzzy_review_required' AND status = 'pending') OR action = 'manual_rejected')"
        );
        $suspicious = $conn->fetchAllAssociative(
            "SELECT keyword_id, reason FROM keyword_treatment_log WHERE action = 'suspicious' AND status = 'applied'"
        );

        return new self($suggestions, $suspicious);
    }

    public function hasSuggestion(int $keywordId, int $conceptId): bool
    {
        return isset($this->suggestions[$keywordId][$conceptId]);
    }

    public function rememberSuggestion(int $keywordId, int $conceptId): void
    {
        $this->suggestions[$keywordId][$conceptId] = true;
    }

    public function hasSuspicious(int $keywordId, string $reason): bool
    {
        return isset($this->suspicious[$keywordId][$reason]);
    }

    public function rememberSuspicious(int $keywordId, string $reason): void
    {
        $this->suspicious[$keywordId][$reason] = true;
    }

    /**
     * Fusão: os logs do termo apagado passam ao que fica, e a memória acompanha.
     * Indexada por palavra-chave: custa só o que o termo apagado tem, não o tamanho da memória inteira.
     */
    public function moveKeyword(int $fromId, int $toId): void
    {
        if (isset($this->suggestions[$fromId])) {
            $this->suggestions[$toId] = ($this->suggestions[$toId] ?? []) + $this->suggestions[$fromId];
            unset($this->suggestions[$fromId]);
        }
        if (isset($this->suspicious[$fromId])) {
            $this->suspicious[$toId] = ($this->suspicious[$toId] ?? []) + $this->suspicious[$fromId];
            unset($this->suspicious[$fromId]);
        }
    }
}
