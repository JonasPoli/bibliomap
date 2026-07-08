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
    name: 'app:apply-corrections-v4',
    description: 'Apply institution corrections from docs/ajustes/pendencias_instituicoes04_detalhado.csv',
)]
class ApplyInstitutionsCorrectionsV4Command extends Command
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
        $csvPath = '/Users/jonaspoli/work/html/bibliometric/docs/ajustes/pendencias_instituicoes04_detalhado.csv';

        if (!file_exists($csvPath)) {
            $io->error("File not found: {$csvPath}");
            return Command::FAILURE;
        }

        $io->title("Applying Institution Corrections v4 from CSV");

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

        // 3. Read and Aggregate CSV
        $io->text("Parsing and aggregating CSV rows...");
        $reader = Reader::createFromPath($csvPath, 'r');
        $reader->setHeaderOffset(0);

        $recordsByCurrentName = [];
        foreach ($reader->getRecords() as $record) {
            $currentName = trim($record['official_name_atual'] ?? '');
            if ($currentName === '') continue;

            if (!isset($recordsByCurrentName[$currentName])) {
                $recordsByCurrentName[$currentName] = [
                    'current_name' => $currentName,
                    'suggested_name' => '',
                    'short_name' => '',
                    'sigla' => '',
                    'type' => '',
                    'natureza' => '',
                    'country' => '',
                    'state' => '',
                    'city' => '',
                    'current_country' => '',
                    'current_state' => '',
                    'current_city' => '',
                    'should_remove' => false,
                ];
            }

            $issueCat = trim($record['issue_category'] ?? '');
            if (in_array($issueCat, ['EMPRESA_OU_ORGANIZACAO_PRIVADA_NAO_IES', 'HOSPITAL_OU_UNIDADE_DE_SAUDE', 'ORGAO_PUBLICO_AGENCIA_OU_ORGANISMO'])) {
                $recordsByCurrentName[$currentName]['should_remove'] = true;
            }

            $suggestedName = trim($record['suggested_official_name'] ?? '');
            if ($suggestedName !== '') {
                $recordsByCurrentName[$currentName]['suggested_name'] = $suggestedName;
            }

            $shortName = trim($record['suggested_short_name'] ?? '');
            if ($shortName !== '') {
                $recordsByCurrentName[$currentName]['short_name'] = $shortName;
            }

            $sigla = trim($record['suggested_sigla'] ?? '');
            if ($sigla !== '') {
                $recordsByCurrentName[$currentName]['sigla'] = $sigla;
            }

            $type = trim($record['suggested_institution_type'] ?? '');
            if ($type !== '') {
                $recordsByCurrentName[$currentName]['type'] = $type;
            }

            $natureza = trim($record['suggested_natureza'] ?? '');
            if ($natureza !== '') {
                $recordsByCurrentName[$currentName]['natureza'] = $natureza;
            }

            $country = trim($record['suggested_country_name'] ?? '');
            if ($country !== '') {
                $recordsByCurrentName[$currentName]['country'] = $country;
            }

            $state = trim($record['suggested_state_sigla'] ?? '');
            if ($state !== '') {
                $recordsByCurrentName[$currentName]['state'] = $state;
            }

            $city = trim($record['suggested_city_name'] ?? '');
            if ($city !== '') {
                $recordsByCurrentName[$currentName]['city'] = $city;
            }

            $currentCountry = trim($record['country_name_atual'] ?? '');
            if ($currentCountry !== '' && $recordsByCurrentName[$currentName]['current_country'] === '') {
                $recordsByCurrentName[$currentName]['current_country'] = $currentCountry;
            }

            $currentState = trim($record['state_sigla_atual'] ?? '');
            if ($currentState !== '' && $recordsByCurrentName[$currentName]['current_state'] === '') {
                $recordsByCurrentName[$currentName]['current_state'] = $currentState;
            }

            $currentCity = trim($record['city_name_atual'] ?? '');
            if ($currentCity !== '' && $recordsByCurrentName[$currentName]['current_city'] === '') {
                $recordsByCurrentName[$currentName]['current_city'] = $currentCity;
            }
        }

        // 4. Apply Actions
        $io->text("Applying corrections...");
        $removedCount = 0;
        $mergedCount = 0;
        $updatedCount = 0;
        $batchSize = 200;
        $i = 0;

        $conn = $this->em->getConnection();

        foreach ($recordsByCurrentName as $currentName => $data) {
            $normCurrent = DocumentEnrichmentService::normalize($currentName);
            $inst = $instMap[$normCurrent] ?? null;

            if (!$inst) {
                continue;
            }

            if ($data['should_remove']) {
                // Delete links first
                $conn->executeStatement('DELETE FROM documento_instituicoes WHERE institution_id = ?', [$inst->getId()]);

                // Delete variations
                foreach ($inst->getVariations() as $v) {
                    $this->em->remove($v);
                }

                $this->em->remove($inst);
                unset($instMap[$normCurrent]);
                $removedCount++;

                if ((++$i % $batchSize) === 0) {
                    $this->em->flush();
                }
                continue;
            }

            // Rename or Merge
            $suggestedName = $data['suggested_name'];
            if ($suggestedName !== '' && $suggestedName !== $currentName) {
                $normSuggested = DocumentEnrichmentService::normalize($suggestedName);
                $target = $instMap[$normSuggested] ?? null;

                if ($target) {
                    // Merge duplicate into target
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

                    $this->em->remove($inst);
                    unset($instMap[$normCurrent]);
                    $mergedCount++;

                    $inst = $target;
                } else {
                    // Rename
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

            // Fill locations and other fields
            $countryName = $data['country'] !== '' ? $data['country'] : $data['current_country'];
            $stateSigla  = $data['state'] !== ''   ? $data['state']   : $data['current_state'];
            $cityName    = $data['city'] !== ''    ? $data['city']    : $data['current_city'];

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

            if ($data['short_name'] !== '') {
                $inst->setShortName($data['short_name']);
            }
            if ($data['sigla'] !== '') {
                $inst->setSigla($data['sigla']);
            }
            if ($data['type'] !== '') {
                $inst->setInstitutionType($data['type']);
            }
            if ($data['natureza'] !== '') {
                $inst->setNatureza($data['natureza']);
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
