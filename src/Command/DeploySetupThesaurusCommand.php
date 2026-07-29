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

        // 1. Ensure Columns & Tables Exist
        $io->section("1. Updating Database Schema");
        
        $emecCols = [
            'razao_social' => 'VARCHAR(255) DEFAULT NULL',
            'cnpj' => 'VARCHAR(20) DEFAULT NULL',
            'codigo_mantenedora' => 'INT DEFAULT NULL',
            'codigo_ies' => 'INT DEFAULT NULL',
            'latitude' => 'VARCHAR(50) DEFAULT NULL',
            'longitude' => 'VARCHAR(50) DEFAULT NULL',
            'telefone' => 'VARCHAR(100) DEFAULT NULL',
            'endereco_sede' => 'VARCHAR(255) DEFAULT NULL',
            'organizacao_academica' => 'VARCHAR(100) DEFAULT NULL',
            'tipo_credenciamento' => 'VARCHAR(150) DEFAULT NULL',
            'categoria' => 'VARCHAR(100) DEFAULT NULL',
            'categoria_administrativa' => 'VARCHAR(100) DEFAULT NULL',
            'data_criacao' => 'DATE DEFAULT NULL',
            'ci' => 'VARCHAR(10) DEFAULT NULL',
            'ano_ci' => 'INT DEFAULT NULL',
            'ci_ead' => 'VARCHAR(10) DEFAULT NULL',
            'ano_ci_ead' => 'INT DEFAULT NULL',
            'igc' => 'VARCHAR(10) DEFAULT NULL',
            'ano_igc' => 'INT DEFAULT NULL',
            'reitor' => 'VARCHAR(150) DEFAULT NULL',
            'representante_legal' => 'VARCHAR(150) DEFAULT NULL',
            'sinalizacoes_vigentes' => 'VARCHAR(255) DEFAULT NULL',
            'situacao_ies' => 'VARCHAR(50) DEFAULT NULL',
            'vantagepoint' => 'VARCHAR(255) DEFAULT NULL',
            'ano_fundacao' => 'INT DEFAULT NULL',
            'ano_extincao' => 'INT DEFAULT NULL',
        ];

        foreach ($emecCols as $col => $typeDef) {
            $this->addCol($conn, "instituicoes_ensino", $col, $typeDef, $io);
        }

        $this->addCol($conn, "paises", "ano_fundacao", "INT DEFAULT NULL", $io);
        $this->addCol($conn, "paises", "ano_extincao", "INT DEFAULT NULL", $io);

        // Ensure qualis_journal_variacoes_nome table exists
        $conn->executeStatement("
            CREATE TABLE IF NOT EXISTS qualis_journal_variacoes_nome (
                id INT AUTO_INCREMENT NOT NULL,
                journal_id INT NOT NULL,
                variation_name VARCHAR(500) NOT NULL,
                normalized_name VARCHAR(500) NOT NULL,
                variation_type VARCHAR(50) DEFAULT 'alternative' NOT NULL,
                status TINYINT(1) DEFAULT 1 NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX IDX_JOURNAL_VAR_NORM (normalized_name),
                INDEX IDX_JOURNAL_VAR_JOURNAL (journal_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ");
        $io->writeln("Ensured table qualis_journal_variacoes_nome exists.");

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
            $tableCheck = $conn->fetchAllAssociative("SHOW TABLES LIKE ?", [$tbl]);
            if (empty($tableCheck)) {
                $io->writeln("Table {$tbl} does not exist, skipping deduplication.");
                continue;
            }

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

    private function addCol($conn, string $table, string $col, string $typeDef, SymfonyStyle $io): void
    {
        $check = $conn->fetchAllAssociative("SHOW COLUMNS FROM $table LIKE ?", [$col]);
        if (empty($check)) {
            $conn->executeStatement("ALTER TABLE $table ADD COLUMN $col $typeDef");
            $io->writeln("Added column {$col} to {$table}");
        } else {
            $io->writeln("Column {$col} already exists in {$table}");
        }
    }
}
