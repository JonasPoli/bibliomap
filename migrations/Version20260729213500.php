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
        // 1. Add foundation and extinction year columns if not exist
        $this->addSql('ALTER TABLE instituicoes_ensino ADD COLUMN IF NOT EXISTS ano_fundacao INT DEFAULT NULL, ADD COLUMN IF NOT EXISTS ano_extincao INT DEFAULT NULL');
        $this->addSql('ALTER TABLE paises ADD COLUMN IF NOT EXISTS ano_fundacao INT DEFAULT NULL, ADD COLUMN IF NOT EXISTS ano_extincao INT DEFAULT NULL');

        // 2. Create qualis_journal_variacoes_nome
        $this->addSql('CREATE TABLE IF NOT EXISTS qualis_journal_variacoes_nome (
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
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS qualis_journal_variacoes_nome');
        $this->addSql('ALTER TABLE instituicoes_ensino DROP COLUMN IF EXISTS ano_fundacao, DROP COLUMN IF EXISTS ano_extincao');
        $this->addSql('ALTER TABLE paises DROP COLUMN IF EXISTS ano_fundacao, DROP COLUMN IF EXISTS ano_extincao');
    }
}
