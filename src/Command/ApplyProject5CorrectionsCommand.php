<?php

namespace App\Command;

use App\Entity\Institution;
use App\Entity\InstitutionVariation;
use App\Entity\Organization;
use App\Entity\InstitutionUnit;
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
    description: 'Apply Project 5 unresolved institutions corrections from CSV',
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
        $io->title("Applying Project 5 Unresolved Institutions Corrections");

        $basePath = $this->kernel->getProjectDir() . '/docs/ajustes';
        $csvPath = $basePath . '/tabela_cadastro_instituicoes_nao_encontradas_projeto_5.csv';

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
            $raw = trim($row['raw_institution_name'] ?? '');
            $acao = trim($row['acao'] ?? '');
            $tipo = trim($row['tipo_sugerido'] ?? '');
            $canonical = trim($row['nome_canonico_sugerido'] ?? '');
            $obs = trim($row['observacao'] ?? '');

            if ($raw === '') continue;

            $normRaw = DocumentEnrichmentService::normalize($raw);

            // 1. Add Variation
            if (strpos($acao, 'Adicionar variação') !== false || strpos($acao, 'Atualizar/Adicionar variação') !== false) {
                $inst = $this->em->getRepository(Institution::class)->findOneBy(['officialName' => $canonical]);
                if (!$inst && $canonical !== '') {
                    $inst = $this->em->getRepository(Institution::class)->findOneBy(['shortName' => $canonical]);
                }

                if ($inst) {
                    $exists = $conn->fetchOne(
                        'SELECT id FROM instituicao_variacoes_nome WHERE institution_id = ? AND normalized_name = ?',
                        [$inst->getId(), $normRaw]
                    );

                    if (!$exists) {
                        $conn->insert('instituicao_variacoes_nome', [
                            'institution_id' => $inst->getId(),
                            'variation_name' => $raw,
                            'variation_type' => 'scopus_abbreviation',
                            'normalized_name' => $normRaw,
                            'status' => 1,
                        ]);
                        $variationsAdded++;
                    }
                } else {
                    $io->warning("Target institution not found for: '{$canonical}'. Creating new Institution.");
                    $inst = new Institution();
                    $inst->setOfficialName($canonical);
                    $inst->setShortName($canonical);
                    $inst->setStatus(1);
                    $this->em->persist($inst);
                    $this->em->flush();

                    $conn->insert('instituicao_variacoes_nome', [
                        'institution_id' => $inst->getId(),
                        'variation_name' => $raw,
                        'variation_type' => 'scopus_abbreviation',
                        'normalized_name' => $normRaw,
                        'status' => 1,
                    ]);
                    $variationsAdded++;
                }

            // 2. Cadastrar Organização
            } elseif (strpos($acao, 'Cadastrar organização') !== false || strpos($acao, 'Cadastrar órgão') !== false || strpos($acao, 'Cadastrar instituição/organização') !== false || strpos($acao, 'Separar em duas') !== false) {
                if ($canonical === '-') $canonical = $raw;

                $exists = $conn->fetchOne(
                    'SELECT id FROM organizacoes WHERE original_variation_name = ? OR canonical_name = ?',
                    [$raw, $canonical]
                );

                if (!$exists) {
                    $conn->insert('organizacoes', [
                        'original_variation_name' => $raw,
                        'canonical_name' => $canonical,
                        'type' => $tipo,
                        'confidence' => 'High',
                        'observation' => $obs,
                    ]);
                    $organizationsAdded++;
                }

            // 3. Cadastrar Unidade Interna
            } elseif (strpos($acao, 'Cadastrar unidade') !== false) {
                $exists = $conn->fetchOne(
                    'SELECT id FROM instituicao_unidades WHERE original_variation_name = ? OR canonical_name = ?',
                    [$raw, $canonical]
                );

                if (!$exists) {
                    $parentName = null;
                    if (strpos($raw, 'USP') !== false || strpos($canonical, 'USP') !== false) {
                        $parentName = 'Universidade de São Paulo';
                    } elseif (strpos($raw, 'UFRJ') !== false || strpos($canonical, 'UFRJ') !== false) {
                        $parentName = 'Universidade Federal do Rio de Janeiro';
                    } elseif (strpos($raw, 'CAAS') !== false || strpos($canonical, 'CAAS') !== false) {
                        $parentName = 'Chinese Academy of Agricultural Sciences';
                    } elseif (strpos($raw, 'CAS') !== false || strpos($canonical, 'Chinese Academy of Sciences') !== false) {
                        $parentName = 'Chinese Academy of Sciences';
                    } elseif (strpos($raw, 'NOVA') !== false || strpos($canonical, 'NOVA') !== false) {
                        $parentName = 'Universidade NOVA de Lisboa';
                    } elseif (strpos($raw, 'Sfax') !== false || strpos($canonical, 'Sfax') !== false) {
                        $parentName = 'University of Sfax';
                    } elseif (strpos($raw, 'UNSW') !== false || strpos($canonical, 'New South Wales') !== false) {
                        $parentName = 'University of New South Wales';
                    }

                    $parentId = null;
                    if ($parentName !== null) {
                        $parentId = $conn->fetchOne('SELECT id FROM instituicoes_ensino WHERE official_name = ?', [$parentName]);
                        $parentId = $parentId !== false ? (int)$parentId : null;
                    }

                    $conn->insert('instituicao_unidades', [
                        'original_variation_name' => $raw,
                        'canonical_name' => $canonical,
                        'type' => $tipo,
                        'confidence' => 'High',
                        'observation' => $obs,
                        'parent_institution_id' => $parentId,
                    ]);
                    $unitsAdded++;
                }
            }
        }

        $io->success([
            "Corrections applied successfully!",
            "Variations Added: {$variationsAdded}",
            "Organizations Added: {$organizationsAdded}",
            "Units Added: {$unitsAdded}"
        ]);

        return Command::SUCCESS;
    }
}
