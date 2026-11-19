<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * OpenAlex com leitor: formatos e colunas-assinatura na linha da base (tabela global academic_database).
 * Nenhum número de projeto muda.
 */
final class Version20261119180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'OpenAlex: formatos e colunas-assinatura em academic_database';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE academic_database SET file_formats = '["json","csv","txt","ris"]', signature_columns = '["Work ID","Title","Year","Citation count"]'
            WHERE acronym = 'openalex'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE academic_database SET file_formats = '["csv"]', signature_columns = '[]' WHERE acronym = 'openalex'
            SQL);
    }
}
