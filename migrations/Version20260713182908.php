<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260713182908 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE qualis_journal (id INT AUTO_INCREMENT NOT NULL, issn VARCHAR(50) DEFAULT NULL, normalized_issn VARCHAR(50) NOT NULL, title VARCHAR(500) NOT NULL, qualis VARCHAR(10) DEFAULT NULL, UNIQUE INDEX UNIQ_DC28C908177AA8D8 (normalized_issn), INDEX IDX_DC28C908177AA8D8 (normalized_issn), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE document ADD qualis VARCHAR(10) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE qualis_journal');
        $this->addSql('ALTER TABLE document DROP qualis');
    }
}
