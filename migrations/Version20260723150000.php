<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds classification_min_year to bibliometric_project and changes
 * the document_classification unique constraint to allow multi-group
 * classification (one row per document+project+group instead of
 * one row per document+project).
 */
final class Version20260723150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add classification_min_year and allow multi-group classification';
    }

    public function up(Schema $schema): void
    {
        // 1. Add classificationMinYear column to bibliometric_project
        $this->addSql('ALTER TABLE bibliometric_project ADD classification_min_year INT DEFAULT NULL');

        // 2. Drop old unique constraint (document_id, project_id)
        $this->addSql('ALTER TABLE document_classification DROP INDEX uniq_doc_classification');

        // 3. Create new unique constraint (document_id, project_id, group_id)
        $this->addSql('CREATE UNIQUE INDEX uniq_doc_group_classification ON document_classification (document_id, project_id, group_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bibliometric_project DROP classification_min_year');

        $this->addSql('DROP INDEX uniq_doc_group_classification ON document_classification');
        $this->addSql('CREATE UNIQUE INDEX uniq_doc_classification ON document_classification (document_id, project_id)');
    }
}
