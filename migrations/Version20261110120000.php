<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Versão da expansão pelo tesauro usada na última classificação do projeto.
 * NULL = calculada antes da correção que incluiu as variações das palavras-chave (aviso na tela).
 * Só acrescenta a coluna: nenhum dado existente muda.
 */
final class Version20261110120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'bibliometric_project.classification_expansion_version (SMALLINT NULL)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bibliometric_project ADD classification_expansion_version SMALLINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bibliometric_project DROP classification_expansion_version');
    }
}
