<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Conditionally adds countries and institutions columns to the document table.
 * Prevents "Unknown column 'd0_.countries'" error on production server where schema was manually updated locally.
 */
final class Version20260602044300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Conditionally add countries and institutions JSON columns to document table if missing online';
    }

    public function up(Schema $schema): void
    {
        $conn = $this->connection;
        $hasCountries = false;
        $hasInstitutions = false;
        
        try {
            $conn->fetchOne('SELECT countries FROM document LIMIT 1');
            $hasCountries = true;
        } catch (\Throwable $e) {
            // Column does not exist
        }
        
        try {
            $conn->fetchOne('SELECT institutions FROM document LIMIT 1');
            $hasInstitutions = true;
        } catch (\Throwable $e) {
            // Column does not exist
        }
        
        if (!$hasCountries) {
            $this->addSql('ALTER TABLE document ADD countries JSON DEFAULT NULL');
        }
        if (!$hasInstitutions) {
            $this->addSql('ALTER TABLE document ADD institutions JSON DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document DROP countries, DROP institutions');
    }
}
