<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260708012743 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE cidade_variacoes_nome (id INT AUTO_INCREMENT NOT NULL, variation_name VARCHAR(255) NOT NULL, variation_type VARCHAR(100) DEFAULT NULL, normalized_name VARCHAR(255) NOT NULL, status TINYINT DEFAULT 1 NOT NULL, city_id INT NOT NULL, INDEX IDX_76B038D98BAC62AF (city_id), INDEX IDX_76B038D9D69C0128 (normalized_name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE cidades (id INT AUTO_INCREMENT NOT NULL, official_name VARCHAR(255) NOT NULL, normalized_name VARCHAR(255) NOT NULL, official_code VARCHAR(50) DEFAULT NULL, status TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, country_id INT NOT NULL, state_id INT DEFAULT NULL, INDEX IDX_79B94AE7F92F3E70 (country_id), INDEX IDX_79B94AE75D83CC1 (state_id), INDEX IDX_79B94AE7D69C0128 (normalized_name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE documento_paises (document_id INT NOT NULL, country_id INT NOT NULL, INDEX IDX_73C3159C33F7837 (document_id), INDEX IDX_73C3159F92F3E70 (country_id), PRIMARY KEY (document_id, country_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE documento_estados (document_id INT NOT NULL, state_id INT NOT NULL, INDEX IDX_F793F1DEC33F7837 (document_id), INDEX IDX_F793F1DE5D83CC1 (state_id), PRIMARY KEY (document_id, state_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE documento_cidades (document_id INT NOT NULL, city_id INT NOT NULL, INDEX IDX_AC019A11C33F7837 (document_id), INDEX IDX_AC019A118BAC62AF (city_id), PRIMARY KEY (document_id, city_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE documento_instituicoes (id INT AUTO_INCREMENT NOT NULL, link_type VARCHAR(100) DEFAULT NULL, document_id INT NOT NULL, institution_id INT NOT NULL, INDEX IDX_94FAE6EC33F7837 (document_id), INDEX IDX_94FAE6E10405986 (institution_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE estado_variacoes_nome (id INT AUTO_INCREMENT NOT NULL, variation_name VARCHAR(255) NOT NULL, variation_type VARCHAR(100) DEFAULT NULL, normalized_name VARCHAR(255) NOT NULL, status TINYINT DEFAULT 1 NOT NULL, state_id INT NOT NULL, INDEX IDX_D0AFB7225D83CC1 (state_id), INDEX IDX_D0AFB722D69C0128 (normalized_name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE estados (id INT AUTO_INCREMENT NOT NULL, official_name VARCHAR(255) NOT NULL, sigla VARCHAR(10) DEFAULT NULL, official_code VARCHAR(50) DEFAULT NULL, status TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, country_id INT NOT NULL, region_id INT DEFAULT NULL, INDEX IDX_222B2128F92F3E70 (country_id), INDEX IDX_222B212898260155 (region_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE instituicao_variacoes_nome (id INT AUTO_INCREMENT NOT NULL, variation_name VARCHAR(255) NOT NULL, variation_type VARCHAR(100) DEFAULT NULL, normalized_name VARCHAR(255) NOT NULL, status TINYINT DEFAULT 1 NOT NULL, institution_id INT NOT NULL, INDEX IDX_1B6589E210405986 (institution_id), INDEX IDX_1B6589E2D69C0128 (normalized_name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE instituicoes_ensino (id INT AUTO_INCREMENT NOT NULL, official_name VARCHAR(255) NOT NULL, short_name VARCHAR(150) DEFAULT NULL, sigla VARCHAR(50) DEFAULT NULL, institution_type VARCHAR(100) DEFAULT NULL, natureza VARCHAR(100) DEFAULT NULL, official_website VARCHAR(255) DEFAULT NULL, institutional_email VARCHAR(150) DEFAULT NULL, status TINYINT DEFAULT 1 NOT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, country_id INT DEFAULT NULL, state_id INT DEFAULT NULL, city_id INT DEFAULT NULL, created_by_id INT DEFAULT NULL, updated_by_id INT DEFAULT NULL, INDEX IDX_165F7F18F92F3E70 (country_id), INDEX IDX_165F7F185D83CC1 (state_id), INDEX IDX_165F7F188BAC62AF (city_id), INDEX IDX_165F7F18B03A8386 (created_by_id), INDEX IDX_165F7F18896DBBDE (updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE pais_variacoes_nome (id INT AUTO_INCREMENT NOT NULL, variation_name VARCHAR(255) NOT NULL, variation_type VARCHAR(100) DEFAULT NULL, normalized_name VARCHAR(255) NOT NULL, status TINYINT DEFAULT 1 NOT NULL, country_id INT NOT NULL, INDEX IDX_7F11019EF92F3E70 (country_id), INDEX IDX_7F11019ED69C0128 (normalized_name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE paises (id INT AUTO_INCREMENT NOT NULL, official_name VARCHAR(255) NOT NULL, common_name VARCHAR(100) NOT NULL, sigla VARCHAR(10) DEFAULT NULL, iso_code VARCHAR(10) DEFAULT NULL, continente VARCHAR(100) DEFAULT NULL, nationality VARCHAR(100) DEFAULT NULL, status TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE regioes (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, sigla VARCHAR(10) DEFAULT NULL, display_order INT DEFAULT 0 NOT NULL, status TINYINT DEFAULT 1 NOT NULL, country_id INT NOT NULL, INDEX IDX_4193A038F92F3E70 (country_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE cidade_variacoes_nome ADD CONSTRAINT FK_76B038D98BAC62AF FOREIGN KEY (city_id) REFERENCES cidades (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE cidades ADD CONSTRAINT FK_79B94AE7F92F3E70 FOREIGN KEY (country_id) REFERENCES paises (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE cidades ADD CONSTRAINT FK_79B94AE75D83CC1 FOREIGN KEY (state_id) REFERENCES estados (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE documento_paises ADD CONSTRAINT FK_73C3159C33F7837 FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE documento_paises ADD CONSTRAINT FK_73C3159F92F3E70 FOREIGN KEY (country_id) REFERENCES paises (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE documento_estados ADD CONSTRAINT FK_F793F1DEC33F7837 FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE documento_estados ADD CONSTRAINT FK_F793F1DE5D83CC1 FOREIGN KEY (state_id) REFERENCES estados (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE documento_cidades ADD CONSTRAINT FK_AC019A11C33F7837 FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE documento_cidades ADD CONSTRAINT FK_AC019A118BAC62AF FOREIGN KEY (city_id) REFERENCES cidades (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE documento_instituicoes ADD CONSTRAINT FK_94FAE6EC33F7837 FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE documento_instituicoes ADD CONSTRAINT FK_94FAE6E10405986 FOREIGN KEY (institution_id) REFERENCES instituicoes_ensino (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE estado_variacoes_nome ADD CONSTRAINT FK_D0AFB7225D83CC1 FOREIGN KEY (state_id) REFERENCES estados (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE estados ADD CONSTRAINT FK_222B2128F92F3E70 FOREIGN KEY (country_id) REFERENCES paises (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE estados ADD CONSTRAINT FK_222B212898260155 FOREIGN KEY (region_id) REFERENCES regioes (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE instituicao_variacoes_nome ADD CONSTRAINT FK_1B6589E210405986 FOREIGN KEY (institution_id) REFERENCES instituicoes_ensino (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE instituicoes_ensino ADD CONSTRAINT FK_165F7F18F92F3E70 FOREIGN KEY (country_id) REFERENCES paises (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE instituicoes_ensino ADD CONSTRAINT FK_165F7F185D83CC1 FOREIGN KEY (state_id) REFERENCES estados (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE instituicoes_ensino ADD CONSTRAINT FK_165F7F188BAC62AF FOREIGN KEY (city_id) REFERENCES cidades (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE instituicoes_ensino ADD CONSTRAINT FK_165F7F18B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE instituicoes_ensino ADD CONSTRAINT FK_165F7F18896DBBDE FOREIGN KEY (updated_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE pais_variacoes_nome ADD CONSTRAINT FK_7F11019EF92F3E70 FOREIGN KEY (country_id) REFERENCES paises (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE regioes ADD CONSTRAINT FK_4193A038F92F3E70 FOREIGN KEY (country_id) REFERENCES paises (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE cidade_variacoes_nome DROP FOREIGN KEY FK_76B038D98BAC62AF');
        $this->addSql('ALTER TABLE cidades DROP FOREIGN KEY FK_79B94AE7F92F3E70');
        $this->addSql('ALTER TABLE cidades DROP FOREIGN KEY FK_79B94AE75D83CC1');
        $this->addSql('ALTER TABLE documento_paises DROP FOREIGN KEY FK_73C3159C33F7837');
        $this->addSql('ALTER TABLE documento_paises DROP FOREIGN KEY FK_73C3159F92F3E70');
        $this->addSql('ALTER TABLE documento_estados DROP FOREIGN KEY FK_F793F1DEC33F7837');
        $this->addSql('ALTER TABLE documento_estados DROP FOREIGN KEY FK_F793F1DE5D83CC1');
        $this->addSql('ALTER TABLE documento_cidades DROP FOREIGN KEY FK_AC019A11C33F7837');
        $this->addSql('ALTER TABLE documento_cidades DROP FOREIGN KEY FK_AC019A118BAC62AF');
        $this->addSql('ALTER TABLE documento_instituicoes DROP FOREIGN KEY FK_94FAE6EC33F7837');
        $this->addSql('ALTER TABLE documento_instituicoes DROP FOREIGN KEY FK_94FAE6E10405986');
        $this->addSql('ALTER TABLE estado_variacoes_nome DROP FOREIGN KEY FK_D0AFB7225D83CC1');
        $this->addSql('ALTER TABLE estados DROP FOREIGN KEY FK_222B2128F92F3E70');
        $this->addSql('ALTER TABLE estados DROP FOREIGN KEY FK_222B212898260155');
        $this->addSql('ALTER TABLE instituicao_variacoes_nome DROP FOREIGN KEY FK_1B6589E210405986');
        $this->addSql('ALTER TABLE instituicoes_ensino DROP FOREIGN KEY FK_165F7F18F92F3E70');
        $this->addSql('ALTER TABLE instituicoes_ensino DROP FOREIGN KEY FK_165F7F185D83CC1');
        $this->addSql('ALTER TABLE instituicoes_ensino DROP FOREIGN KEY FK_165F7F188BAC62AF');
        $this->addSql('ALTER TABLE instituicoes_ensino DROP FOREIGN KEY FK_165F7F18B03A8386');
        $this->addSql('ALTER TABLE instituicoes_ensino DROP FOREIGN KEY FK_165F7F18896DBBDE');
        $this->addSql('ALTER TABLE pais_variacoes_nome DROP FOREIGN KEY FK_7F11019EF92F3E70');
        $this->addSql('ALTER TABLE regioes DROP FOREIGN KEY FK_4193A038F92F3E70');
        $this->addSql('DROP TABLE cidade_variacoes_nome');
        $this->addSql('DROP TABLE cidades');
        $this->addSql('DROP TABLE documento_paises');
        $this->addSql('DROP TABLE documento_estados');
        $this->addSql('DROP TABLE documento_cidades');
        $this->addSql('DROP TABLE documento_instituicoes');
        $this->addSql('DROP TABLE estado_variacoes_nome');
        $this->addSql('DROP TABLE estados');
        $this->addSql('DROP TABLE instituicao_variacoes_nome');
        $this->addSql('DROP TABLE instituicoes_ensino');
        $this->addSql('DROP TABLE pais_variacoes_nome');
        $this->addSql('DROP TABLE paises');
        $this->addSql('DROP TABLE regioes');
    }
}
