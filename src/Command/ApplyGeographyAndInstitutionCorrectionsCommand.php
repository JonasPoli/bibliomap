<?php

namespace App\Command;

use App\Entity\Country;
use App\Entity\CountryVariation;
use App\Entity\State;
use App\Entity\StateVariation;
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

#[AsCommand(
    name: 'app:geography:apply-corrections',
    description: 'Apply countries, states, institutions, and organizations audit corrections',
)]
class ApplyGeographyAndInstitutionCorrectionsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', '2048M');
        set_time_limit(3600);

        $io = new SymfonyStyle($input, $output);
        $basePath = '/Users/jonaspoli/work/html/bibliometric/docs/ajustes';

        $io->title('Applying Geography and Institution Corrections');

        // 1. Preload maps
        $io->text('Preloading database maps...');
        $conn = $this->em->getConnection();

        // Countries map
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

        // States map
        $states = $this->em->getRepository(State::class)->findAll();
        $stateMap = [];
        foreach ($states as $s) {
            $coId = $s->getCountry()->getId();
            $stateMap[$coId . '_' . DocumentEnrichmentService::normalize($s->getOfficialName())] = $s;
            if ($s->getSigla()) {
                $stateMap[$coId . '_' . DocumentEnrichmentService::normalize($s->getSigla())] = $s;
            }
        }

        // Institutions map
        $institutions = $this->em->getRepository(Institution::class)->findAll();
        $instMap = [];
        foreach ($institutions as $inst) {
            $instMap[DocumentEnrichmentService::normalize($inst->getOfficialName())] = $inst;
        }
        $instVars = $this->em->getRepository(InstitutionVariation::class)->findAll();
        foreach ($instVars as $iv) {
            $instMap[$iv->getNormalizedName()] = $iv->getInstitution();
        }

        // Target Country: United States
        $usa = $countryMap[DocumentEnrichmentService::normalize('United States')] 
            ?? $countryMap[DocumentEnrichmentService::normalize('Estados Unidos')]
            ?? null;

        if (!$usa) {
            $io->text('Creating United States country...');
            $usa = new Country();
            $usa->setOfficialName('United States of America');
            $usa->setCommonName('Estados Unidos');
            $usa->setSigla('US');
            $usa->setIsoCode('USA');
            $usa->setContinente('América do Norte');
            $usa->setNationality('Norte-americano');
            $usa->setStatus(true);
            $this->em->persist($usa);
            $this->em->flush();
            $countryMap[DocumentEnrichmentService::normalize('Estados Unidos')] = $usa;
            $countryMap[DocumentEnrichmentService::normalize('United States')] = $usa;
            $countryMap[DocumentEnrichmentService::normalize('US')] = $usa;
        }

        $batchSize = 200;
        $i = 0;

        // Paths definitions
        $path06 = $basePath . '/06_instituicoes_correcoes_alta_confianca.csv';
        $path07 = $basePath . '/07_organizacoes_para_cadastrar.csv';
        $path08 = $basePath . '/08_unidades_para_cadastrar.csv';

        // ─────────────────────────────────────────────────────────────────────
        // PREPROCESSING: Prune all to-be-removed institutions (Units and Orgs)
        // ─────────────────────────────────────────────────────────────────────
        $io->section('Preprocessing: Pruning sub-units and organizations from institutions table...');
        
        $toPrune = [];
        if (file_exists($path07)) {
            $reader = Reader::createFromPath($path07, 'r');
            $reader->setHeaderOffset(0);
            foreach ($reader->getRecords() as $record) {
                $orig = trim($record['nome_variacao_original'] ?? '');
                if ($orig !== '') $toPrune[] = $orig;
            }
        }
        if (file_exists($path08)) {
            $reader = Reader::createFromPath($path08, 'r');
            $reader->setHeaderOffset(0);
            foreach ($reader->getRecords() as $record) {
                $orig = trim($record['nome_variacao_original'] ?? '');
                if ($orig !== '') $toPrune[] = $orig;
            }
        }
        if (file_exists($path06)) {
            $reader = Reader::createFromPath($path06, 'r');
            $reader->setHeaderOffset(0);
            foreach ($reader->getRecords() as $record) {
                $orig = trim($record['nome_variacao_original'] ?? '');
                $action = trim($record['acao_recomendada'] ?? '');
                if ($orig !== '' && ($action === 'mover_para_organizacoes' || $action === 'mover_para_unidades')) {
                    $toPrune[] = $orig;
                }
            }
        }

        $toPrune = array_unique($toPrune);
        $prunedCount = 0;
        foreach ($toPrune as $origName) {
            $normOrig = DocumentEnrichmentService::normalize($origName);
            $inst = $instMap[$normOrig] ?? null;
            if ($inst) {
                $conn->executeStatement('DELETE FROM documento_instituicoes WHERE institution_id = ?', [$inst->getId()]);
                foreach ($inst->getVariations() as $v) {
                    $this->em->remove($v);
                }
                $this->em->remove($inst);
                $this->removeInstitutionFromMap($inst, $instMap);
                $prunedCount++;
            }
        }
        $this->em->flush();
        $io->success("Preprocessing completed. Pruned {$prunedCount} institutions from the main table.");

        // ─────────────────────────────────────────────────────────────────────
        // PHASE 1: USA Locations to Correct (04_localidades_eua_para_corrigir.csv)
        // ─────────────────────────────────────────────────────────────────────
        $path04 = $basePath . '/04_localidades_eua_para_corrigir.csv';
        if (file_exists($path04)) {
            $io->section('Phase 1: Correcting USA Locations...');
            $reader = Reader::createFromPath($path04, 'r');
            $reader->setHeaderOffset(0);

            $fixedCount = 0;
            foreach ($reader->getRecords() as $record) {
                $valOrig = trim($record['valor_original'] ?? '');
                if ($valOrig === '') continue;

                $normOrig = DocumentEnrichmentService::normalize($valOrig);
                
                // Check if this is currently registered as a Country
                $fakeCountry = $this->em->getRepository(Country::class)->findOneBy(['officialName' => $valOrig]);
                if (!$fakeCountry) {
                    $fakeCountry = $this->em->getRepository(Country::class)->findOneBy(['commonName' => $valOrig]);
                }

                $stateSigla = trim($record['state_sigla_sugerido'] ?? '');
                $stateName = trim($record['state_name_sugerido'] ?? '');

                // Find or Create State of USA
                $state = null;
                if ($stateSigla !== '') {
                    $stateKey = $usa->getId() . '_' . DocumentEnrichmentService::normalize($stateSigla);
                    if (isset($stateMap[$stateKey])) {
                        $state = $stateMap[$stateKey];
                    } else {
                        $state = new State();
                        $state->setCountry($usa);
                        $state->setOfficialName($stateName !== '' ? $stateName : $stateSigla);
                        $state->setSigla($stateSigla);
                        $state->setStatus(true);
                        $this->em->persist($state);
                        $this->em->flush(); // Flush state to get ID
                        $stateMap[$stateKey] = $state;
                    }
                }

                if ($fakeCountry) {
                    // Reassign document-country relationships
                    $conn->executeStatement(
                        'INSERT IGNORE INTO documento_paises (document_id, country_id)
                         SELECT document_id, ? FROM documento_paises WHERE country_id = ?',
                        [$usa->getId(), $fakeCountry->getId()]
                    );
                    $conn->executeStatement(
                        'DELETE FROM documento_paises WHERE country_id = ?',
                        [$fakeCountry->getId()]
                    );

                    // Reassign document-state relationships if state is resolved
                    if ($state) {
                        $conn->executeStatement(
                            'INSERT IGNORE INTO documento_estados (document_id, state_id)
                             SELECT de.document_id, ? FROM documento_estados de
                             JOIN documento_paises dp ON de.document_id = dp.document_id
                             WHERE dp.country_id = ?',
                            [$state->getId(), $usa->getId()]
                        );
                    }

                    // Remove variations of fake country
                    $conn->executeStatement('DELETE FROM paises_variacoes WHERE country_id = ?', [$fakeCountry->getId()]);
                    // Delete fake country
                    $this->em->remove($fakeCountry);
                    $fixedCount++;
                }

                // Delete any country variation matching this fake name
                $fakeVar = $this->em->getRepository(CountryVariation::class)->findOneBy(['normalizedName' => $normOrig]);
                if ($fakeVar) {
                    $this->em->remove($fakeVar);
                }

                if ((++$i % $batchSize) === 0) {
                    $this->em->flush();
                }
            }
            $this->em->flush();
            $io->success("Completed Phase 1. Corrected/Pruned {$fixedCount} fake USA country records.");
        }

        // ─────────────────────────────────────────────────────────────────────
        // PHASE 2: Add Real Countries (02_paises_para_adicionar.csv)
        // ─────────────────────────────────────────────────────────────────────
        $path02 = $basePath . '/02_paises_para_adicionar.csv';
        if (file_exists($path02)) {
            $io->section('Phase 2: Adding Real Countries...');
            $reader = Reader::createFromPath($path02, 'r');
            $reader->setHeaderOffset(0);

            $addedCountries = 0;
            foreach ($reader->getRecords() as $record) {
                $offName = trim($record['official_name'] ?? '');
                $comName = trim($record['common_name'] ?? '');
                if ($offName === '' || $comName === '') continue;

                $normOff = DocumentEnrichmentService::normalize($offName);
                $normCom = DocumentEnrichmentService::normalize($comName);

                if (isset($countryMap[$normOff]) || isset($countryMap[$normCom])) {
                    continue;
                }

                $country = new Country();
                $country->setOfficialName($offName);
                $country->setCommonName($comName);
                $country->setSigla(trim($record['sigla'] ?? '') ?: null);
                $country->setIsoCode(trim($record['iso_code'] ?? '') ?: null);
                $country->setContinente(trim($record['continente'] ?? '') ?: null);
                $country->setNationality(trim($record['nationality'] ?? '') ?: null);
                $country->setStatus(true);

                $this->em->persist($country);
                $countryMap[$normOff] = $country;
                $countryMap[$normCom] = $country;

                // Add variations
                $varsList = explode(';', $record['variations'] ?? '');
                foreach ($varsList as $v) {
                    $v = trim($v);
                    if ($v === '') continue;
                    $normV = DocumentEnrichmentService::normalize($v);
                    if (!isset($countryMap[$normV])) {
                        $cv = new CountryVariation();
                        $cv->setVariationName($v);
                        $cv->setNormalizedName($normV);
                        $cv->setVariationType('alternative');
                        $cv->setCountry($country);
                        $this->em->persist($cv);
                        $countryMap[$normV] = $country;
                    }
                }

                $addedCountries++;

                if ((++$i % $batchSize) === 0) {
                    $this->em->flush();
                }
            }
            $this->em->flush();
            $io->success("Completed Phase 2. Added {$addedCountries} missing countries.");
        }

        // ─────────────────────────────────────────────────────────────────────
        // HELPER FUNCTION: Apply Institution-focused Row Correction
        // ─────────────────────────────────────────────────────────────────────
        $applyRowCorrection = function(array $record, &$removed, &$merged, &$renamed) use ($conn, &$instMap) {
            $origName = trim($record['nome_variacao_original'] ?? '');
            if ($origName === '') return;

            $normOrig = DocumentEnrichmentService::normalize($origName);
            $action = trim($record['acao_recomendada'] ?? '');
            $targetName = trim($record['nome_canonico_sugerido'] ?? '');
            $typeSugerido = trim($record['tipo_sugerido'] ?? '');

            // Note: Deletions (mover_para_organizacoes / mover_para_unidades) are handled in the preprocessing step.
            if ($action === 'mover_para_organizacoes' || $action === 'mover_para_unidades') {
                return;
            }

            // 2. Renaming & Merging Actions
            if ($targetName !== '' && $targetName !== $origName) {
                $normTarget = DocumentEnrichmentService::normalize($targetName);
                $source = $instMap[$normOrig] ?? null;
                $target = $instMap[$normTarget] ?? null;

                if ($target) {
                    // Target exists -> MERGE
                    if ($source && $source->getId() !== $target->getId()) {
                        $conn->executeStatement(
                            'DELETE di1 FROM documento_instituicoes di1
                             JOIN documento_instituicoes di2 ON di1.document_id = di2.document_id
                             WHERE di1.institution_id = ? AND di2.institution_id = ?',
                            [$source->getId(), $target->getId()]
                        );
                        $conn->executeStatement(
                            'UPDATE documento_instituicoes SET institution_id = ? WHERE institution_id = ?',
                            [$target->getId(), $source->getId()]
                        );

                        foreach ($source->getVariations() as $v) {
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

                        $this->em->remove($source);
                        $this->removeInstitutionFromMap($source, $instMap);
                        $merged++;
                    }

                    // Add source name as variation of target
                    $exists = false;
                    foreach ($target->getVariations() as $tv) {
                        if ($tv->getNormalizedName() === $normOrig) {
                            $exists = true;
                            break;
                        }
                    }
                    if (!$exists) {
                        $v = new InstitutionVariation();
                        $v->setVariationName($origName);
                        $v->setNormalizedName($normOrig);
                        $v->setVariationType('alternative');
                        $v->setInstitution($target);
                        $this->em->persist($v);
                    }

                    if ($typeSugerido !== '') {
                        $target->setInstitutionType($typeSugerido);
                    }
                } else {
                    // Target doesn't exist -> RENAME source or CREATE new
                    if ($source) {
                        $exists = false;
                        foreach ($source->getVariations() as $tv) {
                            if ($tv->getNormalizedName() === $normOrig) {
                                $exists = true;
                                break;
                            }
                        }
                        if (!$exists) {
                            $v = new InstitutionVariation();
                            $v->setVariationName($source->getOfficialName());
                            $v->setNormalizedName($normOrig);
                            $v->setVariationType('alternative');
                            $v->setInstitution($source);
                            $this->em->persist($v);
                        }

                        $source->setOfficialName($targetName);
                        if ($typeSugerido !== '') {
                            $source->setInstitutionType($typeSugerido);
                        }
                        unset($instMap[$normOrig]);
                        $instMap[$normTarget] = $source;
                        $renamed++;
                    } else {
                        // Create canonical institution
                        $newInst = new Institution();
                        $newInst->setOfficialName($targetName);
                        $newInst->setInstitutionType($typeSugerido !== '' ? $typeSugerido : 'Universidade');
                        $newInst->setStatus(true);
                        $this->em->persist($newInst);
                        $this->em->flush(); // Flush immediately to generate ID

                        $v = new InstitutionVariation();
                        $v->setVariationName($origName);
                        $v->setNormalizedName($normOrig);
                        $v->setVariationType('alternative');
                        $v->setInstitution($newInst);
                        $this->em->persist($v);

                        $instMap[$normTarget] = $newInst;
                        $renamed++;
                    }
                }
            }
        };

        // ─────────────────────────────────────────────────────────────────────
        // PHASE 3: Apply High Confidence Corrections (06_instituicoes_correcoes_alta_confianca.csv)
        // ─────────────────────────────────────────────────────────────────────
        if (file_exists($path06)) {
            $io->section('Phase 3: Applying High Confidence Institution Corrections...');
            $reader = Reader::createFromPath($path06, 'r');
            $reader->setHeaderOffset(0);

            $removed = 0; $merged = 0; $renamed = 0;
            foreach ($reader->getRecords() as $record) {
                $applyRowCorrection($record, $removed, $merged, $renamed);
                if ((++$i % $batchSize) === 0) {
                    $this->em->flush();
                }
            }
            $this->em->flush();
            $io->success("Completed Phase 3. Removed: {$removed}, Merged: {$merged}, Renamed/Created: {$renamed}");
        }

        // ─────────────────────────────────────────────────────────────────────
        // PHASE 4: Feed Organizations (07_organizacoes_para_cadastrar.csv)
        // ─────────────────────────────────────────────────────────────────────
        if (file_exists($path07)) {
            $io->section('Phase 4: Seeding Organizations...');
            $reader = Reader::createFromPath($path07, 'r');
            $reader->setHeaderOffset(0);

            $orgsAdded = 0;
            foreach ($reader->getRecords() as $record) {
                $orig = trim($record['nome_variacao_original'] ?? '');
                $canon = trim($record['nome_canonico_sugerido'] ?? '');
                if ($orig === '' || $canon === '') continue;

                // Check if already exists in organizacoes
                $exists = $this->em->getRepository(Organization::class)->findOneBy(['originalVariationName' => $orig]);
                if (!$exists) {
                    $org = new Organization();
                    $org->setOriginalVariationName($orig);
                    $org->setCanonicalName($canon);
                    $org->setType(trim($record['tipo_sugerido'] ?? '') ?: null);
                    $org->setConfidence(trim($record['confianca'] ?? '') ?: null);
                    $org->setObservation(trim($record['observacao'] ?? '') ?: null);
                    $this->em->persist($org);
                    $orgsAdded++;
                }

                if ((++$i % $batchSize) === 0) {
                    $this->em->flush();
                }
            }
            $this->em->flush();
            $io->success("Completed Phase 4. Added {$orgsAdded} organization records.");
        }

        // ─────────────────────────────────────────────────────────────────────
        // PHASE 5: Feed Institution Units (08_unidades_para_cadastrar.csv)
        // ─────────────────────────────────────────────────────────────────────
        if (file_exists($path08)) {
            $io->section('Phase 5: Seeding Institution Units...');
            $reader = Reader::createFromPath($path08, 'r');
            $reader->setHeaderOffset(0);

            $unitsAdded = 0;
            foreach ($reader->getRecords() as $record) {
                $orig = trim($record['nome_variacao_original'] ?? '');
                $canon = trim($record['nome_canonico_sugerido'] ?? '');
                if ($orig === '' || $canon === '') continue;

                $exists = $this->em->getRepository(InstitutionUnit::class)->findOneBy(['originalVariationName' => $orig]);
                if (!$exists) {
                    $unit = new InstitutionUnit();
                    $unit->setOriginalVariationName($orig);
                    $unit->setCanonicalName($canon);
                    $unit->setType(trim($record['tipo_sugerido'] ?? '') ?: null);
                    $unit->setConfidence(trim($record['confianca'] ?? '') ?: null);
                    $unit->setObservation(trim($record['observacao'] ?? '') ?: null);

                    // Try to resolve parent institution from preloaded map
                    $parent = $this->findParentInstitution($canon, $instMap);
                    if ($parent) {
                        $unit->setParentInstitution($parent);
                    }

                    $this->em->persist($unit);
                    $unitsAdded++;
                }

                if ((++$i % $batchSize) === 0) {
                    $this->em->flush();
                }
            }
            $this->em->flush();
            $io->success("Completed Phase 5. Added {$unitsAdded} institution units.");
        }

        // ─────────────────────────────────────────────────────────────────────
        // PHASE 6: Add Extra Institution Variations (09_variacoes_instituicoes_para_adicionar.csv)
        // ─────────────────────────────────────────────────────────────────────
        $path09 = $basePath . '/09_variacoes_instituicoes_para_adicionar.csv';
        if (file_exists($path09)) {
            $io->section('Phase 6: Adding Additional Institution Variations...');
            $reader = Reader::createFromPath($path09, 'r');
            $reader->setHeaderOffset(0);

            $removed = 0; $merged = 0; $renamed = 0;
            foreach ($reader->getRecords() as $record) {
                $applyRowCorrection($record, $removed, $merged, $renamed);
                if ((++$i % $batchSize) === 0) {
                    $this->em->flush();
                }
            }
            $this->em->flush();
            $io->success("Completed Phase 6. Removed: {$removed}, Merged: {$merged}, Renamed/Created: {$renamed}");
        }

        $io->success('All geography, country, and institution audit corrections applied successfully!');
        return Command::SUCCESS;
    }

    private function findParentInstitution(string $name, array $instMap): ?Institution
    {
        $nameLower = strtolower($name);
        foreach ($instMap as $normName => $inst) {
            if ($normName === '') continue;
            $univName = strtolower($inst->getOfficialName());
            if (strlen($univName) > 6 && str_contains($nameLower, $univName)) {
                return $inst;
            }
        }
        return null;
    }

    private function removeInstitutionFromMap(Institution $inst, array &$instMap): void
    {
        foreach ($instMap as $key => $value) {
            if ($value === $inst || ($inst->getId() !== null && $value->getId() === $inst->getId())) {
                unset($instMap[$key]);
            }
        }
    }
}
