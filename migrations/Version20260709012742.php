<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration for new Author & Keyword normalization schema.
 */
final class Version20260709012742 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Refactors authors and keywords to support advanced normalization, display names, and concepts.';
    }

    public function up(Schema $schema): void
    {
        // ── 1. Create new Author Identity & Variant Tables ───────────────────
        $this->addSql('
            CREATE TABLE author_identity (
                id INT AUTO_INCREMENT NOT NULL,
                preferred_name VARCHAR(255) NOT NULL,
                normalized_name VARCHAR(255) NOT NULL,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                INDEX idx_author_identity_normalized (normalized_name),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        $this->addSql('
            CREATE TABLE author_name_variant (
                id INT AUTO_INCREMENT NOT NULL,
                author_identity_id INT NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                display_name VARCHAR(255) NOT NULL,
                normalized_name VARCHAR(255) NOT NULL,
                source VARCHAR(100) NOT NULL,
                confidence DOUBLE PRECISION NOT NULL,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                INDEX IDX_F87D397C11BF2E8B (author_identity_id),
                INDEX idx_author_variant_normalized (normalized_name),
                CONSTRAINT FK_F87D397C11BF2E8B FOREIGN KEY (author_identity_id) REFERENCES author_identity (id) ON DELETE CASCADE,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        $this->addSql('
            CREATE TABLE author_external_identifier (
                id INT AUTO_INCREMENT NOT NULL,
                author_identity_id INT NOT NULL,
                provider VARCHAR(50) NOT NULL,
                identifier VARCHAR(100) NOT NULL,
                url VARCHAR(255) NOT NULL,
                INDEX IDX_D2C5CCB311BF2E8B (author_identity_id),
                INDEX idx_author_ext_id_provider (provider, identifier),
                CONSTRAINT FK_D2C5CCB311BF2E8B FOREIGN KEY (author_identity_id) REFERENCES author_identity (id) ON DELETE CASCADE,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        // ── 2. Backfill existing authors into the new tables ──────────────────
        // Insert identities preserving their old IDs so document_author links don't break!
        $this->addSql('
            INSERT INTO author_identity (id, preferred_name, normalized_name, created_at, updated_at)
            SELECT id, name, normalized_name, created_at, created_at FROM author
        ');

        // Insert variant for their preferred name
        $this->addSql('
            INSERT INTO author_name_variant (author_identity_id, original_name, display_name, normalized_name, source, confidence, created_at, updated_at)
            SELECT id, name, name, normalized_name, \'import\', 1.0, created_at, created_at FROM author
        ');

        // Insert existing variations
        $this->addSql('
            INSERT INTO author_name_variant (author_identity_id, original_name, display_name, normalized_name, source, confidence, created_at, updated_at)
            SELECT author_id, variation_name, variation_name, normalized_name, \'manual\', 1.0, NOW(), NOW() FROM autor_variacoes_nome
        ');

        // Insert ORCID identifiers if they exist
        $this->addSql('
            INSERT INTO author_external_identifier (author_identity_id, provider, identifier, url)
            SELECT id, \'orcid\', orcid, CONCAT(\'https://orcid.org/\', orcid) 
            FROM author 
            WHERE orcid IS NOT NULL AND orcid != \'\'
        ');

        // ── 3. Refactor document_author table links ──────────────────────────
        $this->addSql('ALTER TABLE document_author DROP FOREIGN KEY FK_3CD69AEF675F31B');
        $this->addSql('ALTER TABLE document_author RENAME COLUMN author_id TO author_identity_id');
        $this->addSql('ALTER TABLE document_author ADD CONSTRAINT FK_3CD69AE11BF2E8B FOREIGN KEY (author_identity_id) REFERENCES author_identity (id) ON DELETE CASCADE');

        // ── 4. Drop old author tables ────────────────────────────────────────
        $this->addSql('DROP TABLE autor_variacoes_nome');
        $this->addSql('DROP TABLE author');

        // ── 5. Refactor keyword table columns and indices ────────────────────
        $this->addSql('ALTER TABLE keyword DROP INDEX UNIQ_5A93713B2DB098A38CDE5729');
        $this->addSql('ALTER TABLE keyword RENAME COLUMN term TO keyword_original');
        $this->addSql('ALTER TABLE keyword RENAME COLUMN normalized_term TO keyword_normalized');
        $this->addSql('ALTER TABLE keyword RENAME COLUMN type TO keyword_type');
        
        $this->addSql('ALTER TABLE keyword MODIFY keyword_type VARCHAR(50) NOT NULL DEFAULT \'author_keyword\'');
        $this->addSql('ALTER TABLE keyword ADD keyword_display VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE keyword ADD keyword_concept_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE keyword ADD CONSTRAINT FK_KW_CONCEPT FOREIGN KEY (keyword_concept_id) REFERENCES keyword (id) ON DELETE SET NULL');

        // Backfill keyword values
        $this->addSql('UPDATE keyword SET keyword_display = keyword_original');
        $this->addSql('UPDATE keyword SET keyword_type = \'author_keyword\' WHERE keyword_type = \'author\'');
        $this->addSql('UPDATE keyword SET keyword_type = \'indexed_keyword\' WHERE keyword_type = \'indexed\'');

        // Re-create indexes
        $this->addSql('ALTER TABLE keyword ADD UNIQUE KEY UNIQ_KW_NORM_TYPE (keyword_normalized, keyword_type)');
        $this->addSql('ALTER TABLE keyword ADD INDEX idx_kw_concept_id (keyword_concept_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE keyword DROP FOREIGN KEY FK_KW_CONCEPT');
        $this->addSql('ALTER TABLE keyword DROP INDEX UNIQ_KW_NORM_TYPE');
        $this->addSql('ALTER TABLE keyword DROP INDEX idx_kw_concept_id');
        $this->addSql('ALTER TABLE keyword DROP COLUMN keyword_display');
        $this->addSql('ALTER TABLE keyword DROP COLUMN keyword_concept_id');
        
        $this->addSql('ALTER TABLE keyword RENAME COLUMN keyword_original TO term');
        $this->addSql('ALTER TABLE keyword RENAME COLUMN keyword_normalized TO normalized_term');
        $this->addSql('ALTER TABLE keyword RENAME COLUMN keyword_type TO type');
        $this->addSql('ALTER TABLE keyword MODIFY type VARCHAR(20) NOT NULL DEFAULT \'author\'');
        $this->addSql('ALTER TABLE keyword ADD UNIQUE KEY UNIQ_5A93713B2DB098A38CDE5729 (normalized_term, type)');

        // Down steps for authors table recreation...
        $this->addSql('
            CREATE TABLE author (
                id INT AUTO_INCREMENT NOT NULL,
                name VARCHAR(255) NOT NULL,
                normalized_name VARCHAR(255) NOT NULL,
                surname VARCHAR(255) DEFAULT NULL,
                initials VARCHAR(255) DEFAULT NULL,
                orcid VARCHAR(255) DEFAULT NULL,
                status TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        $this->addSql('
            CREATE TABLE autor_variacoes_nome (
                id INT AUTO_INCREMENT NOT NULL,
                author_id INT NOT NULL,
                variation_name VARCHAR(255) NOT NULL,
                normalized_name VARCHAR(255) NOT NULL,
                variation_type VARCHAR(100) DEFAULT NULL,
                status TINYINT(1) NOT NULL DEFAULT 1,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        // Restore authors back
        $this->addSql('
            INSERT INTO author (id, name, normalized_name, created_at)
            SELECT id, preferred_name, normalized_name, created_at FROM author_identity
        ');

        $this->addSql('
            INSERT INTO autor_variacoes_nome (author_id, variation_name, normalized_name, variation_type)
            SELECT author_identity_id, display_name, normalized_name, \'alternative\' FROM author_name_variant
        ');

        $this->addSql('ALTER TABLE document_author DROP FOREIGN KEY FK_3CD69AE11BF2E8B');
        $this->addSql('ALTER TABLE document_author RENAME COLUMN author_identity_id TO author_id');
        $this->addSql('ALTER TABLE document_author ADD CONSTRAINT FK_3CD69AEF675F31B FOREIGN KEY (author_id) REFERENCES author (id) ON DELETE CASCADE');

        $this->addSql('DROP TABLE author_external_identifier');
        $this->addSql('DROP TABLE author_name_variant');
        $this->addSql('DROP TABLE author_identity');
    }
}
