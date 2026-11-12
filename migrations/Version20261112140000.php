<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Contador de versão dos dados que alimentam as análises (uma linha, id = 1).
 * O cache dos relatórios usa a versão na chave: toda gravação sobe o contador e os resultados
 * calculados antes deixam de ser usados. Só cria a tabela: nenhum dado existente muda.
 */
final class Version20261112140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'analytics_data_version (versão dos dados para o cache das análises)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS analytics_data_version (
            id SMALLINT NOT NULL,
            version BIGINT UNSIGNED NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('INSERT IGNORE INTO analytics_data_version (id, version, updated_at) VALUES (1, 1, NOW())');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS analytics_data_version');
    }
}
