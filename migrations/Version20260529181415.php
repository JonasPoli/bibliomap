<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260529181415 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE author (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, normalized_name VARCHAR(255) DEFAULT NULL, surname VARCHAR(150) DEFAULT NULL, initials VARCHAR(20) DEFAULT NULL, orcid VARCHAR(30) DEFAULT NULL, created_at DATETIME NOT NULL, INDEX IDX_BDAFD8C8D69C0128 (normalized_name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE document (id INT AUTO_INCREMENT NOT NULL, title LONGTEXT DEFAULT NULL, normalized_title LONGTEXT DEFAULT NULL, abstract_text LONGTEXT DEFAULT NULL, year INT DEFAULT NULL, document_type VARCHAR(50) DEFAULT NULL, doi VARCHAR(255) DEFAULT NULL, pmid VARCHAR(50) DEFAULT NULL, isbn VARCHAR(50) DEFAULT NULL, issn VARCHAR(50) DEFAULT NULL, url VARCHAR(500) DEFAULT NULL, language VARCHAR(10) DEFAULT NULL, source_title VARCHAR(500) DEFAULT NULL, volume VARCHAR(20) DEFAULT NULL, issue VARCHAR(20) DEFAULT NULL, page_start VARCHAR(30) DEFAULT NULL, page_end VARCHAR(30) DEFAULT NULL, publisher VARCHAR(255) DEFAULT NULL, cited_by INT DEFAULT NULL, local_citations INT DEFAULT NULL, open_access_status VARCHAR(100) DEFAULT NULL, publication_stage VARCHAR(50) DEFAULT NULL, external_id VARCHAR(100) DEFAULT NULL, source VARCHAR(50) NOT NULL, hash VARCHAR(64) DEFAULT NULL, created_at DATETIME NOT NULL, project_id INT NOT NULL, dataset_id INT DEFAULT NULL, INDEX IDX_D8698A76166D1F9C (project_id), INDEX IDX_D8698A76D47C2D1B (dataset_id), INDEX IDX_D8698A766694147A (doi), INDEX IDX_D8698A76BB827337 (year), INDEX IDX_D8698A76D1B862B8 (hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE document_author (id INT AUTO_INCREMENT NOT NULL, position INT DEFAULT 0 NOT NULL, original_name VARCHAR(255) DEFAULT NULL, document_id INT NOT NULL, author_id INT NOT NULL, INDEX IDX_3CD69AEC33F7837 (document_id), INDEX IDX_3CD69AEF675F31B (author_id), INDEX IDX_3CD69AEC33F7837F675F31B (document_id, author_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE document_keyword (id INT AUTO_INCREMENT NOT NULL, document_id INT NOT NULL, keyword_id INT NOT NULL, INDEX IDX_FEFCD7E7C33F7837 (document_id), INDEX IDX_FEFCD7E7115D4552 (keyword_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE keyword (id INT AUTO_INCREMENT NOT NULL, term VARCHAR(255) NOT NULL, normalized_term VARCHAR(255) NOT NULL, type VARCHAR(20) DEFAULT \'author\' NOT NULL, INDEX IDX_5A93713B2DB098A3 (normalized_term), UNIQUE INDEX UNIQ_5A93713B2DB098A38CDE5729 (normalized_term, type), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A76166D1F9C FOREIGN KEY (project_id) REFERENCES bibliometric_project (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A76D47C2D1B FOREIGN KEY (dataset_id) REFERENCES dataset (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE document_author ADD CONSTRAINT FK_3CD69AEC33F7837 FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE document_author ADD CONSTRAINT FK_3CD69AEF675F31B FOREIGN KEY (author_id) REFERENCES author (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE document_keyword ADD CONSTRAINT FK_FEFCD7E7C33F7837 FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE document_keyword ADD CONSTRAINT FK_FEFCD7E7115D4552 FOREIGN KEY (keyword_id) REFERENCES keyword (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A76166D1F9C');
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A76D47C2D1B');
        $this->addSql('ALTER TABLE document_author DROP FOREIGN KEY FK_3CD69AEC33F7837');
        $this->addSql('ALTER TABLE document_author DROP FOREIGN KEY FK_3CD69AEF675F31B');
        $this->addSql('ALTER TABLE document_keyword DROP FOREIGN KEY FK_FEFCD7E7C33F7837');
        $this->addSql('ALTER TABLE document_keyword DROP FOREIGN KEY FK_FEFCD7E7115D4552');
        $this->addSql('DROP TABLE author');
        $this->addSql('DROP TABLE document');
        $this->addSql('DROP TABLE document_author');
        $this->addSql('DROP TABLE document_keyword');
        $this->addSql('DROP TABLE keyword');
    }
}
