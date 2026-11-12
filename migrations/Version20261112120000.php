<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Telas administrativas e rotina do Qualis (revisão de 12/11/2026).
 * - keyword (keyword_original): a lista de /admin/keywords ordena por esse campo e lia a tabela inteira a cada página.
 * - keyword (status, keyword_original): fila de revisão (status = 0, em ordem alfabética) e sua contagem.
 * - qualis_journal.qualis_checked_at: quando a Sucupira respondeu pela última vez sobre o estrato da revista,
 *   para a rotina noturna consultar de novo, aos poucos, as revistas que ficaram sem estrato.
 * Só cria índices e uma coluna vazia: nenhum dado muda.
 */
final class Version20261112120000 extends AbstractMigration
{
    private const INDEXES = [
        'IDX_kw_original' => ['keyword', 'keyword_original'],
        'IDX_kw_status_original' => ['keyword', 'status, keyword_original'],
    ];

    public function getDescription(): string
    {
        return 'Índices em keyword (keyword_original; status, keyword_original) e coluna qualis_journal.qualis_checked_at';
    }

    public function up(Schema $schema): void
    {
        foreach (self::INDEXES as $name => [$table, $columns]) {
            if (!$this->indexExists($table, $name)) {
                $this->addSql(sprintf('CREATE INDEX %s ON %s (%s)', $name, $table, $columns));
            }
        }
        if (!$this->columnExists('qualis_journal', 'qualis_checked_at')) {
            $this->addSql('ALTER TABLE qualis_journal ADD qualis_checked_at DATETIME DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::INDEXES as $name => [$table]) {
            if ($this->indexExists($table, $name)) {
                $this->addSql(sprintf('DROP INDEX %s ON %s', $name, $table));
            }
        }
        if ($this->columnExists('qualis_journal', 'qualis_checked_at')) {
            $this->addSql('ALTER TABLE qualis_journal DROP qualis_checked_at');
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

    private function columnExists(string $table, string $column): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column]
        ) > 0;
    }
}
