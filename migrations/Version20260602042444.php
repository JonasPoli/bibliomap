<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260602042444 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE dataset_skip (id INT AUTO_INCREMENT NOT NULL, title LONGTEXT NOT NULL, doi VARCHAR(255) DEFAULT NULL, hash VARCHAR(64) DEFAULT NULL, reason VARCHAR(50) NOT NULL, created_at DATETIME NOT NULL, dataset_id INT NOT NULL, matched_document_id INT DEFAULT NULL, INDEX IDX_7DF2007824D7B95A (matched_document_id), INDEX idx_dataset_skip_dataset (dataset_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE dataset_skip ADD CONSTRAINT FK_7DF20078D47C2D1B FOREIGN KEY (dataset_id) REFERENCES dataset (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE dataset_skip ADD CONSTRAINT FK_7DF2007824D7B95A FOREIGN KEY (matched_document_id) REFERENCES document (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE dataset_skip DROP FOREIGN KEY FK_7DF20078D47C2D1B');
        $this->addSql('ALTER TABLE dataset_skip DROP FOREIGN KEY FK_7DF2007824D7B95A');
        $this->addSql('DROP TABLE dataset_skip');
    }
}
