<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Diário só-de-inserção das ações que repontuam, apagam ou fundem autores, palavras-chave e instituições
 * (change tesauro-em-camadas, fase 0). Só cria as duas tabelas: nenhum dado existente muda.
 * Sem chave estrangeira de propósito: o diário precisa sobreviver à exclusão da entidade e do documento.
 * Idempotente: confere o information_schema antes de criar e antes de remover.
 */
final class Version20261112160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'authority_merge_log e authority_merge_log_link (diário das fusões e exclusões de autoridades)';
    }

    private function tableExists(string $table): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        ) > 0;
    }

    public function up(Schema $schema): void
    {
        if (!$this->tableExists('authority_merge_log')) {
            $this->addSql('CREATE TABLE authority_merge_log (
                id INT AUTO_INCREMENT NOT NULL,
                created_at DATETIME NOT NULL,
                user_id INT DEFAULT NULL,
                project_id INT DEFAULT NULL,
                origin VARCHAR(30) NOT NULL,
                action VARCHAR(30) NOT NULL,
                entity_type VARCHAR(30) NOT NULL,
                source_id INT NOT NULL,
                target_id INT DEFAULT NULL,
                source_label VARCHAR(500) NOT NULL,
                payload JSON DEFAULT NULL,
                INDEX idx_aml_entity (entity_type, source_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        }
        if (!$this->tableExists('authority_merge_log_link')) {
            $this->addSql('CREATE TABLE authority_merge_log_link (
                id INT AUTO_INCREMENT NOT NULL,
                log_id INT NOT NULL,
                document_id INT NOT NULL,
                link_table VARCHAR(40) NOT NULL,
                old_entity_id INT NOT NULL,
                original_text VARCHAR(255) DEFAULT NULL,
                position INT DEFAULT NULL,
                INDEX idx_amll_log (log_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        }
    }

    public function down(Schema $schema): void
    {
        if ($this->tableExists('authority_merge_log_link')) {
            $this->addSql('DROP TABLE authority_merge_log_link');
        }
        if ($this->tableExists('authority_merge_log')) {
            $this->addSql('DROP TABLE authority_merge_log');
        }
    }
}
