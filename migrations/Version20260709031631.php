<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260709031631 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE keyword_treatment_job (id INT AUTO_INCREMENT NOT NULL, started_at DATETIME NOT NULL, finished_at DATETIME DEFAULT NULL, status VARCHAR(30) NOT NULL, mode VARCHAR(30) NOT NULL, started_by VARCHAR(100) NOT NULL, total_keywords INT DEFAULT 0 NOT NULL, cleaned_count INT DEFAULT 0 NOT NULL, invalid_count INT DEFAULT 0 NOT NULL, exact_grouped_count INT DEFAULT 0 NOT NULL, thesaurus_matched_count INT DEFAULT 0 NOT NULL, fuzzy_auto_matched_count INT DEFAULT 0 NOT NULL, fuzzy_review_count INT DEFAULT 0 NOT NULL, skipped_count INT DEFAULT 0 NOT NULL, error_count INT DEFAULT 0 NOT NULL, report_path VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE keyword_treatment_log (id INT AUTO_INCREMENT NOT NULL, action VARCHAR(50) NOT NULL, old_display VARCHAR(255) DEFAULT NULL, new_display VARCHAR(255) DEFAULT NULL, old_normalized VARCHAR(255) DEFAULT NULL, new_normalized VARCHAR(255) DEFAULT NULL, score DOUBLE PRECISION DEFAULT NULL, reason LONGTEXT DEFAULT NULL, status VARCHAR(30) DEFAULT \'pending\' NOT NULL, created_at DATETIME NOT NULL, job_id INT NOT NULL, keyword_id INT NOT NULL, old_concept_id INT DEFAULT NULL, new_concept_id INT DEFAULT NULL, INDEX IDX_EDF19702BE04EA9 (job_id), INDEX IDX_EDF19702115D4552 (keyword_id), INDEX IDX_EDF19702452A1F10 (old_concept_id), INDEX IDX_EDF197022436CB82 (new_concept_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE keyword_treatment_log ADD CONSTRAINT FK_EDF19702BE04EA9 FOREIGN KEY (job_id) REFERENCES keyword_treatment_job (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE keyword_treatment_log ADD CONSTRAINT FK_EDF19702115D4552 FOREIGN KEY (keyword_id) REFERENCES keyword (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE keyword_treatment_log ADD CONSTRAINT FK_EDF19702452A1F10 FOREIGN KEY (old_concept_id) REFERENCES keyword (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE keyword_treatment_log ADD CONSTRAINT FK_EDF197022436CB82 FOREIGN KEY (new_concept_id) REFERENCES keyword (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE keyword_treatment_log DROP FOREIGN KEY FK_EDF19702BE04EA9');
        $this->addSql('ALTER TABLE keyword_treatment_log DROP FOREIGN KEY FK_EDF19702115D4552');
        $this->addSql('ALTER TABLE keyword_treatment_log DROP FOREIGN KEY FK_EDF19702452A1F10');
        $this->addSql('ALTER TABLE keyword_treatment_log DROP FOREIGN KEY FK_EDF197022436CB82');
        $this->addSql('DROP TABLE keyword_treatment_job');
        $this->addSql('DROP TABLE keyword_treatment_log');
    }
}
