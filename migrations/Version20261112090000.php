<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Índices compostos para as agregações dos relatórios (revisão de 12/11/2026).
 * - document_keyword (document_id, keyword_id): a junção documento → palavra-chave passa a ser
 *   resolvida só pelo índice (medido no projeto 13 local: de ~120 ms para ~95 ms na contagem de
 *   conceitos e de ~300 ms para ~210 ms na lista do relatório).
 * - document (project_id, year, cited_by): somas de citações por projeto e por ano sem ler a linha
 *   do documento, que carrega resumo e referências (de ~10 ms para ~2 ms).
 * Só cria índices: nenhum dado muda.
 */
final class Version20261112090000 extends AbstractMigration
{
    private const INDEXES = [
        'idx_dk_document_keyword' => ['document_keyword', 'document_id, keyword_id'],
        'idx_document_project_year_cited' => ['document', 'project_id, year, cited_by'],
    ];

    public function getDescription(): string
    {
        return 'Índices compostos document_keyword (document_id, keyword_id) e document (project_id, year, cited_by)';
    }

    public function up(Schema $schema): void
    {
        foreach (self::INDEXES as $name => [$table, $columns]) {
            if (!$this->indexExists($table, $name)) {
                $this->addSql(sprintf('CREATE INDEX %s ON %s (%s)', $name, $table, $columns));
            }
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::INDEXES as $name => [$table]) {
            if ($this->indexExists($table, $name)) {
                $this->addSql(sprintf('DROP INDEX %s ON %s', $name, $table));
            }
        }
    }

    private function indexExists(string $table, string $name): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [$table, $name]
        ) > 0;
    }
}
