<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tratamento de palavras-chave / tesauro:
 * - logs de simulação ficavam 'pending' e apareciam na revisão como sugestões reais;
 * - sugestões fuzzy do modo Real eram gravadas 'applied' (e sem conceito) e nunca chegavam à revisão;
 * - palavras-chave ligadas a conceitos de outros esquemas (lugar, instituição...)
 *   pelo applyThesaurusMappings sem filtro de esquema.
 */
final class Version20261025162000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Corrige status dos logs do tratamento de palavras-chave e desfaz ligações de keyword a conceitos fora do esquema keyword';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE keyword_treatment_log l JOIN keyword_treatment_job j ON j.id = l.job_id
                       SET l.status = 'simulated'
                       WHERE j.mode = 'dry_run' AND l.status = 'pending'");
        $this->addSql("UPDATE keyword_treatment_log l JOIN keyword_treatment_job j ON j.id = l.job_id
                       SET l.status = 'pending'
                       WHERE j.mode = 'execute' AND l.action = 'fuzzy_review_required' AND l.status = 'applied'");
        // Sugestões antigas sem conceito no log: recupera o conceito da sugestão pendente em thesaurus_match
        $this->addSql("UPDATE keyword_treatment_log l
                       JOIN thesaurus_match m ON m.keyword_id = l.keyword_id AND m.status = 'pending' AND m.match_method = 'fuzzy'
                       SET l.new_thesaurus_concept_id = m.concept_id
                       WHERE l.action = 'fuzzy_review_required' AND l.status = 'pending' AND l.new_thesaurus_concept_id IS NULL");
        $this->addSql("UPDATE keyword k
                       JOIN thesaurus_concept tc ON tc.id = k.thesaurus_concept_id
                       JOIN thesaurus_scheme ts ON ts.id = tc.scheme_id
                       SET k.thesaurus_concept_id = NULL
                       WHERE ts.slug <> 'keyword'");
    }

    public function down(Schema $schema): void
    {
    }
}
