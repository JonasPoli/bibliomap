<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BDTD no cadastro de bases (tabela global academic_database): aparece no cadastro de bases de todos os projetos.
 * Teses não têm ISSN: a importação da BDTD não cria vínculo de periódico. Nenhum número de projeto muda.
 */
final class Version20261119160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'BDTD em academic_database';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO academic_database (name, acronym, url, logo, file_formats, signature_columns)
            SELECT 'BDTD', 'bdtd', 'https://bdtd.ibict.br', NULL, '["zip","csv","ris"]', '["Autor(a)","Ano de defesa","Instituição de defesa","Tipo de documento"]'
            FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM academic_database WHERE acronym = 'bdtd')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM academic_database WHERE acronym = 'bdtd'");
    }
}
