<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rússia, Turquia e União Soviética estavam em três "continentes" diferentes
 * ("Europa/Ásia", "Ásia/Europa", "Europa / Ásia"), fragmentando gráficos e mapas.
 */
final class Version20261025140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Padroniza continente de países transcontinentais como "Europa/Ásia"';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE paises SET continente = 'Europa/Ásia' WHERE continente IN ('Ásia/Europa', 'Europa / Ásia')");
        $this->addSql("UPDATE classification_group SET continente = 'Europa/Ásia' WHERE continente IN ('Ásia/Europa', 'Europa / Ásia')");
    }

    public function down(Schema $schema): void
    {
    }
}
