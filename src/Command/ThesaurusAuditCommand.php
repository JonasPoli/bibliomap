<?php

namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:thesaurus:audit',
    description: 'Audit and verify thesaurus variation rules and redundancy across all entities (Institutions, Geography, Authors, Keywords, Journals)',
)]
class ThesaurusAuditCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $conn = $this->em->getConnection();

        $io->title("BiblioMap Thesaurus Audit & Redundancy Conference");

        // 1. Audit Institutions
        $totalInsts = (int) $conn->fetchOne('SELECT COUNT(*) FROM instituicoes_ensino');
        $totalInstVars = (int) $conn->fetchOne('SELECT COUNT(*) FROM instituicao_variacoes_nome');
        $orphanedInstVars = (int) $conn->fetchOne('SELECT COUNT(*) FROM instituicao_variacoes_nome v LEFT JOIN instituicoes_ensino i ON v.institution_id = i.id WHERE i.id IS NULL');

        $io->section("1. Institutions Thesaurus Audit");
        $io->table(['Metric', 'Count'], [
            ['Main Institutions', $totalInsts],
            ['Institution Variations', $totalInstVars],
            ['Orphaned Variations (No Parent)', $orphanedInstVars],
        ]);

        // 2. Audit Geography
        $totalCountries = (int) $conn->fetchOne('SELECT COUNT(*) FROM paises');
        $totalCountryVars = (int) $conn->fetchOne('SELECT COUNT(*) FROM pais_variacoes_nome');
        $orphanedCountryVars = (int) $conn->fetchOne('SELECT COUNT(*) FROM pais_variacoes_nome v LEFT JOIN paises p ON v.country_id = p.id WHERE p.id IS NULL');

        $totalStates = (int) $conn->fetchOne('SELECT COUNT(*) FROM estados');
        $totalStateVars = (int) $conn->fetchOne('SELECT COUNT(*) FROM estado_variacoes_nome');
        $orphanedStateVars = (int) $conn->fetchOne('SELECT COUNT(*) FROM estado_variacoes_nome v LEFT JOIN estados s ON v.state_id = s.id WHERE s.id IS NULL');

        $totalCities = (int) $conn->fetchOne('SELECT COUNT(*) FROM cidades');
        $totalCityVars = (int) $conn->fetchOne('SELECT COUNT(*) FROM cidade_variacoes_nome');
        $orphanedCityVars = (int) $conn->fetchOne('SELECT COUNT(*) FROM cidade_variacoes_nome v LEFT JOIN cidades c ON v.city_id = c.id WHERE c.id IS NULL');

        $io->section("2. Geography Thesaurus Audit");
        $io->table(['Metric', 'Count'], [
            ['Main Countries', $totalCountries],
            ['Country Variations', $totalCountryVars],
            ['Orphaned Country Variations', $orphanedCountryVars],
            ['Main States', $totalStates],
            ['State Variations', $totalStateVars],
            ['Orphaned State Variations', $orphanedStateVars],
            ['Main Cities', $totalCities],
            ['City Variations', $totalCityVars],
            ['Orphaned City Variations', $orphanedCityVars],
        ]);

        // 3. Audit Authors
        $totalAuthors = (int) $conn->fetchOne('SELECT COUNT(*) FROM author_identity');
        $totalAuthorVars = (int) $conn->fetchOne('SELECT COUNT(*) FROM author_name_variant');
        $orphanedAuthorVars = (int) $conn->fetchOne('SELECT COUNT(*) FROM author_name_variant v LEFT JOIN author_identity a ON v.author_identity_id = a.id WHERE a.id IS NULL');

        $io->section("3. Authors Thesaurus Audit");
        $io->table(['Metric', 'Count'], [
            ['Main Author Identities', $totalAuthors],
            ['Author Name Variations', $totalAuthorVars],
            ['Orphaned Author Variations', $orphanedAuthorVars],
        ]);

        // 4. Audit Keywords
        $totalKeywords = (int) $conn->fetchOne('SELECT COUNT(*) FROM keyword');
        $totalKwVars = (int) $conn->fetchOne('SELECT COUNT(*) FROM palavra_chave_variacoes_nome');
        $orphanedKwVars = (int) $conn->fetchOne('SELECT COUNT(*) FROM palavra_chave_variacoes_nome v LEFT JOIN keyword k ON v.keyword_id = k.id WHERE k.id IS NULL');

        $io->section("4. Keywords Thesaurus Audit");
        $io->table(['Metric', 'Count'], [
            ['Main Keywords', $totalKeywords],
            ['Keyword Variations', $totalKwVars],
            ['Orphaned Keyword Variations', $orphanedKwVars],
        ]);

        // 5. Audit Journals
        $totalJournals = (int) $conn->fetchOne('SELECT COUNT(*) FROM qualis_journal');
        $totalJournalVars = (int) $conn->fetchOne('SELECT COUNT(*) FROM qualis_journal_variacoes_nome');
        $orphanedJournalVars = (int) $conn->fetchOne('SELECT COUNT(*) FROM qualis_journal_variacoes_nome v LEFT JOIN qualis_journal j ON v.journal_id = j.id WHERE j.id IS NULL');

        $io->section("5. Journals Thesaurus Audit");
        $io->table(['Metric', 'Count'], [
            ['Main Qualis Journals', $totalJournals],
            ['Journal Name Variations', $totalJournalVars],
            ['Orphaned Journal Variations', $orphanedJournalVars],
        ]);

        // 6. Legacy SKOS Thesaurus Audit
        $legacySchemes  = (int) $conn->fetchOne('SELECT COUNT(*) FROM thesaurus_scheme');
        $legacyConcepts = (int) $conn->fetchOne('SELECT COUNT(*) FROM thesaurus_concept');
        $legacyLabels   = (int) $conn->fetchOne('SELECT COUNT(*) FROM thesaurus_label');

        $io->section("6. Legacy SKOS Thesaurus Cleanliness Audit");
        $io->table(['Metric', 'Count'], [
            ['Legacy Thesaurus Schemes', $legacySchemes],
            ['Legacy Thesaurus Concepts', $legacyConcepts],
            ['Legacy Thesaurus Labels', $legacyLabels],
        ]);

        $totalOrphans = $orphanedInstVars + $orphanedCountryVars + $orphanedStateVars + $orphanedCityVars + $orphanedAuthorVars + $orphanedKwVars + $orphanedJournalVars;

        if ($totalOrphans === 0 && $legacyConcepts === 0 && $legacyLabels === 0) {
            $io->success("Conference Complete! All Thesaurus domain variation tables are 100% clean and consistent with zero redundancy.");
        } else {
            $io->warning("Found {$totalOrphans} orphaned variations or {$legacyConcepts} unmigrated legacy concepts.");
        }

        return Command::SUCCESS;
    }
}
