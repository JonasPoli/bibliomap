<?php

namespace App\Command;

use App\Entity\Country;
use App\Entity\CountryVariation;
use App\Service\Import\DocumentEnrichmentService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:deploy:setup-thesaurus',
    description: 'Post-deployment routine: updates DB schema for foundation/extinction years, deduplicates variations, seeds historical countries, and audits thesaurus integrity on production',
)]
class DeploySetupThesaurusCommand extends Command
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

        $io->title("BiblioMap Production Deployment Setup Routine");

        // 1. Ensure Columns Exist
        $io->section("1. Updating Database Schema (foundation & extinction years)");
        $this->addCol($conn, "instituicoes_ensino", "ano_fundacao", $io);
        $this->addCol($conn, "instituicoes_ensino", "ano_extincao", $io);
        $this->addCol($conn, "paises", "ano_fundacao", $io);
        $this->addCol($conn, "paises", "ano_extincao", $io);

        // 2. Deduplicate Variation Tables
        $io->section("2. Deduplicating Variation Tables");
        $tables = [
            "instituicao_variacoes_nome" => "institution_id",
            "pais_variacoes_nome" => "country_id",
            "estado_variacoes_nome" => "state_id",
            "cidade_variacoes_nome" => "city_id",
            "author_name_variant" => "author_identity_id",
            "palavra_chave_variacoes_nome" => "keyword_id",
            "qualis_journal_variacoes_nome" => "journal_id",
        ];

        foreach ($tables as $tbl => $col) {
            $deleted = $conn->executeStatement("
                DELETE t1 FROM $tbl t1
                INNER JOIN $tbl t2 
                WHERE t1.id > t2.id 
                  AND t1.$col = t2.$col 
                  AND t1.normalized_name = t2.normalized_name
            ");
            $io->writeln("Removed {$deleted} duplicate rows from {$tbl}");
        }

        // 3. Seed Historical Countries
        $io->section("3. Seeding Historical & Recent Countries");
        $seedCommand = $this->getApplication()->find('app:geography:seed-historical-countries');
        $seedCommand->run(new ArrayInput([]), $output);

        // 4. Audit Thesaurus
        $io->section("4. Running Final Thesaurus Audit");
        $auditCommand = $this->getApplication()->find('app:thesaurus:audit');
        $auditCommand->run(new ArrayInput([]), $output);

        $io->success("Production Deployment Setup Routine completed successfully!");
        return Command::SUCCESS;
    }

    private function addCol($conn, string $table, string $col, SymfonyStyle $io): void
    {
        $check = $conn->fetchAllAssociative("SHOW COLUMNS FROM $table LIKE ?", [$col]);
        if (empty($check)) {
            $conn->executeStatement("ALTER TABLE $table ADD COLUMN $col INT DEFAULT NULL");
            $io->writeln("Added column {$col} to {$table}");
        } else {
            $io->writeln("Column {$col} already exists in {$table}");
        }
    }
}
