<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260529185044 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE INDEX IDX_D8698A76166D1F9CBB827337 ON document (project_id, year)');
        $this->addSql('CREATE UNIQUE INDEX uniq_project_hash ON document (project_id, hash)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX IDX_D8698A76166D1F9CBB827337 ON document');
        $this->addSql('DROP INDEX uniq_project_hash ON document');
    }
}
