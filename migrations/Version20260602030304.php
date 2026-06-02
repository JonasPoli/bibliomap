<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260602030304 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE site_settings (id INT NOT NULL, google_analytics_id VARCHAR(50) DEFAULT NULL, seo_title VARCHAR(120) NOT NULL, seo_description LONGTEXT NOT NULL, seo_keywords LONGTEXT NOT NULL, og_image VARCHAR(255) DEFAULT NULL, base_url VARCHAR(255) NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE user CHANGE status status VARCHAR(30) DEFAULT \'pending\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE site_settings');
        $this->addSql('ALTER TABLE `user` CHANGE status status VARCHAR(30) DEFAULT \'active\' NOT NULL');
    }
}
