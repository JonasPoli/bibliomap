<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260709024817 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE thesaurus_scheme (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, type VARCHAR(50) NOT NULL, description LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_scheme_slug (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE thesaurus_concept (id INT AUTO_INCREMENT NOT NULL, preferred_label VARCHAR(255) NOT NULL, normalized_label VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, external_code VARCHAR(100) DEFAULT NULL, status VARCHAR(20) DEFAULT \'active\' NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, scheme_id INT NOT NULL, INDEX IDX_951A96BA65797862 (scheme_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE thesaurus_label (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(255) NOT NULL, normalized_label VARCHAR(255) NOT NULL, language VARCHAR(10) DEFAULT \'en\' NOT NULL, type VARCHAR(30) DEFAULT \'alternative\' NOT NULL, source VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, concept_id INT NOT NULL, INDEX IDX_F77DB39CF909284E (concept_id), INDEX IDX_F77DB39CD2F9E258 (normalized_label), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE thesaurus_match (id INT AUTO_INCREMENT NOT NULL, entity_type VARCHAR(50) NOT NULL, entity_id INT DEFAULT NULL, original_value VARCHAR(255) NOT NULL, normalized_value VARCHAR(255) NOT NULL, confidence DOUBLE PRECISION DEFAULT 1 NOT NULL, status VARCHAR(30) DEFAULT \'pending\' NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, concept_id INT DEFAULT NULL, INDEX IDX_83812671F909284E (concept_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE thesaurus_relation (id INT AUTO_INCREMENT NOT NULL, relation_type VARCHAR(50) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, source_concept_id INT NOT NULL, target_concept_id INT NOT NULL, INDEX IDX_22241CD985BD596A (source_concept_id), INDEX IDX_22241CD9CBE5F0DF (target_concept_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE thesaurus_concept ADD CONSTRAINT FK_951A96BA65797862 FOREIGN KEY (scheme_id) REFERENCES thesaurus_scheme (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE thesaurus_label ADD CONSTRAINT FK_F77DB39CF909284E FOREIGN KEY (concept_id) REFERENCES thesaurus_concept (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE thesaurus_match ADD CONSTRAINT FK_83812671F909284E FOREIGN KEY (concept_id) REFERENCES thesaurus_concept (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE thesaurus_relation ADD CONSTRAINT FK_22241CD985BD596A FOREIGN KEY (source_concept_id) REFERENCES thesaurus_concept (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE thesaurus_relation ADD CONSTRAINT FK_22241CD9CBE5F0DF FOREIGN KEY (target_concept_id) REFERENCES thesaurus_concept (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE thesaurus_concept DROP FOREIGN KEY FK_951A96BA65797862');
        $this->addSql('ALTER TABLE thesaurus_label DROP FOREIGN KEY FK_F77DB39CF909284E');
        $this->addSql('ALTER TABLE thesaurus_match DROP FOREIGN KEY FK_83812671F909284E');
        $this->addSql('ALTER TABLE thesaurus_relation DROP FOREIGN KEY FK_22241CD985BD596A');
        $this->addSql('ALTER TABLE thesaurus_relation DROP FOREIGN KEY FK_22241CD9CBE5F0DF');
        $this->addSql('DROP TABLE thesaurus_concept');
        $this->addSql('DROP TABLE thesaurus_label');
        $this->addSql('DROP TABLE thesaurus_match');
        $this->addSql('DROP TABLE thesaurus_relation');
        $this->addSql('DROP TABLE thesaurus_scheme');
    }
}
