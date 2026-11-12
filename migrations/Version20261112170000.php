<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Colunas derivadas do id efetivo e tabela de mapeamentos por escopo (change tesauro-em-camadas, fase 1).
 *
 * - document_author.effective_author_id e document_keyword.effective_keyword_id: 0 = ainda não calculado;
 *   o preenchimento é feito por `app:authority:rebuild`. Palavra-chave mantém a convenção de hoje:
 *   positivo = keyword.id efetivo, negativo = -thesaurus_concept.id. ADD COLUMN com ALGORITHM=INSTANT não
 *   reescreve as tabelas (1,5 milhão e 560 mil linhas em produção).
 * - authority_mapping: decisões de agrupamento por escopo (global, user, project). Sem chave estrangeira.
 * - Os índices das colunas efetivas ficam na migration seguinte (Version20261112171000): criá-los depois do
 *   preenchimento custa menos.
 *
 * Idempotente: confere o information_schema antes de criar e antes de remover.
 */
final class Version20261112170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Colunas effective_author_id e effective_keyword_id (INSTANT) e tabela authority_mapping';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    private function tableExists(string $table): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        ) > 0;
    }

    private function columnExists(string $table, string $column): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        ) > 0;
    }

    public function up(Schema $schema): void
    {
        if (!$this->columnExists('document_author', 'effective_author_id')) {
            $this->addSql('ALTER TABLE document_author ADD effective_author_id INT NOT NULL DEFAULT 0, ALGORITHM=INSTANT');
        }
        if (!$this->columnExists('document_keyword', 'effective_keyword_id')) {
            $this->addSql('ALTER TABLE document_keyword ADD effective_keyword_id INT NOT NULL DEFAULT 0, ALGORITHM=INSTANT');
        }
        if (!$this->tableExists('authority_mapping')) {
            $this->addSql('CREATE TABLE authority_mapping (
                id INT AUTO_INCREMENT NOT NULL,
                entity_type VARCHAR(20) NOT NULL,
                scope VARCHAR(10) NOT NULL,
                scope_id INT NOT NULL DEFAULT 0,
                source_id INT NOT NULL,
                target_id INT NOT NULL,
                origin VARCHAR(20) NOT NULL,
                created_by INT DEFAULT NULL,
                created_at DATETIME NOT NULL,
                note VARCHAR(255) DEFAULT NULL,
                UNIQUE INDEX uniq_am_scope_source (entity_type, scope, scope_id, source_id),
                INDEX idx_am_source (entity_type, source_id),
                INDEX idx_am_target (entity_type, scope, scope_id, target_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, $index]
        ) > 0;
    }

    public function down(Schema $schema): void
    {
        // Se a migration dos índices não foi desfeita antes, o MySQL tiraria a coluna dos índices compostos em
        // silêncio e deixaria índices truncados; por isso eles saem primeiro.
        foreach (['document_author' => ['idx_da_doc_effective', 'idx_da_effective'], 'document_keyword' => ['idx_dk_doc_effective', 'idx_dk_effective']] as $table => $names) {
            foreach ($names as $name) {
                if ($this->indexExists($table, $name)) {
                    $this->addSql("ALTER TABLE {$table} DROP INDEX {$name}, ALGORITHM=INPLACE, LOCK=NONE");
                }
            }
        }
        if ($this->tableExists('authority_mapping')) {
            $this->addSql('DROP TABLE authority_mapping');
        }
        if ($this->columnExists('document_keyword', 'effective_keyword_id')) {
            $this->addSql('ALTER TABLE document_keyword DROP COLUMN effective_keyword_id');
        }
        if ($this->columnExists('document_author', 'effective_author_id')) {
            $this->addSql('ALTER TABLE document_author DROP COLUMN effective_author_id');
        }
    }
}
