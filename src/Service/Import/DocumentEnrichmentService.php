<?php

namespace App\Service\Import;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class DocumentEnrichmentService
{
    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Recalculates and enriches all documents in a project with institutions, countries, states, and cities.
     *
     * @param int $projectId
     * @return array Sync stats/report
     */
    public function enrichProject(int $projectId): array
    {
        $conn = $this->em->getConnection();
        $this->logger->info("Starting geographical and institutional enrichment for Project #{$projectId}...");

        $startTime = microtime(true);

        // 1. Clear existing linkages for documents in this project
        $this->clearExistingLinks($projectId, $conn);

        // 2. Load lookups into memory
        $institutionsLookup = $this->loadInstitutionsLookup($conn);
        $countriesLookup = $this->loadCountriesLookup($conn);
        $statesLookup = $this->loadStatesLookup($conn);
        $citiesLookup = $this->loadCitiesLookup($conn);

        // 3. Load all documents for the project
        $docs = $conn->fetchAllAssociative(
            'SELECT id, countries, institutions FROM document WHERE project_id = ?',
            [$projectId]
        );

        $totalDocs = count($docs);
        $processedDocs = 0;
        $matchedInstitutionsCount = 0;
        $matchedCountriesCount = 0;

        $unresolvedInstitutions = [];
        $unresolvedCountries = [];

        $resolvedInstitutions = [];
        $resolvedCountries = [];

        // Batch link buffers
        $instLinks = [];
        $countryLinks = [];
        $stateLinks = [];
        $cityLinks = [];

        foreach ($docs as $doc) {
            $docId = (int) $doc['id'];
            $docInstitutions = $doc['institutions'] ? json_decode($doc['institutions'], true) : [];
            $docCountries = $doc['countries'] ? json_decode($doc['countries'], true) : [];

            $docInstitutions = is_array($docInstitutions) ? $docInstitutions : [];
            $docCountries = is_array($docCountries) ? $docCountries : [];

            $boundCountryIds = [];
            $boundStateIds = [];
            $boundCityIds = [];
            $boundInstIds = [];

            // Process institutions
            foreach ($docInstitutions as $rawInst) {
                $rawInst = trim($rawInst);
                if ($rawInst === '') continue;

                $norm = self::normalize($rawInst);
                if (isset($institutionsLookup[$norm])) {
                    $instData = $institutionsLookup[$norm];
                    $instId = $instData['id'];

                    if (!isset($boundInstIds[$instId])) {
                        $boundInstIds[$instId] = true;
                        $instLinks[] = [
                            'document_id' => $docId,
                            'institution_id' => $instId,
                            'link_type' => 'author_affiliation'
                        ];

                        // Keep track of resolved insts
                        $resolvedInstitutions[$instId] = ($resolvedInstitutions[$instId] ?? 0) + 1;
                    }

                    // Auto-bind geography from institution
                    if ($instData['country_id'] !== null) {
                        $cId = (int)$instData['country_id'];
                        $boundCountryIds[$cId] = true;
                    }
                    if ($instData['state_id'] !== null) {
                        $sId = (int)$instData['state_id'];
                        $boundStateIds[$sId] = true;
                    }
                    if ($instData['city_id'] !== null) {
                        $ctId = (int)$instData['city_id'];
                        $boundCityIds[$ctId] = true;
                    }

                    $matchedInstitutionsCount++;
                } else {
                    $unresolvedInstitutions[$rawInst] = ($unresolvedInstitutions[$rawInst] ?? 0) + 1;
                }
            }

            // Process countries
            foreach ($docCountries as $rawCountry) {
                $rawCountry = trim($rawCountry);
                if ($rawCountry === '') continue;

                $norm = self::normalize($rawCountry);
                if (isset($countriesLookup[$norm])) {
                    $cId = (int)$countriesLookup[$norm];
                    $boundCountryIds[$cId] = true;
                    $matchedCountriesCount++;
                    
                    // Keep track of resolved countries
                    $resolvedCountries[$cId] = ($resolvedCountries[$cId] ?? 0) + 1;
                } else {
                    // Try to see if it matches a state or city variation, which could yield country
                    if (isset($statesLookup[$norm])) {
                        $stateData = $statesLookup[$norm];
                        $boundStateIds[$stateData['id']] = true;
                        if ($stateData['country_id'] !== null) {
                            $boundCountryIds[(int)$stateData['country_id']] = true;
                        }
                    } elseif (isset($citiesLookup[$norm])) {
                        $cityData = $citiesLookup[$norm];
                        $boundCityIds[$cityData['id']] = true;
                        if ($cityData['country_id'] !== null) {
                            $boundCountryIds[(int)$cityData['country_id']] = true;
                        }
                        if ($cityData['state_id'] !== null) {
                            $boundStateIds[(int)$cityData['state_id']] = true;
                        }
                    } else {
                        $unresolvedCountries[$rawCountry] = ($unresolvedCountries[$rawCountry] ?? 0) + 1;
                    }
                }
            }

            // Flush bindings into lists
            foreach (array_keys($boundCountryIds) as $cId) {
                $countryLinks[] = ['document_id' => $docId, 'country_id' => $cId];
            }
            foreach (array_keys($boundStateIds) as $sId) {
                $stateLinks[] = ['document_id' => $docId, 'state_id' => $sId];
            }
            foreach (array_keys($boundCityIds) as $ctId) {
                $cityLinks[] = ['document_id' => $docId, 'city_id' => $ctId];
            }

            $processedDocs++;

            // Flush batches to avoid huge memory profiles
            if (count($instLinks) >= self::BATCH_SIZE) {
                $this->flushLinks('documento_instituicoes', $instLinks, $conn);
                $instLinks = [];
            }
            if (count($countryLinks) >= self::BATCH_SIZE) {
                $this->flushLinks('documento_paises', $countryLinks, $conn);
                $countryLinks = [];
            }
            if (count($stateLinks) >= self::BATCH_SIZE) {
                $this->flushLinks('documento_estados', $stateLinks, $conn);
                $stateLinks = [];
            }
            if (count($cityLinks) >= self::BATCH_SIZE) {
                $this->flushLinks('documento_cidades', $cityLinks, $conn);
                $cityLinks = [];
            }
        }

        // Final partial flushes
        if ($instLinks) $this->flushLinks('documento_instituicoes', $instLinks, $conn);
        if ($countryLinks) $this->flushLinks('documento_paises', $countryLinks, $conn);
        if ($stateLinks) $this->flushLinks('documento_estados', $stateLinks, $conn);
        if ($cityLinks) $this->flushLinks('documento_cidades', $cityLinks, $conn);

        // Sort unresolved items by occurrence count DESC
        arsort($unresolvedInstitutions);
        arsort($unresolvedCountries);

        $executionTime = round(microtime(true) - $startTime, 2);
        $this->logger->info("Geographical enrichment completed in {$executionTime}s. Processed {$processedDocs} documents.");

        // Get matched objects names
        $matchedInstitutionsList = [];
        if (!empty($resolvedInstitutions)) {
            $instIds = array_keys($resolvedInstitutions);
            $instRows = $conn->fetchAllAssociative(
                'SELECT id, official_name, sigla FROM instituicoes_ensino WHERE id IN (' . implode(',', $instIds) . ')'
            );
            foreach ($instRows as $row) {
                $id = (int)$row['id'];
                $matchedInstitutionsList[] = [
                    'id' => $id,
                    'name' => $row['official_name'] . ($row['sigla'] ? " ({$row['sigla']})" : ''),
                    'count' => $resolvedInstitutions[$id]
                ];
            }
            usort($matchedInstitutionsList, fn($a, $b) => $b['count'] <=> $a['count']);
        }

        $matchedCountriesList = [];
        if (!empty($resolvedCountries)) {
            $cIds = array_keys($resolvedCountries);
            $cRows = $conn->fetchAllAssociative(
                'SELECT id, common_name FROM paises WHERE id IN (' . implode(',', $cIds) . ')'
            );
            foreach ($cRows as $row) {
                $id = (int)$row['id'];
                $matchedCountriesList[] = [
                    'id' => $id,
                    'name' => $row['common_name'],
                    'count' => $resolvedCountries[$id]
                ];
            }
            usort($matchedCountriesList, fn($a, $b) => $b['count'] <=> $a['count']);
        }

        return [
            'total_docs' => $totalDocs,
            'processed_docs' => $processedDocs,
            'matched_institutions_count' => $matchedInstitutionsCount,
            'matched_countries_count' => $matchedCountriesCount,
            'execution_time' => $executionTime,
            'unresolved_institutions' => $unresolvedInstitutions,
            'unresolved_countries' => $unresolvedCountries,
            'matched_institutions' => $matchedInstitutionsList,
            'matched_countries' => $matchedCountriesList,
        ];
    }

    private function clearExistingLinks(int $projectId, Connection $conn): void
    {
        $docIdsSelect = 'SELECT id FROM document WHERE project_id = ?';
        
        $conn->executeStatement(
            "DELETE FROM documento_instituicoes WHERE document_id IN ($docIdsSelect)",
            [$projectId]
        );
        $conn->executeStatement(
            "DELETE FROM documento_paises WHERE document_id IN ($docIdsSelect)",
            [$projectId]
        );
        $conn->executeStatement(
            "DELETE FROM documento_estados WHERE document_id IN ($docIdsSelect)",
            [$projectId]
        );
        $conn->executeStatement(
            "DELETE FROM documento_cidades WHERE document_id IN ($docIdsSelect)",
            [$projectId]
        );
    }

    private function flushLinks(string $table, array $rows, Connection $conn): void
    {
        if (empty($rows)) return;
        
        $conn->beginTransaction();
        try {
            foreach ($rows as $row) {
                $conn->insert($table, $row);
            }
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            $this->logger->error("Failed to insert links batch into {$table}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Normalizes names for robust matching (lowercase, no accents, strip extras).
     */
    public static function normalize(string $name): string
    {
        $text = mb_strtolower(trim($name), 'UTF-8');
        if (function_exists('transliterator_transliterate')) {
            $trans = \Transliterator::create('Any-Latin; Latin-ASCII');
            if ($trans) {
                $text = $trans->transliterate($text);
            }
        } else {
            $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
        }
        $text = preg_replace('/[^a-z0-9\s.\-]/u', '', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    private function loadInstitutionsLookup(Connection $conn): array
    {
        $lookup = [];

        // 1. Get official institution data
        $insts = $conn->fetchAllAssociative(
            'SELECT id, official_name, short_name, sigla, country_id, state_id, city_id FROM instituicoes_ensino WHERE status = 1'
        );

        foreach ($insts as $row) {
            $id = (int) $row['id'];
            $data = [
                'id' => $id,
                'country_id' => $row['country_id'] !== null ? (int)$row['country_id'] : null,
                'state_id' => $row['state_id'] !== null ? (int)$row['state_id'] : null,
                'city_id' => $row['city_id'] !== null ? (int)$row['city_id'] : null,
            ];

            // Add official name, short name, sigla to lookup directly
            $lookup[self::normalize($row['official_name'])] = $data;
            if ($row['short_name'] !== null && trim($row['short_name']) !== '') {
                $lookup[self::normalize($row['short_name'])] = $data;
            }
            if ($row['sigla'] !== null && trim($row['sigla']) !== '') {
                $lookup[self::normalize($row['sigla'])] = $data;
            }
        }

        // 2. Load variations
        $vars = $conn->fetchAllAssociative(
            'SELECT v.normalized_name, v.institution_id, i.country_id, i.state_id, i.city_id 
             FROM instituicao_variacoes_nome v
             JOIN instituicoes_ensino i ON i.id = v.institution_id
             WHERE v.status = 1 AND i.status = 1'
        );

        foreach ($vars as $row) {
            $lookup[$row['normalized_name']] = [
                'id' => (int) $row['institution_id'],
                'country_id' => $row['country_id'] !== null ? (int)$row['country_id'] : null,
                'state_id' => $row['state_id'] !== null ? (int)$row['state_id'] : null,
                'city_id' => $row['city_id'] !== null ? (int)$row['city_id'] : null,
            ];
        }

        return $lookup;
    }

    private function loadCountriesLookup(Connection $conn): array
    {
        $lookup = [];

        // 1. Get official countries
        $countries = $conn->fetchAllAssociative('SELECT id, official_name, common_name, sigla FROM paises WHERE status = 1');
        foreach ($countries as $row) {
            $id = (int) $row['id'];
            $lookup[self::normalize($row['official_name'])] = $id;
            $lookup[self::normalize($row['common_name'])] = $id;
            if ($row['sigla'] !== null && trim($row['sigla']) !== '') {
                $lookup[self::normalize($row['sigla'])] = $id;
            }
        }

        // 2. Variations
        $vars = $conn->fetchAllAssociative(
            'SELECT v.normalized_name, v.country_id FROM pais_variacoes_nome v WHERE v.status = 1'
        );
        foreach ($vars as $row) {
            $lookup[$row['normalized_name']] = (int)$row['country_id'];
        }

        return $lookup;
    }

    private function loadStatesLookup(Connection $conn): array
    {
        $lookup = [];

        // 1. Get official states
        $states = $conn->fetchAllAssociative('SELECT id, official_name, sigla, country_id FROM estados WHERE status = 1');
        foreach ($states as $row) {
            $id = (int) $row['id'];
            $data = [
                'id' => $id,
                'country_id' => (int)$row['country_id']
            ];
            $lookup[self::normalize($row['official_name'])] = $data;
            if ($row['sigla'] !== null && trim($row['sigla']) !== '') {
                $lookup[self::normalize($row['sigla'])] = $data;
            }
        }

        // 2. Variations
        $vars = $conn->fetchAllAssociative(
            'SELECT v.normalized_name, v.state_id, s.country_id 
             FROM estado_variacoes_nome v 
             JOIN estados s ON s.id = v.state_id
             WHERE v.status = 1'
        );
        foreach ($vars as $row) {
            $lookup[$row['normalized_name']] = [
                'id' => (int)$row['state_id'],
                'country_id' => (int)$row['country_id']
            ];
        }

        return $lookup;
    }

    private function loadCitiesLookup(Connection $conn): array
    {
        $lookup = [];

        // 1. Get official cities
        $cities = $conn->fetchAllAssociative('SELECT id, official_name, state_id, country_id FROM cidades WHERE status = 1');
        foreach ($cities as $row) {
            $id = (int) $row['id'];
            $data = [
                'id' => $id,
                'state_id' => $row['state_id'] !== null ? (int)$row['state_id'] : null,
                'country_id' => (int)$row['country_id']
            ];
            $lookup[self::normalize($row['official_name'])] = $data;
        }

        // 2. Variations
        $vars = $conn->fetchAllAssociative(
            'SELECT v.normalized_name, v.city_id, c.state_id, c.country_id 
             FROM cidade_variacoes_nome v 
             JOIN cidades c ON c.id = v.city_id
             WHERE v.status = 1'
        );
        foreach ($vars as $row) {
            $lookup[$row['normalized_name']] = [
                'id' => (int)$row['city_id'],
                'state_id' => $row['state_id'] !== null ? (int)$row['state_id'] : null,
                'country_id' => (int)$row['country_id']
            ];
        }

        return $lookup;
    }
}
