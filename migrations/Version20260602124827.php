<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Conditionally adds an index to the keyword table on the type column for high performance.
 */
final class Version20260602124827 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create index on keyword type column for dashboard performance';
    }

    public function up(Schema $schema): void
    {
        $conn = $this->connection;
        $hasIndex = false;
        
        try {
            // Check if index already exists
            $indexes = $conn->fetchAllAssociative("SHOW INDEX FROM keyword WHERE Key_name = 'idx_keyword_type'");
            if (!empty($indexes)) {
                $hasIndex = true;
            }
        } catch (\Throwable $e) {
            // Table might not exist or other error
        }

        if (!$hasIndex) {
            $this->addSql('CREATE INDEX idx_keyword_type ON keyword (type)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_keyword_type ON keyword');
    }
}
