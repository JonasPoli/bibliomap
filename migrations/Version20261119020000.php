<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona classification_color_labels (JSON) em bibliometric_project para títulos dos agrupamentos por cor';
    }

    public function isTransactional(): bool
    {
        return false;
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
        if (!$this->columnExists('bibliometric_project', 'classification_color_labels')) {
            $this->addSql('ALTER TABLE bibliometric_project ADD classification_color_labels JSON DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if ($this->columnExists('bibliometric_project', 'classification_color_labels')) {
            $this->addSql('ALTER TABLE bibliometric_project DROP COLUMN classification_color_labels');
        }
    }
}
