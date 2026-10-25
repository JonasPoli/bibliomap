<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * O WosImporter tratava TC "0" como ausente (string "0" é falsy em PHP) e gravava
 * cited_by = NULL para todo artigo sem citações. Como toda exportação WoS traz TC,
 * cited_by NULL em documentos WoS significa 0 citações.
 */
final class Version20261025120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Corrige cited_by NULL (deveria ser 0) em documentos importados do Web of Science';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE document SET cited_by = 0 WHERE source = 'wos' AND cited_by IS NULL");
    }

    public function down(Schema $schema): void
    {
        // Irreversível: não há como distinguir os zeros corrigidos dos zeros importados depois.
    }
}
