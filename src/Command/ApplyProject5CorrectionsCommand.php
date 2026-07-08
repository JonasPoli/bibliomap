<?php

namespace App\Command;

use App\Entity\Institution;
use App\Service\Import\DocumentEnrichmentService;
use Doctrine\ORM\EntityManagerInterface;
use League\Csv\Reader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(
    name: 'app:geography:apply-corrections-p5',
    description: 'Apply updated Project 5 unresolved institutions corrections from CSV',
)]
class ApplyProject5CorrectionsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly KernelInterface $kernel
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', '1024M');
        $io = new SymfonyStyle($input, $output);
        $io->title("Applying Updated Project 5 Unresolved Institutions Corrections");

        $basePath = $this->kernel->getProjectDir() . '/docs/ajustes';
        $csvPath = $basePath . '/recomendacoes_instituicoes_nao_encontradas_projeto_5_atualizado.csv';

        if (!file_exists($csvPath)) {
            $io->error("CSV file not found at: {$csvPath}");
            return Command::FAILURE;
        }

        $reader = Reader::createFromPath($csvPath, 'r');
        $reader->setHeaderOffset(0);

        $conn = $this->em->getConnection();

        $variationsAdded = 0;
        $organizationsAdded = 0;
        $unitsAdded = 0;

        foreach ($reader as $row) {
            $raw = trim($row['raw'] ?? '');
            $acao = trim($row['acao'] ?? '');
            $tabela = trim($row['tabela'] ?? '');
            $canonical = trim($row['canonical'] ?? '');
            $tipo = trim($row['tipo'] ?? '');
            $paisName = trim($row['pais'] ?? '');
            $parentName = trim($row['parent'] ?? '');
            $confianca = trim($row['confianca'] ?? 'High');
            $obs = trim($row['observacao'] ?? '');

            if ($raw === '') continue;

            $normRaw = DocumentEnrichmentService::normalize($raw);

            // Skip blacklist or ambiguous items
            if ($acao === 'NAO_CADASTRAR_BLACKLIST' || $acao === 'REVISAR_PAIS_DO_DOCUMENTO') {
                $io->note("Skipping blacklisted or ambiguous item: '{$raw}'");
                continue;
            }

            // 1. Cadastrar Unidade Interna
            if ($acao === 'CADASTRAR_UNIDADE' || $acao === 'CADASTRAR_UNIDADE_E_ORG_SEPARADA') {
                // If it is composite, we register ITI/LARSyS unit and ARDITI organization
                if ($raw === 'Interact Technol Inst ITI LARSyS & ARDITI') {
                    // Check organization ARDITI
                    $orgExists = $conn->fetchOne('SELECT id FROM organizacoes WHERE original_variation_name = ?', ['ARDITI']);
                    if (!$orgExists) {
                        $conn->insert('organizacoes', [
                            'original_variation_name' => 'ARDITI',
                            'canonical_name' => 'Agência Regional para o Desenvolvimento da Investigação, Tecnologia e Inovação',
                            'type' => 'Agência pública de pesquisa e inovação',
                            'confidence' => 'High',
                            'observation' => 'Entidade mencionada junto ao ITI/LARSyS.'
                        ]);
                        $organizationsAdded++;
                    }
                }

                // Check unit exists
                $unitExists = $conn->fetchOne(
                    'SELECT id FROM instituicao_unidades WHERE original_variation_name = ? OR canonical_name = ?',
                    [$raw, $canonical]
                );

                if (!$unitExists) {
                    $parentId = null;
                    if ($parentName !== 'N/A' && $parentName !== 'Indefinido' && $parentName !== '') {
                        // Find parent
                        $parentId = $conn->fetchOne(
                            'SELECT id FROM instituicoes_ensino WHERE official_name = ? OR sigla = ?',
                            [$parentName, $parentName]
                        );
                        
                        // If parent not found and country is specified, create it!
                        if (!$parentId) {
                            $countryId = null;
                            if ($paisName !== '' && $paisName !== 'Indefinido') {
                                $countryId = $conn->fetchOne(
                                    'SELECT id FROM paises WHERE common_name = ? OR official_name = ?',
                                    [$paisName, $paisName]
                                );
                            }
                            $countryId = $countryId !== false ? (int)$countryId : null;

                            $io->warning("Parent institution '{$parentName}' not found. Creating it under country: '{$paisName}'.");
                            $conn->insert('instituicoes_ensino', [
                                'official_name' => $parentName,
                                'short_name' => $parentName,
                                'institution_type' => 'Universidade',
                                'natureza' => 'Pública',
                                'country_id' => $countryId,
                                'status' => 1,
                                'created_at' => date('Y-m-d H:i:s'),
                                'updated_at' => date('Y-m-d H:i:s'),
                            ]);
                            $parentId = (int)$conn->lastInsertId();
                        } else {
                            $parentId = (int)$parentId;
                        }
                    }

                    $conn->insert('instituicao_unidades', [
                        'original_variation_name' => $raw,
                        'canonical_name' => $canonical,
                        'type' => $tipo,
                        'confidence' => $confianca === 'baixa' ? 'Low' : 'High',
                        'observation' => $obs,
                        'parent_institution_id' => $parentId,
                    ]);
                    $unitsAdded++;
                }

            // 2. Cadastrar como Organização
            } else {
                // Check if already exists in organizacoes
                $exists = $conn->fetchOne(
                    'SELECT id FROM organizacoes WHERE original_variation_name = ? OR canonical_name = ?',
                    [$raw, $canonical]
                );

                if (!$exists) {
                    $conn->insert('organizacoes', [
                        'original_variation_name' => $raw,
                        'canonical_name' => $canonical,
                        'type' => $tipo,
                        'confidence' => $confianca === 'baixa' ? 'Low' : 'High',
                        'observation' => $obs,
                    ]);
                    $organizationsAdded++;
                }
            }
        }

        $io->success([
            "Updated Project 5 corrections applied successfully!",
            "Organizations Added: {$organizationsAdded}",
            "Units Added: {$unitsAdded}"
        ]);

        return Command::SUCCESS;
    }
}
