<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * author_identity (~150 mil linhas) só tinha a PK, mas importação, fila de revisão,
 * busca de destino de fusão e importação de CSV consultam por nome.
 */
final class Version20261025170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Índices de nome em author_identity';
    }

    public function up(Schema $schema): void
    {
        $existing = array_map('strtolower', array_keys($this->connection->createSchemaManager()->listTableIndexes('author_identity')));
        foreach ([
            'IDX_author_identity_normalized' => '(normalized_name)',
            'IDX_author_identity_preferred' => '(preferred_name)',
            'IDX_author_identity_status_normalized' => '(status, normalized_name)',
        ] as $name => $cols) {
            if (!in_array(strtolower($name), $existing, true)) {
                $this->addSql("CREATE INDEX {$name} ON author_identity {$cols}");
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_author_identity_normalized ON author_identity');
        $this->addSql('DROP INDEX IDX_author_identity_preferred ON author_identity');
        $this->addSql('DROP INDEX IDX_author_identity_status_normalized ON author_identity');
    }
}
