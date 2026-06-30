<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create tables for the Thematic Classification panel:
 *   - classification_group
 *   - classification_rule
 *   - document_classification
 */
final class Version20260629163800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create classification_group, classification_rule and document_classification tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE classification_group (
            id           INT AUTO_INCREMENT NOT NULL,
            project_id   INT NOT NULL,
            name         VARCHAR(255) NOT NULL,
            description  LONGTEXT DEFAULT NULL,
            type         VARCHAR(30) NOT NULL DEFAULT \'normal\',
            color        VARCHAR(50) DEFAULT NULL,
            icon         VARCHAR(100) DEFAULT NULL,
            position     INT NOT NULL DEFAULT 0,
            created_at   DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX IDX_CLF_GROUP_PROJECT (project_id, position),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE classification_group
            ADD CONSTRAINT FK_CLF_GROUP_PROJECT FOREIGN KEY (project_id)
            REFERENCES bibliometric_project (id) ON DELETE CASCADE');

        $this->addSql('CREATE TABLE classification_rule (
            id         INT AUTO_INCREMENT NOT NULL,
            group_id   INT NOT NULL,
            term       VARCHAR(500) NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX IDX_CLF_RULE_GROUP (group_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE classification_rule
            ADD CONSTRAINT FK_CLF_RULE_GROUP FOREIGN KEY (group_id)
            REFERENCES classification_group (id) ON DELETE CASCADE');

        $this->addSql('CREATE TABLE document_classification (
            id              INT AUTO_INCREMENT NOT NULL,
            document_id     INT NOT NULL,
            group_id        INT DEFAULT NULL,
            project_id      INT NOT NULL,
            matched_term    VARCHAR(500) DEFAULT NULL,
            run_at          DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            manual_override TINYINT(1) NOT NULL DEFAULT 0,
            INDEX IDX_DOC_CLASSIFICATION_PROJECT (project_id),
            INDEX IDX_DOC_CLASSIFICATION_DOC (document_id),
            UNIQUE INDEX uniq_doc_classification (document_id, project_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE document_classification
            ADD CONSTRAINT FK_DOC_CLF_DOCUMENT FOREIGN KEY (document_id)
            REFERENCES document (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE document_classification
            ADD CONSTRAINT FK_DOC_CLF_GROUP FOREIGN KEY (group_id)
            REFERENCES classification_group (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE document_classification
            ADD CONSTRAINT FK_DOC_CLF_PROJECT FOREIGN KEY (project_id)
            REFERENCES bibliometric_project (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE document_classification');
        $this->addSql('DROP TABLE classification_rule');
        $this->addSql('DROP TABLE classification_group');
    }
}
