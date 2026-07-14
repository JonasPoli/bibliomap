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
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(
    name: 'app:geography:apply-corrections-v6',
    description: 'Apply countries, states, institutions, and organizations audit corrections (Phase 3 / V6)',
)]
class ApplyGeographyAndInstitutionCorrectionsV6Command extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly KernelInterface $kernel
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', '2048M');
        set_time_limit(3600);

        $io = new SymfonyStyle($input, $output);
        $basePath = $this->kernel->getProjectDir() . '/docs/ajustes';

        $io->title('Applying Geography and Institution Corrections (Phase 3 / V6)');

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

        // Path definitions
        $path02 = $basePath . '/02_instituicoes_correcao_alta_confianca.csv';
        $path03 = $basePath . '/03_organizacoes_nao_devem_entrar_em_instituicoes.csv';
        $path04 = $basePath . '/04_unidades_hospitais_departamentos.csv';
        $path05 = $basePath . '/05_nomes_errados_corrigir_e_variations.csv';
        $path07 = $basePath . '/07_variations_para_importar.csv';
        $path09 = $basePath . '/09_paises_reais_para_cadastrar.csv';
        $path10 = $basePath . '/10_localidades_eua_nao_sao_paises.csv';

        // ─────────────────────────────────────────────────────────────────────
        // PREPROCESSING: Prune all to-be-removed institutions (Units and Orgs)
        // ─────────────────────────────────────────────────────────────────────
        $io->section('Preprocessing: Pruning sub-units and organizations from institutions table...');
        
        $toPrune = [];
        if (file_exists($path03)) {
            $reader = Reader::createFromPath($path03, 'r');
            $reader->setHeaderOffset(0);
            foreach ($reader->getRecords() as $record) {
                $orig = trim($record['nome_no_sistema'] ?? '');
                if ($orig !== '') $toPrune[] = $orig;
            }
        }
        if (file_exists($path04)) {
            $reader = Reader::createFromPath($path04, 'r');
            $reader->setHeaderOffset(0);
            foreach ($reader->getRecords() as $record) {
                $orig = trim($record['nome_no_sistema'] ?? '');
                if ($orig !== '') $toPrune[] = $orig;
            }
        }
        
        // Check files 02 and 05 for action = mover_para_organizacoes or mover_para_unidades
        $checkDeletions = function(string $path) use (&$toPrune) {
            if (file_exists($path)) {
                $reader = Reader::createFromPath($path, 'r');
                $reader->setHeaderOffset(0);
                foreach ($reader->getRecords() as $record) {
                    $orig = trim($record['nome_no_sistema'] ?? '');
                    $action = trim($record['acao_recomendada'] ?? '');
                    if ($orig !== '' && ($action === 'mover_para_organizacoes' || $action === 'mover_para_unidades')) {
                        $toPrune[] = $orig;
                    }
                }
            }
        };
        $checkDeletions($path02);
        $checkDeletions($path05);

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
        // PHASE 1: USA Locations to Correct (10_localidades_eua_nao_sao_paises.csv)
        // ─────────────────────────────────────────────────────────────────────
        if (file_exists($path10)) {
            $io->section('Phase 1: Correcting USA Locations...');
            $reader = Reader::createFromPath($path10, 'r');
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
                    $conn->executeStatement('DELETE FROM pais_variacoes_nome WHERE country_id = ?', [$fakeCountry->getId()]);
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
        // PHASE 2: Add Real Countries (09_paises_reais_para_cadastrar.csv)
        // ─────────────────────────────────────────────────────────────────────
        if (file_exists($path09)) {
            $io->section('Phase 2: Adding Real Countries...');
            $reader = Reader::createFromPath($path09, 'r');
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
            $origName = trim($record['nome_no_sistema'] ?? '');
            if ($origName === '') return;

            $normOrig = DocumentEnrichmentService::normalize($origName);
            $action = trim($record['acao_recomendada'] ?? '');
            $targetName = trim($record['nome_correto_para_cadastrar'] ?? '');
            $typeSugerido = trim($record['tipo_sugerido'] ?? '');

            // Note: Deletions are handled in the preprocessing step.
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

                // Add semicolon separated variations as well
                $resolvedTarget = $instMap[$normTarget] ?? null;
                if ($resolvedTarget) {
                    $altVars = explode(';', $record['nome_alternativo_variation'] ?? '');
                    foreach ($altVars as $altV) {
                        $altV = trim($altV);
                        if ($altV === '') continue;
                        $normAltV = DocumentEnrichmentService::normalize($altV);

                        $exists = false;
                        foreach ($resolvedTarget->getVariations() as $tv) {
                            if ($tv->getNormalizedName() === $normAltV) {
                                $exists = true;
                                break;
                            }
                        }
                        if (!$exists) {
                            $v = new InstitutionVariation();
                            $v->setVariationName($altV);
                            $v->setNormalizedName($normAltV);
                            $v->setVariationType('alternative');
                            $v->setInstitution($resolvedTarget);
                            $this->em->persist($v);
                        }
                    }
                }
            }
        };

        // ─────────────────────────────────────────────────────────────────────
        // PHASE 3: Apply Corrections (02_instituicoes_correcao_alta_confianca.csv and 05_nomes_errados_corrigir_e_variations.csv)
        // ─────────────────────────────────────────────────────────────────────
        $applyInstitutionFiles = function(string $path, string $phaseName) use ($applyRowCorrection, $batchSize, &$i) {
            if (file_exists($path)) {
                $this->em->flush();
                $reader = Reader::createFromPath($path, 'r');
                $reader->setHeaderOffset(0);

                $removed = 0; $merged = 0; $renamed = 0;
                foreach ($reader->getRecords() as $record) {
                    $applyRowCorrection($record, $removed, $merged, $renamed);
                    if ((++$i % $batchSize) === 0) {
                        $this->em->flush();
                    }
                }
                $this->em->flush();
                return [$removed, $merged, $renamed];
            }
            return [0, 0, 0];
        };

        $io->section('Phase 3 (Part A): Applying High Confidence Institution Corrections...');
        [$r1, $m1, $n1] = $applyInstitutionFiles($path02, 'High Confidence');
        $io->success("Completed High Confidence corrections. Merged: {$m1}, Renamed/Created: {$n1}");

        $io->section('Phase 3 (Part B): Applying Mid/Low Confidence Institution Corrections...');
        [$r2, $m2, $n2] = $applyInstitutionFiles($path05, 'Mid/Low Confidence');
        $io->success("Completed Mid/Low Confidence corrections. Merged: {$m2}, Renamed/Created: {$n2}");

        // ─────────────────────────────────────────────────────────────────────
        // PHASE 4: Feed Organizations (03_organizacoes_nao_devem_entrar_em_instituicoes.csv)
        // ─────────────────────────────────────────────────────────────────────
        if (file_exists($path03)) {
            $io->section('Phase 4: Seeding Organizations...');
            $reader = Reader::createFromPath($path03, 'r');
            $reader->setHeaderOffset(0);

            $orgsAdded = 0;
            foreach ($reader->getRecords() as $record) {
                $orig = trim($record['nome_no_sistema'] ?? '');
                $canon = trim($record['nome_correto_para_cadastrar'] ?? '');
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
        // PHASE 5: Feed Institution Units (04_unidades_hospitais_departamentos.csv)
        // ─────────────────────────────────────────────────────────────────────
        if (file_exists($path04)) {
            $io->section('Phase 5: Seeding Institution Units...');
            $reader = Reader::createFromPath($path04, 'r');
            $reader->setHeaderOffset(0);

            $unitsAdded = 0;
            foreach ($reader->getRecords() as $record) {
                $orig = trim($record['nome_no_sistema'] ?? '');
                $canon = trim($record['nome_correto_para_cadastrar'] ?? '');
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
        // PHASE 6: Import Variations (07_variations_para_importar.csv)
        // ─────────────────────────────────────────────────────────────────────
        if (file_exists($path07)) {
            $io->section('Phase 6: Importing Variations...');
            $reader = Reader::createFromPath($path07, 'r');
            $reader->setHeaderOffset(0);

            $varsAdded = 0;
            foreach ($reader->getRecords() as $record) {
                $canonName = trim($record['nome_canonico'] ?? '');
                $variationName = trim($record['variation'] ?? '');
                if ($canonName === '' || $variationName === '') continue;

                $normCanon = DocumentEnrichmentService::normalize($canonName);
                $normVar = DocumentEnrichmentService::normalize($variationName);

                $target = $instMap[$normCanon] ?? null;
                if ($target) {
                    $exists = false;
                    foreach ($target->getVariations() as $tv) {
                        if ($tv->getNormalizedName() === $normVar) {
                            $exists = true;
                            break;
                        }
                    }
                    if (!$exists) {
                        $v = new InstitutionVariation();
                        $v->setVariationName($variationName);
                        $v->setNormalizedName($normVar);
                        $v->setVariationType('alternative');
                        $v->setInstitution($target);
                        $this->em->persist($v);

                        $instMap[$normVar] = $target;
                        $varsAdded++;
                    }
                }

                if ((++$i % $batchSize) === 0) {
                    $this->em->flush();
                }
            }
            $this->em->flush();
            $io->success("Completed Phase 6. Added {$varsAdded} institution variations.");
        }

        $io->success('All geography, country, and institution audit corrections applied successfully (Phase 3 / V6)!');
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
