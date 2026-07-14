<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260714024143 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE classification_group CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE classification_rule CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE classification_rule RENAME INDEX idx_clf_rule_group TO IDX_C26DA372FE54D947');
        $this->addSql('ALTER TABLE document_classification CHANGE run_at run_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE document_classification RENAME INDEX fk_doc_clf_group TO IDX_3F26273DFE54D947');
        $this->addSql('ALTER TABLE document_classification RENAME INDEX idx_doc_classification_project TO IDX_3F26273D166D1F9C');
        $this->addSql('ALTER TABLE document_classification RENAME INDEX idx_doc_classification_doc TO IDX_3F26273DC33F7837');
        $this->addSql('ALTER TABLE keyword_treatment_job CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE palavra_chave_variacoes_nome RENAME INDEX idx_b8c80521115f4956 TO IDX_2B5F4FDF115D4552');
        $this->addSql('ALTER TABLE palavra_chave_variacoes_nome RENAME INDEX idx_keyword_var_normalized TO IDX_2B5F4FDFD69C0128');
        $this->addSql('ALTER TABLE thesaurus_match CHANGE confidence confidence DOUBLE PRECISION DEFAULT 1 NOT NULL');

        // Robust check for review_reasons in keyword table
        $schemaManager = $this->connection->createSchemaManager();
        $columnsKeyword = $schemaManager->listTableColumns('keyword');
        if (!isset($columnsKeyword['review_reasons'])) {
            $this->addSql('ALTER TABLE keyword ADD review_reasons VARCHAR(255) DEFAULT NULL');
        }

        // Robust check for status and review_reasons in author_identity table
        $columnsAuthor = $schemaManager->listTableColumns('author_identity');
        if (!isset($columnsAuthor['status'])) {
            $this->addSql('ALTER TABLE author_identity ADD status TINYINT(1) DEFAULT 0 NOT NULL');
        }
        if (!isset($columnsAuthor['review_reasons'])) {
            $this->addSql('ALTER TABLE author_identity ADD review_reasons VARCHAR(255) DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE classification_group CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE INDEX IDX_CLF_GROUP_PROJECT ON classification_group (project_id, position)');
        $this->addSql('ALTER TABLE classification_rule CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE classification_rule RENAME INDEX idx_c26da372fe54d947 TO IDX_CLF_RULE_GROUP');
        $this->addSql('ALTER TABLE document_classification CHANGE run_at run_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE document_classification RENAME INDEX idx_3f26273dfe54d947 TO FK_DOC_CLF_GROUP');
        $this->addSql('ALTER TABLE document_classification RENAME INDEX idx_3f26273dc33f7837 TO IDX_DOC_CLASSIFICATION_DOC');
        $this->addSql('ALTER TABLE document_classification RENAME INDEX idx_3f26273d166d1f9c TO IDX_DOC_CLASSIFICATION_PROJECT');
        $this->addSql('ALTER TABLE keyword_treatment_job CHANGE updated_at updated_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL');
        $this->addSql('ALTER TABLE palavra_chave_variacoes_nome RENAME INDEX idx_2b5f4fdf115d4552 TO IDX_B8C80521115F4956');
        $this->addSql('ALTER TABLE palavra_chave_variacoes_nome RENAME INDEX idx_2b5f4fdfd69c0128 TO idx_keyword_var_normalized');
        $this->addSql('ALTER TABLE thesaurus_match CHANGE confidence confidence DOUBLE PRECISION DEFAULT \'1\' NOT NULL');
    }
}
