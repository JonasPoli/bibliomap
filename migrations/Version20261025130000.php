<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TextNormalizer::cleanDisplayValue removia ")" do fim dos rótulos mesmo quando o
 * parêntese estava balanceado: "Artificial Intelligence (AI)" virava
 * "artificial intelligence (AI". Restaura o ")" quando o valor original o tinha.
 */
final class Version20261025130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Restaura ")" final removido indevidamente de palavras-chave e nomes de autores';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE keyword SET keyword_display = CONCAT(keyword_display, ')')
            WHERE keyword_display LIKE '%(%' AND keyword_display NOT LIKE '%)%' AND TRIM(keyword_original) LIKE '%)'");

        $this->addSql("UPDATE author_name_variant SET display_name = CONCAT(display_name, ')')
            WHERE display_name LIKE '%(%' AND display_name NOT LIKE '%)%' AND TRIM(original_name) LIKE '%)'");

        $this->addSql("UPDATE author_identity a SET a.preferred_name = CONCAT(a.preferred_name, ')')
            WHERE a.preferred_name LIKE '%(%' AND a.preferred_name NOT LIKE '%)%'
              AND EXISTS (
                  SELECT 1 FROM document_author da
                  WHERE da.author_identity_id = a.id
                    AND TRIM(da.original_name) COLLATE utf8mb4_unicode_ci = CONCAT(a.preferred_name, ')') COLLATE utf8mb4_unicode_ci
              )");
    }

    public function down(Schema $schema): void
    {
        // Correção de dados; não há estado anterior válido para restaurar.
    }
}
