<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260709033908 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // Keyword: add thesaurus_concept_id FK and indexes
        $this->addSql('ALTER TABLE keyword ADD thesaurus_concept_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE keyword ADD CONSTRAINT FK_5A93713B74F16A85 FOREIGN KEY (thesaurus_concept_id) REFERENCES thesaurus_concept (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_5A93713B74F16A85 ON keyword (thesaurus_concept_id)');
        $this->addSql('CREATE INDEX IDX_kw_type ON keyword (keyword_type)');

        // KeywordTreatmentJob: add new counters
        $this->addSql('ALTER TABLE keyword_treatment_job ADD total_document_keywords INT DEFAULT 0 NOT NULL, ADD suspicious_count INT DEFAULT 0 NOT NULL, ADD exact_matched_count INT DEFAULT 0 NOT NULL, ADD created_concept_count INT DEFAULT 0 NOT NULL, ADD affected_document_keyword_count INT DEFAULT 0 NOT NULL, ADD affected_document_count INT DEFAULT 0 NOT NULL, ADD updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');

        // KeywordTreatmentLog: add thesaurus concept FKs and match_method
        $this->addSql('ALTER TABLE keyword_treatment_log ADD match_method VARCHAR(50) DEFAULT NULL, ADD old_thesaurus_concept_id INT DEFAULT NULL, ADD new_thesaurus_concept_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE keyword_treatment_log ADD CONSTRAINT FK_EDF197029503104 FOREIGN KEY (old_thesaurus_concept_id) REFERENCES thesaurus_concept (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE keyword_treatment_log ADD CONSTRAINT FK_EDF1970283301AD0 FOREIGN KEY (new_thesaurus_concept_id) REFERENCES thesaurus_concept (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_EDF197029503104 ON keyword_treatment_log (old_thesaurus_concept_id)');
        $this->addSql('CREATE INDEX IDX_EDF1970283301AD0 ON keyword_treatment_log (new_thesaurus_concept_id)');

        // ThesaurusMatch: add keyword FK and match_method
        $this->addSql('ALTER TABLE thesaurus_match ADD match_method VARCHAR(50) DEFAULT NULL, ADD keyword_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE thesaurus_match ADD CONSTRAINT FK_83812671115D4552 FOREIGN KEY (keyword_id) REFERENCES keyword (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_83812671115D4552 ON thesaurus_match (keyword_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE keyword DROP FOREIGN KEY FK_5A93713B74F16A85');
        $this->addSql('DROP INDEX IDX_5A93713B74F16A85 ON keyword');
        $this->addSql('DROP INDEX IDX_kw_type ON keyword');
        $this->addSql('ALTER TABLE keyword DROP thesaurus_concept_id');

        $this->addSql('ALTER TABLE keyword_treatment_job DROP total_document_keywords, DROP suspicious_count, DROP exact_matched_count, DROP created_concept_count, DROP affected_document_keyword_count, DROP affected_document_count, DROP updated_at');

        $this->addSql('ALTER TABLE keyword_treatment_log DROP FOREIGN KEY FK_EDF197029503104');
        $this->addSql('ALTER TABLE keyword_treatment_log DROP FOREIGN KEY FK_EDF1970283301AD0');
        $this->addSql('DROP INDEX IDX_EDF197029503104 ON keyword_treatment_log');
        $this->addSql('DROP INDEX IDX_EDF1970283301AD0 ON keyword_treatment_log');
        $this->addSql('ALTER TABLE keyword_treatment_log DROP match_method, DROP old_thesaurus_concept_id, DROP new_thesaurus_concept_id');

        $this->addSql('ALTER TABLE thesaurus_match DROP FOREIGN KEY FK_83812671115D4552');
        $this->addSql('DROP INDEX IDX_83812671115D4552 ON thesaurus_match');
        $this->addSql('ALTER TABLE thesaurus_match DROP match_method, DROP keyword_id');
    }
}
