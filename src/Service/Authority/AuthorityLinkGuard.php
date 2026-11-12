<?php

namespace App\Service\Authority;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Conta os documentos ligados a um autor, uma palavra-chave ou uma instituição. Excluir ou descartar
 * é recusado quando a contagem é maior que zero (change tesauro-em-camadas, fase 0): o vínculo do documento
 * (e o original_name / original_term dele) nunca é apagado por uma exclusão de cadastro.
 */
class AuthorityLinkGuard
{
    /** tipo => [tabela de vínculo, coluna do id da entidade] */
    private const LINKS = [
        'author'      => ['document_author', 'author_identity_id'],
        'keyword'     => ['document_keyword', 'keyword_id'],
        'institution' => ['documento_instituicoes', 'institution_id'],
    ];

    private const NOUNS = [
        'author'      => 'O autor',
        'keyword'     => 'A palavra-chave',
        'institution' => 'A instituição',
    ];

    public function __construct(private readonly EntityManagerInterface $em) {}

    /** Documentos distintos ligados à entidade. */
    public function documentCount(string $entityType, int $id): int
    {
        [$table, $col] = $this->link($entityType);

        return (int) $this->em->getConnection()->fetchOne(
            "SELECT COUNT(DISTINCT document_id) FROM {$table} WHERE {$col} = ?",
            [$id]
        );
    }

    /**
     * Contagem para vários ids numa consulta só (sem N+1). Só os ids com documento aparecem no resultado.
     *
     * @param int[] $ids
     * @return array<int,int> id => documentos distintos
     */
    public function documentCounts(string $entityType, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        [$table, $col] = $this->link($entityType);

        $rows = $this->em->getConnection()->fetchAllKeyValue(
            "SELECT {$col}, COUNT(DISTINCT document_id) FROM {$table} WHERE {$col} IN (?) GROUP BY {$col}",
            [$ids],
            [ArrayParameterType::INTEGER]
        );

        $counts = [];
        foreach ($rows as $id => $n) {
            $counts[(int) $id] = (int) $n;
        }

        return $counts;
    }

    /** Mensagem de recusa em pt-BR, com a contagem. $verb: "excluída", "descartada" ou "movida". */
    public function blockMessage(string $entityType, string $label, int $documents, string $verb = 'excluída'): string
    {
        $noun = self::NOUNS[$entityType] ?? 'O registro';
        $docs = $documents === 1 ? '1 documento' : $documents . ' documentos';
        // Concordância do particípio com o substantivo (autor/instituição: o/a)
        $verb = $entityType === 'author' ? preg_replace('/a$/', 'o', $verb) : $verb;

        return sprintf("%s '%s' está ligad%s a %s; não pode ser %s.", $noun, $label, $entityType === 'author' ? 'o' : 'a', $docs, $verb);
    }

    private function link(string $entityType): array
    {
        return self::LINKS[$entityType] ?? throw new \InvalidArgumentException("Tipo inválido: {$entityType}");
    }
}
