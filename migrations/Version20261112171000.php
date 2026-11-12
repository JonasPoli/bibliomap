<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Índices das colunas de id efetivo (change tesauro-em-camadas, fase 1). Separada da migration das colunas
 * porque é mais barato criar os índices DEPOIS de `app:authority:rebuild` ter preenchido os valores.
 * ALGORITHM=INPLACE, LOCK=NONE: leituras e gravações continuam durante a criação. Só cria o que não existe.
 */
final class Version20261112171000 extends AbstractMigration
{
    private const INDEXES = [
        'document_author' => [
            'idx_da_doc_effective' => 'document_id, effective_author_id',
            'idx_da_effective' => 'effective_author_id',
        ],
        'document_keyword' => [
            'idx_dk_doc_effective' => 'document_id, keyword_id, effective_keyword_id',
            'idx_dk_effective' => 'effective_keyword_id',
        ],
    ];

    public function getDescription(): string
    {
        return 'Índices (document_id, efetivo) e (efetivo) em document_author e document_keyword';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    private function indexExists(string $table, string $index): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, $index]
        ) > 0;
    }

    public function up(Schema $schema): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            $parts = [];
            foreach ($indexes as $name => $cols) {
                if (!$this->indexExists($table, $name)) {
                    $parts[] = "ADD INDEX {$name} ({$cols})";
                }
            }
            if ($parts) {
                $this->addSql("ALTER TABLE {$table} " . implode(', ', $parts) . ', ALGORITHM=INPLACE, LOCK=NONE');
            }
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            $parts = [];
            foreach (array_keys($indexes) as $name) {
                if ($this->indexExists($table, $name)) {
                    $parts[] = "DROP INDEX {$name}";
                }
            }
            if ($parts) {
                $this->addSql("ALTER TABLE {$table} " . implode(', ', $parts) . ', ALGORITHM=INPLACE, LOCK=NONE');
            }
        }
    }
}
