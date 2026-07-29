<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration for Journal Variations table and foundation/extinction year columns.
 */
final class Version20260729213500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add qualis_journal_variacoes_nome table and foundation/extinction year columns to instituicoes_ensino and paises';
    }

    public function up(Schema $schema): void
    {
        $conn = $this->connection;

        // 1. Check instituicoes_ensino columns
        $instCols = array_map(fn($c) => strtolower($c['Field']), $conn->fetchAllAssociative("SHOW COLUMNS FROM instituicoes_ensino"));
        if (!in_array('ano_fundacao', $instCols)) {
            $this->addSql('ALTER TABLE instituicoes_ensino ADD ano_fundacao INT DEFAULT NULL');
        }
        if (!in_array('ano_extincao', $instCols)) {
            $this->addSql('ALTER TABLE instituicoes_ensino ADD ano_extincao INT DEFAULT NULL');
        }

        // 2. Check paises columns
        $paisCols = array_map(fn($c) => strtolower($c['Field']), $conn->fetchAllAssociative("SHOW COLUMNS FROM paises"));
        if (!in_array('ano_fundacao', $paisCols)) {
            $this->addSql('ALTER TABLE paises ADD ano_fundacao INT DEFAULT NULL');
        }
        if (!in_array('ano_extincao', $paisCols)) {
            $this->addSql('ALTER TABLE paises ADD ano_extincao INT DEFAULT NULL');
        }

        // 3. Check qualis_journal_variacoes_nome table
        $tables = array_map(fn($t) => array_values($t)[0], $conn->fetchAllAssociative("SHOW TABLES LIKE 'qualis_journal_variacoes_nome'"));
        if (empty($tables)) {
            $this->addSql('CREATE TABLE qualis_journal_variacoes_nome (
                id INT AUTO_INCREMENT NOT NULL,
                journal_id INT NOT NULL,
                variation_name VARCHAR(500) NOT NULL,
                normalized_name VARCHAR(500) NOT NULL,
                variation_type VARCHAR(50) DEFAULT \'alternative\' NOT NULL,
                status TINYINT(1) DEFAULT 1 NOT NULL,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                INDEX IDX_JOURNAL_VAR_NORM (normalized_name),
                INDEX IDX_JOURNAL_VAR_JOURNAL (journal_id),
                PRIMARY KEY(id),
                CONSTRAINT FK_JOURNAL_VAR_JOURNAL FOREIGN KEY (journal_id) REFERENCES qualis_journal (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        } else {
            // Dummy SQL if nothing to execute so Doctrine doesn't throw empty SQL error
            $this->addSql('SELECT 1');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS qualis_journal_variacoes_nome');
    }
}
