<?php

namespace App\Service\Classification;

use App\Service\Import\TextNormalizer;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Expansão dos termos de um grupo com "Utilizar Tesauro Global" ligado.
 *
 * - Rótulos do tesauro: todos os rótulos dos conceitos cujo rótulo ou rótulo preferido é igual ao termo.
 * - Variações (só no modo MODE_WITH_VARIATIONS): os variation_name ativos de palavra_chave_variacoes_nome
 *   das palavras-chave ativas cuja forma normalizada (TextNormalizer::normalizeForComparison) é igual à do termo.
 *
 * Uma falha de consulta sobe: quem chama decide (a classificação para antes de apagar os resultados).
 */
class ClassificationTermExpander
{
    /** Só os rótulos do tesauro: o comportamento até 11/2026 (a consulta das variações falhava em silêncio). */
    public const MODE_THESAURUS_ONLY = 1;
    /** Rótulos do tesauro e variações ativas da palavra-chave do termo. */
    public const MODE_WITH_VARIATIONS = 2;

    private TextNormalizer $normalizer;

    public function __construct(private readonly Connection $conn)
    {
        $this->normalizer = new TextNormalizer();
    }

    /**
     * @param string[] $terms
     * @return string[] termos originais e expandidos, em minúsculas, sem espaços nas pontas e sem duplicatas
     */
    public function expand(array $terms, int $mode = self::MODE_WITH_VARIATIONS): array
    {
        $expanded = [];

        foreach ($terms as $term) {
            $tNorm = mb_strtolower(trim((string) $term));
            if ($tNorm === '') {
                continue;
            }
            $expanded[] = $tNorm;

            foreach ($this->thesaurusLabels($tNorm) as $label) {
                $expanded[] = $label;
            }

            if ($mode === self::MODE_WITH_VARIATIONS) {
                foreach ($this->keywordVariations($term) as $variation) {
                    $expanded[] = $variation;
                }
            }
        }

        return array_values(array_unique($expanded));
    }

    /** @return string[] */
    private function thesaurusLabels(string $tNorm): array
    {
        $conceptIds = $this->conn->fetchFirstColumn(
            'SELECT concept_id FROM thesaurus_label WHERE LOWER(label) = ? OR LOWER(normalized_label) = ?',
            [$tNorm, $tNorm]
        );
        if (empty($conceptIds)) {
            $conceptIds = $this->conn->fetchFirstColumn(
                'SELECT id FROM thesaurus_concept WHERE LOWER(preferred_label) = ? OR LOWER(normalized_label) = ?',
                [$tNorm, $tNorm]
            );
        }
        if (empty($conceptIds)) {
            return [];
        }

        $labels = $this->conn->fetchFirstColumn(
            'SELECT label FROM thesaurus_label WHERE concept_id IN (?)',
            [array_map('intval', $conceptIds)],
            [ArrayParameterType::INTEGER]
        );

        return $this->clean($labels);
    }

    /**
     * Variações ativas das palavras-chave ativas (autor e indexada) com a mesma forma normalizada do termo.
     * Usa variation_name, e não normalized_name: o texto do documento não é normalizado.
     *
     * @return string[]
     */
    private function keywordVariations(string $term): array
    {
        $normalized = $this->normalizer->normalizeForComparison($term);
        if ($normalized === '') {
            return [];
        }

        $variations = $this->conn->fetchFirstColumn(
            'SELECT DISTINCT v.variation_name
             FROM keyword k
             JOIN palavra_chave_variacoes_nome v ON v.keyword_id = k.id
             WHERE k.keyword_normalized = ? AND k.status = 1 AND v.status = 1',
            [$normalized]
        );

        return $this->clean($variations);
    }

    /**
     * @param iterable<mixed> $values
     * @return string[]
     */
    private function clean(iterable $values): array
    {
        $out = [];
        foreach ($values as $v) {
            $v = mb_strtolower(trim((string) $v));
            if ($v !== '') {
                $out[] = $v;
            }
        }

        return $out;
    }
}
