<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260708034005 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE instituicao_unidades (id INT AUTO_INCREMENT NOT NULL, original_variation_name VARCHAR(255) NOT NULL, canonical_name VARCHAR(255) NOT NULL, type VARCHAR(100) DEFAULT NULL, confidence VARCHAR(50) DEFAULT NULL, observation LONGTEXT DEFAULT NULL, parent_institution_id INT DEFAULT NULL, INDEX IDX_BE5DD6A7E9D2D8D (parent_institution_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE organizacoes (id INT AUTO_INCREMENT NOT NULL, original_variation_name VARCHAR(255) NOT NULL, canonical_name VARCHAR(255) NOT NULL, type VARCHAR(100) DEFAULT NULL, confidence VARCHAR(50) DEFAULT NULL, observation LONGTEXT DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE instituicao_unidades ADD CONSTRAINT FK_BE5DD6A7E9D2D8D FOREIGN KEY (parent_institution_id) REFERENCES instituicoes_ensino (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE instituicao_unidades DROP FOREIGN KEY FK_BE5DD6A7E9D2D8D');
        $this->addSql('DROP TABLE instituicao_unidades');
        $this->addSql('DROP TABLE organizacoes');
    }
}
