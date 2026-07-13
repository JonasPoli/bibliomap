<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Adds status and review_reasons to author_identity.
 */
final class Version20260713205100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds status and review_reasons to author_identity table for sync and review capability';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE author_identity ADD status TINYINT(1) DEFAULT 0 NOT NULL, ADD review_reasons VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE author_identity DROP status, DROP review_reasons');
    }
}
