<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260529174054 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE bibliometric_project (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, research_question LONGTEXT DEFAULT NULL, objective LONGTEXT DEFAULT NULL, search_string LONGTEXT DEFAULT NULL, database_sources JSON DEFAULT NULL, start_year INT DEFAULT NULL, end_year INT DEFAULT NULL, status VARCHAR(50) DEFAULT \'draft\' NOT NULL, visibility VARCHAR(20) DEFAULT \'private\' NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id INT NOT NULL, UNIQUE INDEX UNIQ_768238F1989D9B62 (slug), INDEX IDX_768238F1A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE dataset (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, source VARCHAR(50) DEFAULT NULL, original_filename VARCHAR(255) NOT NULL, file_path VARCHAR(500) NOT NULL, file_format VARCHAR(20) DEFAULT NULL, records_count INT DEFAULT 0 NOT NULL, imported_count INT DEFAULT 0 NOT NULL, duplicated_count INT DEFAULT 0 NOT NULL, error_count INT DEFAULT 0 NOT NULL, status VARCHAR(30) DEFAULT \'pending\' NOT NULL, error_message LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, imported_at DATETIME DEFAULT NULL, project_id INT NOT NULL, INDEX IDX_B7A041D0166D1F9C (project_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `user` (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, name VARCHAR(255) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, institution VARCHAR(255) DEFAULT NULL, country VARCHAR(100) DEFAULT NULL, status VARCHAR(30) DEFAULT \'active\' NOT NULL, last_login_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_IDENTIFIER_EMAIL (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE bibliometric_project ADD CONSTRAINT FK_768238F1A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE dataset ADD CONSTRAINT FK_B7A041D0166D1F9C FOREIGN KEY (project_id) REFERENCES bibliometric_project (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE bibliometric_project DROP FOREIGN KEY FK_768238F1A76ED395');
        $this->addSql('ALTER TABLE dataset DROP FOREIGN KEY FK_B7A041D0166D1F9C');
        $this->addSql('DROP TABLE bibliometric_project');
        $this->addSql('DROP TABLE dataset');
        $this->addSql('DROP TABLE `user`');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
