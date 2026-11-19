<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LISTA (EBSCO) no cadastro de bases (tabela global academic_database): aparece no cadastro de todos os projetos
 * e, como os registros trazem ISSN, o enriquecimento passa a vincular à LISTA os periódicos dos documentos
 * importados dela. Nenhum Qualis existente muda.
 */
final class Version20261119170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'LISTA (EBSCO) em academic_database';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO academic_database (name, acronym, url, logo, file_formats, signature_columns)
            SELECT 'LISTA', 'lista', 'https://research.ebsco.com', NULL, '["csv"]', '["title","publicationDate","plink","shortDbName","accessionNumber"]'
            FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM academic_database WHERE acronym = 'lista')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM academic_database WHERE acronym = 'lista'");
    }
}
