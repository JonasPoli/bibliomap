<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Conferência da importação: lidos, total informado pela base e aviso no dataset; identificador, posição e
 * detalhe em cada registro não importado. Só esquema (colunas anuláveis), nenhum dado muda.
 */
final class Version20261119140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Importação: colunas de conferência em dataset e dataset_skip';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dataset ADD read_count INT DEFAULT NULL, ADD expected_count INT DEFAULT NULL, ADD import_notice VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE dataset_skip ADD external_id VARCHAR(100) DEFAULT NULL, ADD position INT DEFAULT NULL, ADD detail VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dataset DROP read_count, DROP expected_count, DROP import_notice');
        $this->addSql('ALTER TABLE dataset_skip DROP external_id, DROP position, DROP detail');
    }
}
