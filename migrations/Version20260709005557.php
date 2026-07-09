<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260709005557 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create author and keyword variation tables, add status fields, and add original_term to document_keyword.';
    }

    public function up(Schema $schema): void
    {
        // 1. Add status columns to author and keyword
        $this->addSql('ALTER TABLE author ADD status TINYINT(1) DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE keyword ADD status TINYINT(1) DEFAULT 1 NOT NULL');

        // 2. Create autor_variacoes_nome table
        $this->addSql('CREATE TABLE autor_variacoes_nome (
            id INT AUTO_INCREMENT NOT NULL,
            author_id INT NOT NULL,
            variation_name VARCHAR(255) NOT NULL,
            normalized_name VARCHAR(255) NOT NULL,
            variation_type VARCHAR(100) DEFAULT NULL,
            status TINYINT(1) DEFAULT 1 NOT NULL,
            INDEX IDX_FBF7FEE4F675F31B (author_id),
            INDEX idx_author_var_normalized (normalized_name),
            CONSTRAINT FK_FBF7FEE4F675F31B FOREIGN KEY (author_id) REFERENCES author (id) ON DELETE CASCADE,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // 3. Create palavra_chave_variacoes_nome table
        $this->addSql('CREATE TABLE palavra_chave_variacoes_nome (
            id INT AUTO_INCREMENT NOT NULL,
            keyword_id INT NOT NULL,
            variation_name VARCHAR(255) NOT NULL,
            normalized_name VARCHAR(255) NOT NULL,
            variation_type VARCHAR(100) DEFAULT NULL,
            status TINYINT(1) DEFAULT 1 NOT NULL,
            INDEX IDX_B8C80521115F4956 (keyword_id),
            INDEX idx_keyword_var_normalized (normalized_name),
            CONSTRAINT FK_B8C80521115F4956 FOREIGN KEY (keyword_id) REFERENCES keyword (id) ON DELETE CASCADE,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // 4. Add original_term column to document_keyword (initially NULL)
        $this->addSql('ALTER TABLE document_keyword ADD original_term VARCHAR(255) DEFAULT NULL');

        // 5. Populate original_term with the current term of the linked keyword
        $this->addSql('UPDATE document_keyword dk JOIN keyword k ON dk.keyword_id = k.id SET dk.original_term = k.term');

        // 6. Make original_term NOT NULL
        $this->addSql('ALTER TABLE document_keyword MODIFY original_term VARCHAR(255) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document_keyword DROP COLUMN original_term');
        $this->addSql('DROP TABLE autor_variacoes_nome');
        $this->addSql('DROP TABLE palavra_chave_variacoes_nome');
        $this->addSql('ALTER TABLE author DROP COLUMN status');
        $this->addSql('ALTER TABLE keyword DROP COLUMN status');
    }
}
