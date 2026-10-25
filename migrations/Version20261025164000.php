<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * O leitor do Lens gravava "Source Country" (país do periódico) como país do documento,
 * distorcendo mapas e redes de países. O CSV do Lens não tem afiliações: em documentos
 * source = 'lens' o JSON countries vinha só dessa coluna, e os vínculos geográficos
 * (país/estado/cidade) só dele. Apagá-los é seguro; instituições não são tocadas.
 */
final class Version20261025164000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove país do periódico (Source Country) gravado como país de documentos do Lens';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("DELETE dp FROM documento_paises dp JOIN document d ON d.id = dp.document_id WHERE d.source = 'lens'");
        $this->addSql("DELETE de FROM documento_estados de JOIN document d ON d.id = de.document_id WHERE d.source = 'lens'");
        $this->addSql("DELETE dc FROM documento_cidades dc JOIN document d ON d.id = dc.document_id WHERE d.source = 'lens'");
        $this->addSql("UPDATE document SET countries = NULL WHERE source = 'lens' AND countries IS NOT NULL");
    }

    public function down(Schema $schema): void
    {
    }
}
