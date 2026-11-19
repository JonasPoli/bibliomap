<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BRAPCI no cadastro de bases (tabela global academic_database): aparece no cadastro e no vínculo de periódicos
 * de todos os projetos. O CSV da BRAPCI não traz ISSN, então a importação não cria vínculo de periódico.
 * Nenhum número de projeto muda.
 */
final class Version20261119150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'BRAPCI em academic_database';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO academic_database (name, acronym, url, logo, file_formats, signature_columns)
            SELECT 'BRAPCI', 'brapci', 'https://brapci.inf.br', NULL, '["csv","xls","ris"]', '["ID","CLASS","YEAR","PUBLICATION","LEGEND"]'
            FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM academic_database WHERE acronym = 'brapci')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM academic_database WHERE acronym = 'brapci'");
    }
}
