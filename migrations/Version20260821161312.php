<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260821161312 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE author_name_variant CHANGE original_name original_name VARCHAR(500) NOT NULL, CHANGE display_name display_name VARCHAR(500) NOT NULL, CHANGE normalized_name normalized_name VARCHAR(500) NOT NULL');
        $this->addSql('DROP INDEX IDX_CLF_GROUP_PROJECT ON classification_group');
        $this->addSql('ALTER TABLE classification_group CHANGE qualis_filter qualis_filter JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE instituicao_variacoes_nome CHANGE variation_name variation_name VARCHAR(500) NOT NULL, CHANGE normalized_name normalized_name VARCHAR(500) NOT NULL');
        $this->addSql('ALTER TABLE pais_variacoes_nome CHANGE variation_name variation_name VARCHAR(500) NOT NULL, CHANGE normalized_name normalized_name VARCHAR(500) NOT NULL');
        $this->addSql('ALTER TABLE palavra_chave_variacoes_nome CHANGE variation_name variation_name VARCHAR(500) NOT NULL, CHANGE normalized_name normalized_name VARCHAR(500) NOT NULL');
        $this->addSql('ALTER TABLE qualis_journal CHANGE title title VARCHAR(500) NOT NULL');
        $this->addSql('ALTER TABLE qualis_journal_variacoes_nome CHANGE variation_name variation_name VARCHAR(500) NOT NULL, CHANGE normalized_name normalized_name VARCHAR(500) NOT NULL');
        $this->addSql('ALTER TABLE saved_matrix CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE saved_matrix RENAME INDEX idx_saved_matrix_project TO IDX_9C353AD4166D1F9C');
        $this->addSql('ALTER TABLE thesaurus_match CHANGE confidence confidence DOUBLE PRECISION DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE import_error (id INT AUTO_INCREMENT NOT NULL, project_id INT DEFAULT NULL, dataset_id INT DEFAULT NULL, entity_type VARCHAR(100) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, original_value TEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, reason VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, created_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE author_name_variant CHANGE original_name original_name VARCHAR(500) DEFAULT NULL, CHANGE display_name display_name VARCHAR(500) DEFAULT NULL, CHANGE normalized_name normalized_name VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE classification_group CHANGE qualis_filter qualis_filter LONGTEXT DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_CLF_GROUP_PROJECT ON classification_group (project_id, position)');
        $this->addSql('ALTER TABLE instituicao_variacoes_nome CHANGE variation_name variation_name VARCHAR(500) DEFAULT NULL, CHANGE normalized_name normalized_name VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE pais_variacoes_nome CHANGE variation_name variation_name VARCHAR(500) DEFAULT NULL, CHANGE normalized_name normalized_name VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE palavra_chave_variacoes_nome CHANGE variation_name variation_name VARCHAR(500) DEFAULT NULL, CHANGE normalized_name normalized_name VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE qualis_journal CHANGE title title VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE qualis_journal_variacoes_nome CHANGE variation_name variation_name VARCHAR(500) DEFAULT NULL, CHANGE normalized_name normalized_name VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE saved_matrix CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE saved_matrix RENAME INDEX idx_9c353ad4166d1f9c TO IDX_SAVED_MATRIX_PROJECT');
        $this->addSql('ALTER TABLE thesaurus_match CHANGE confidence confidence DOUBLE PRECISION DEFAULT \'1\' NOT NULL');
    }
}
