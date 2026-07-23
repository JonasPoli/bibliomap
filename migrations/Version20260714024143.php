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
        $schemaManager = $this->connection->createSchemaManager();

        $renameIndexSafe = function(string $table, string $oldIndex, string $newIndex) use ($schemaManager) {
            $indexes = $schemaManager->listTableIndexes($table);
            $oldLower = strtolower($oldIndex);
            $newLower = strtolower($newIndex);
            
            // If the target index name already exists, we don't need to rename.
            if (isset($indexes[$newLower])) {
                return;
            }
            
            // If the source index name exists, we run the rename query.
            if (isset($indexes[$oldLower])) {
                $this->addSql(sprintf('ALTER TABLE %s RENAME INDEX %s TO %s', $table, $oldIndex, $newIndex));
            }
        };

        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE classification_group CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE classification_rule CHANGE created_at created_at DATETIME NOT NULL');
        
        $renameIndexSafe('classification_rule', 'idx_clf_rule_group', 'IDX_C26DA372FE54D947');
        
        $this->addSql('ALTER TABLE document_classification CHANGE run_at run_at DATETIME NOT NULL');
        
        $renameIndexSafe('document_classification', 'fk_doc_clf_group', 'IDX_3F26273DFE54D947');
        $renameIndexSafe('document_classification', 'idx_doc_classification_project', 'IDX_3F26273D166D1F9C');
        $renameIndexSafe('document_classification', 'idx_doc_classification_doc', 'IDX_3F26273DC33F7837');
        
        $this->addSql('ALTER TABLE keyword_treatment_job CHANGE updated_at updated_at DATETIME NOT NULL');
        
        $renameIndexSafe('palavra_chave_variacoes_nome', 'idx_b8c80521115f4956', 'IDX_2B5F4FDF115D4552');
        $renameIndexSafe('palavra_chave_variacoes_nome', 'idx_keyword_var_normalized', 'IDX_2B5F4FDFD69C0128');
        
        $this->addSql('ALTER TABLE thesaurus_match CHANGE confidence confidence DOUBLE PRECISION DEFAULT 1 NOT NULL');

        // Robust check for review_reasons in keyword table
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
        $schemaManager = $this->connection->createSchemaManager();

        $renameIndexSafe = function(string $table, string $oldIndex, string $newIndex) use ($schemaManager) {
            $indexes = $schemaManager->listTableIndexes($table);
            $oldLower = strtolower($oldIndex);
            $newLower = strtolower($newIndex);
            
            // If the target index name already exists, we don't need to rename.
            if (isset($indexes[$newLower])) {
                return;
            }
            
            // If the source index name exists, we run the rename query.
            if (isset($indexes[$oldLower])) {
                $this->addSql(sprintf('ALTER TABLE %s RENAME INDEX %s TO %s', $table, $oldIndex, $newIndex));
            }
        };

        $createIndexSafe = function(string $table, string $indexName, string $columnsSql) use ($schemaManager) {
            $indexes = $schemaManager->listTableIndexes($table);
            if (!isset($indexes[strtolower($indexName)])) {
                $this->addSql(sprintf('CREATE INDEX %s ON %s (%s)', $indexName, $table, $columnsSql));
            }
        };

        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE classification_group CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        
        $createIndexSafe('classification_group', 'IDX_CLF_GROUP_PROJECT', 'project_id, position');
        
        $this->addSql('ALTER TABLE classification_rule CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        
        $renameIndexSafe('classification_rule', 'idx_c26da372fe54d947', 'IDX_CLF_RULE_GROUP');
        
        $this->addSql('ALTER TABLE document_classification CHANGE run_at run_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        
        $renameIndexSafe('document_classification', 'idx_3f26273dfe54d947', 'FK_DOC_CLF_GROUP');
        $renameIndexSafe('document_classification', 'idx_3f26273dc33f7837', 'IDX_DOC_CLASSIFICATION_DOC');
        $renameIndexSafe('document_classification', 'idx_3f26273d166d1f9c', 'IDX_DOC_CLASSIFICATION_PROJECT');
        
        $this->addSql('ALTER TABLE keyword_treatment_job CHANGE updated_at updated_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL');
        
        $renameIndexSafe('palavra_chave_variacoes_nome', 'idx_2b5f4fdf115d4552', 'IDX_B8C80521115F4956');
        $renameIndexSafe('palavra_chave_variacoes_nome', 'idx_2b5f4fdfd69c0128', 'idx_keyword_var_normalized');
        
        $this->addSql('ALTER TABLE thesaurus_match CHANGE confidence confidence DOUBLE PRECISION DEFAULT \'1\' NOT NULL');
    }
}
