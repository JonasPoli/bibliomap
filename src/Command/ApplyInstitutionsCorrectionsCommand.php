<?php

namespace App\Command;

use App\Entity\Institution;
use App\Entity\InstitutionVariation;
use App\Entity\Country;
use App\Entity\CountryVariation;
use App\Entity\State;
use App\Entity\StateVariation;
use App\Entity\City;
use App\Entity\CityVariation;
use App\Service\Import\DocumentEnrichmentService;
use Doctrine\ORM\EntityManagerInterface;
use League\Csv\Reader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:apply-corrections',
    description: 'Apply institution corrections from docs/ajustes CSV files',
)]
class ApplyInstitutionsCorrectionsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', '2048M');
        set_time_limit(1800);

        $io = new SymfonyStyle($input, $output);
        $csvPath = '/Users/jonaspoli/work/html/bibliometric/docs/ajustes/correcoes_instituicoes_detalhado.csv';

        if (!file_exists($csvPath)) {
            $io->error("File not found: {$csvPath}");
            return Command::FAILURE;
        }

        $io->title("Applying Institution Corrections from CSV");

        // 1. Preload Geography maps
        $io->text("Preloading geography data...");
        $countries = $this->em->getRepository(Country::class)->findAll();
        $countryMap = [];
        foreach ($countries as $c) {
            $countryMap[DocumentEnrichmentService::normalize($c->getCommonName())] = $c;
            $countryMap[DocumentEnrichmentService::normalize($c->getOfficialName())] = $c;
            if ($c->getSigla()) {
                $countryMap[DocumentEnrichmentService::normalize($c->getSigla())] = $c;
            }
        }
        $countryVars = $this->em->getRepository(CountryVariation::class)->findAll();
        foreach ($countryVars as $cv) {
            $countryMap[$cv->getNormalizedName()] = $cv->getCountry();
        }

        $states = $this->em->getRepository(State::class)->findAll();
        $stateMap = [];
        foreach ($states as $s) {
            $coId = $s->getCountry()->getId();
            $stateMap[$coId . '_' . DocumentEnrichmentService::normalize($s->getOfficialName())] = $s;
            if ($s->getSigla()) {
                $stateMap[$coId . '_' . DocumentEnrichmentService::normalize($s->getSigla())] = $s;
            }
        }
        $stateVars = $this->em->getRepository(StateVariation::class)->findAll();
        foreach ($stateVars as $sv) {
            $coId = $sv->getState()->getCountry()->getId();
            $stateMap[$coId . '_' . $sv->getNormalizedName()] = $sv->getState();
        }

        $cities = $this->em->getRepository(City::class)->findAll();
        $cityMap = [];
        foreach ($cities as $ct) {
            $coId = $ct->getCountry()->getId();
            $stId = $ct->getState() ? $ct->getState()->getId() : 'null';
            $cityMap[$coId . '_' . $stId . '_' . $ct->getNormalizedName()] = $ct;
        }
        $cityVars = $this->em->getRepository(CityVariation::class)->findAll();
        foreach ($cityVars as $ctv) {
            $city = $ctv->getCity();
            $coId = $city->getCountry()->getId();
            $stId = $city->getState() ? $city->getState()->getId() : 'null';
            $cityMap[$coId . '_' . $stId . '_' . $ctv->getNormalizedName()] = $city;
        }

        // 2. Preload Institutions
        $io->text("Preloading institutions...");
        $institutions = $this->em->getRepository(Institution::class)->findAll();
        $instMap = [];
        foreach ($institutions as $inst) {
            $instMap[DocumentEnrichmentService::normalize($inst->getOfficialName())] = $inst;
        }

        $reader = Reader::createFromPath($csvPath, 'r');
        $reader->setHeaderOffset(0);

        $removedCount = 0;
        $mergedCount = 0;
        $updatedCount = 0;
        $batchSize = 200;
        $i = 0;

        $conn = $this->em->getConnection();

        foreach ($reader->getRecords() as $record) {
            $currentName = trim($record['current_official_name'] ?? '');
            if ($currentName === '') continue;

            $normCurrent = DocumentEnrichmentService::normalize($currentName);
            $inst = $instMap[$normCurrent] ?? null;

            if (!$inst) {
                continue;
            }

            $recommended = trim($record['recommended_correction'] ?? '');
            $issueCodes = explode(';', $record['issue_codes'] ?? '');

            // Action: Deletion (addresses, companies, hospitals, government agencies recommended to be removed)
            $shouldRemove = str_contains($recommended, 'Remover') || 
                            str_contains($recommended, 'remover') || 
                            in_array('ENDERECO_IMPORTADO_COMO_INSTITUICAO', $issueCodes) ||
                            in_array('EMPRESA_IMPORTADA_COMO_INSTITUICAO', $issueCodes) ||
                            in_array('HOSPITAL_OU_UNIDADE_SAUDE_NAO_IES', $issueCodes) ||
                            in_array('ORGAO_GOVERNAMENTAL_AGENCIA_OU_ORGANISMO', $issueCodes);

            if ($shouldRemove) {
                // Delete relation links first
                $conn->executeStatement('DELETE FROM documento_instituicoes WHERE institution_id = ?', [$inst->getId()]);
                
                // Delete variations
                foreach ($inst->getVariations() as $v) {
                    $this->em->remove($v);
                }
                
                // Delete institution
                $this->em->remove($inst);
                unset($instMap[$normCurrent]);
                $removedCount++;
                
                if ((++$i % $batchSize) === 0) {
                    $this->em->flush();
                }
                continue;
            }

            // Action: Renaming or Merging
            $suggestedName = trim($record['suggested_official_name'] ?? '');
            if ($suggestedName !== '' && $suggestedName !== $currentName) {
                $normSuggested = DocumentEnrichmentService::normalize($suggestedName);
                $target = $instMap[$normSuggested] ?? null;

                if ($target) {
                    // Target exists, so we MERGE
                    
                    // Reassign document relation links
                    $conn->executeStatement(
                        'DELETE di1 FROM documento_instituicoes di1
                         JOIN documento_instituicoes di2 ON di1.document_id = di2.document_id
                         WHERE di1.institution_id = ? AND di2.institution_id = ?',
                        [$inst->getId(), $target->getId()]
                    );
                    $conn->executeStatement(
                        'UPDATE documento_instituicoes SET institution_id = ? WHERE institution_id = ?',
                        [$target->getId(), $inst->getId()]
                    );

                    // Move variations to target
                    foreach ($inst->getVariations() as $v) {
                        $exists = false;
                        foreach ($target->getVariations() as $tv) {
                            if ($tv->getNormalizedName() === $v->getNormalizedName()) {
                                $exists = true;
                                break;
                            }
                        }
                        if (!$exists) {
                            $v->setInstitution($target);
                            $target->addVariation($v);
                        } else {
                            $this->em->remove($v);
                        }
                    }

                    // Add source's old names as variations to target
                    $names = array_filter([$inst->getOfficialName(), $inst->getShortName(), $inst->getSigla()]);
                    foreach ($names as $name) {
                        $norm = DocumentEnrichmentService::normalize($name);
                        $exists = false;
                        foreach ($target->getVariations() as $tv) {
                            if ($tv->getNormalizedName() === $norm) {
                                $exists = true;
                                break;
                            }
                        }
                        if (!$exists) {
                            $v = new InstitutionVariation();
                            $v->setVariationName($name);
                            $v->setNormalizedName($norm);
                            $v->setVariationType('alternative');
                            $v->setInstitution($target);
                            $this->em->persist($v);
                        }
                    }

                    // Delete source
                    $this->em->remove($inst);
                    unset($instMap[$normCurrent]);
                    $mergedCount++;

                    // Set target as our active institution for fields backfill
                    $inst = $target;
                } else {
                    // Target doesn't exist, so we RENAME
                    
                    // Add old name as a variation
                    $exists = false;
                    foreach ($inst->getVariations() as $tv) {
                        if ($tv->getNormalizedName() === $normCurrent) {
                            $exists = true;
                            break;
                        }
                    }
                    if (!$exists) {
                        $v = new InstitutionVariation();
                        $v->setVariationName($inst->getOfficialName());
                        $v->setNormalizedName($normCurrent);
                        $v->setVariationType('alternative');
                        $v->setInstitution($inst);
                        $this->em->persist($v);
                    }

                    $inst->setOfficialName($suggestedName);
                    unset($instMap[$normCurrent]);
                    $instMap[$normSuggested] = $inst;
                    $updatedCount++;
                }
            }

            // Fill location/details on the active institution
            $countryName = trim($record['suggested_country_name'] ?? '') ?: trim($record['current_country_name'] ?? '');
            $stateSigla  = trim($record['suggested_state_sigla'] ?? '')  ?: trim($record['current_state_sigla'] ?? '');
            $cityName    = trim($record['suggested_city_name'] ?? '')   ?: trim($record['current_city_name'] ?? '');

            $country = null;
            if ($countryName !== '') {
                $country = $countryMap[DocumentEnrichmentService::normalize($countryName)] ?? null;
                if ($country) {
                    $inst->setCountry($country);
                }
            }

            $state = null;
            if ($country && $stateSigla !== '') {
                $state = $stateMap[$country->getId() . '_' . DocumentEnrichmentService::normalize($stateSigla)] ?? null;
                if ($state) {
                    $inst->setState($state);
                }
            }

            if ($country && $cityName !== '') {
                $stId = $state ? $state->getId() : 'null';
                $city = $cityMap[$country->getId() . '_' . $stId . '_' . DocumentEnrichmentService::normalize($cityName)] ?? null;
                if ($city) {
                    $inst->setCity($city);
                }
            }

            $shortName = trim($record['suggested_short_name'] ?? '') ?: trim($record['current_short_name'] ?? '');
            if ($shortName !== '') {
                $inst->setShortName($shortName);
            }

            $sigla = trim($record['suggested_sigla'] ?? '') ?: trim($record['current_sigla'] ?? '');
            if ($sigla !== '') {
                $inst->setSigla($sigla);
            }

            $instType = trim($record['current_institution_type'] ?? '');
            if ($instType !== '') {
                $inst->setInstitutionType($instType);
            }

            $natureza = trim($record['current_natureza'] ?? '');
            if ($natureza !== '') {
                $inst->setNatureza($natureza);
            }

            if ((++$i % $batchSize) === 0) {
                $this->em->flush();
            }
        }

        $this->em->flush();

        $io->success([
            "Correções aplicadas com sucesso!",
            "Instituições removidas: {$removedCount}",
            "Instituições mescladas: {$mergedCount}",
            "Instituições renomeadas/atualizadas: {$updatedCount}",
        ]);

        return Command::SUCCESS;
    }
}
