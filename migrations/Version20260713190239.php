<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260713190239 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE document ADD qualis_journal_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A7647DF50A9 FOREIGN KEY (qualis_journal_id) REFERENCES qualis_journal (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_D8698A7647DF50A9 ON document (qualis_journal_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A7647DF50A9');
        $this->addSql('DROP INDEX IDX_D8698A7647DF50A9 ON document');
        $this->addSql('ALTER TABLE document DROP qualis_journal_id');
    }
}
