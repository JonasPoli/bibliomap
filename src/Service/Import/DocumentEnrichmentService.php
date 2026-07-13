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
        $organizationsLookup = $this->loadOrganizationsLookup($conn);
        $unitsLookup = $this->loadUnitsLookup($conn);

        // Build institutions lookup by ID for parent lookups
        $institutionsLookupById = [];
        $instsRows = $conn->fetchAllAssociative('SELECT id, country_id, state_id, city_id FROM instituicoes_ensino WHERE status = 1');
        foreach ($instsRows as $row) {
            $institutionsLookupById[(int)$row['id']] = [
                'country_id' => $row['country_id'] !== null ? (int)$row['country_id'] : null,
                'state_id' => $row['state_id'] !== null ? (int)$row['state_id'] : null,
                'city_id' => $row['city_id'] !== null ? (int)$row['city_id'] : null,
            ];
        }

        // Target USA country
        $usaCountryId = $conn->fetchOne("SELECT id FROM paises WHERE iso_code = 'USA' OR sigla = 'US' LIMIT 1");
        $usaCountryId = $usaCountryId !== false ? (int)$usaCountryId : null;

        // 3. Load all documents for the project
        $docs = $conn->fetchAllAssociative(
            'SELECT id, countries, institutions FROM document WHERE project_id = ?',
            [$projectId]
        );

        $totalDocs = count($docs);
        $processedDocs = 0;
        $matchedInstitutionsCount = 0;
        $matchedCountriesCount = 0;
        $matchedOrganizationsCount = 0;
        $matchedUnitsCount = 0;

        $unresolvedInstitutions = [];
        $unresolvedCountries = [];

        $resolvedInstitutions = [];
        $resolvedCountries = [];
        $resolvedOrganizations = [];
        $resolvedUnits = [];

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

                // Skip addresses & noises
                if (preg_match('/^\d+\s+[A-Za-z0-9 .\-]+\s+(Rd|Road|Dr|Drive|St|Street|Ave|Avenue|Blvd|Boulevard|Ln|Lane|Way)$/i', $rawInst) ||
                    preg_match('/^\d+\s+[A-Za-z0-9 .\-]+$/i', $rawInst) ||
                    in_array(strtolower($rawInst), ['30 xueyuan rd', '12127 old oaks dr', '1180 ctr dr', '5510 nathan shock dr', '118 parr st', '1280 montgomery blvd'])) {
                    continue;
                }

                $norm = self::normalize($rawInst);

                // 1. Check main institutions
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

                        $resolvedInstitutions[$instId] = ($resolvedInstitutions[$instId] ?? 0) + 1;
                    }

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

                // 2. Check Organizations
                } elseif (isset($organizationsLookup[$norm])) {
                    $orgData = $organizationsLookup[$norm];
                    $orgId = $orgData['id'];
                    $resolvedOrganizations[$orgId] = ($resolvedOrganizations[$orgId] ?? 0) + 1;
                    $matchedOrganizationsCount++;

                // 3. Check Institution Units
                } elseif (isset($unitsLookup[$norm])) {
                    $unitData = $unitsLookup[$norm];
                    $unitId = $unitData['id'];
                    $resolvedUnits[$unitId] = ($resolvedUnits[$unitId] ?? 0) + 1;
                    $matchedUnitsCount++;

                    // If unit has parent, link document to the parent institution!
                    if ($unitData['parent_institution_id'] !== null) {
                        $parentInstId = $unitData['parent_institution_id'];
                        if (!isset($boundInstIds[$parentInstId]) && isset($institutionsLookupById[$parentInstId])) {
                            $boundInstIds[$parentInstId] = true;
                            $instLinks[] = [
                                'document_id' => $docId,
                                'institution_id' => $parentInstId,
                                'link_type' => 'author_affiliation'
                            ];
                            $resolvedInstitutions[$parentInstId] = ($resolvedInstitutions[$parentInstId] ?? 0) + 1;

                            $parentData = $institutionsLookupById[$parentInstId];
                            if ($parentData['country_id'] !== null) {
                                $boundCountryIds[(int)$parentData['country_id']] = true;
                            }
                            if ($parentData['state_id'] !== null) {
                                $boundStateIds[(int)$parentData['state_id']] = true;
                            }
                            if ($parentData['city_id'] !== null) {
                                $boundCityIds[(int)$parentData['city_id']] = true;
                            }
                        }
                    }
                } else {
                    $unresolvedInstitutions[$rawInst] = ($unresolvedInstitutions[$rawInst] ?? 0) + 1;
                }
            }

            // Process countries
            foreach ($docCountries as $rawCountry) {
                $rawCountry = trim($rawCountry);
                if ($rawCountry === '') continue;

                // Check US location pattern
                $usLoc = $this->parseUsLocationToken($rawCountry);
                if ($usLoc !== null) {
                    if ($usaCountryId !== null) {
                        $boundCountryIds[$usaCountryId] = true;
                        $stateKey = $usaCountryId . '_' . self::normalize($usLoc['state']);
                        if (isset($statesLookup[$stateKey])) {
                            $boundStateIds[$statesLookup[$stateKey]['id']] = true;
                        } else {
                            $conn->insert('estados', [
                                'country_id' => $usaCountryId,
                                'official_name' => $usLoc['state'],
                                'sigla' => $usLoc['state'],
                                'status' => 1,
                            ]);
                            $newStateId = (int)$conn->lastInsertId();
                            $statesLookup[$stateKey] = ['id' => $newStateId, 'country_id' => $usaCountryId];
                            $boundStateIds[$newStateId] = true;
                        }
                    }
                    $matchedCountriesCount++;
                    continue;
                }

                $norm = self::normalize($rawCountry);
                if (isset($countriesLookup[$norm])) {
                    $cId = (int)$countriesLookup[$norm];
                    $boundCountryIds[$cId] = true;
                    $matchedCountriesCount++;

                    $resolvedCountries[$cId] = ($resolvedCountries[$cId] ?? 0) + 1;
                } else {
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

            // Flush batches
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

        // ── 4. Re-map Authors ──────────────────────────────────────────
        $rawNames = $conn->fetchFirstColumn(
            'SELECT DISTINCT da.original_name 
             FROM document_author da
             JOIN document d ON da.document_id = d.id
             WHERE d.project_id = ? AND da.original_name IS NOT NULL AND da.original_name != ""',
            [$projectId]
        );
        $normalizedNames = [];
        foreach ($rawNames as $name) {
            $normalizedNames[] = self::normalize($name);
        }
        $normalizedNames = array_unique(array_filter($normalizedNames));

        $authorsLookup = $this->loadAuthorsLookup($conn, $normalizedNames);
        $docAuthors = $conn->fetchAllAssociative(
            'SELECT da.id, da.document_id, da.author_identity_id, da.original_name 
             FROM document_author da
             JOIN document d ON da.document_id = d.id
             WHERE d.project_id = ?',
            [$projectId]
        );

        foreach ($docAuthors as $da) {
            if (!$da['original_name']) continue;
            $norm = self::normalize($da['original_name']);
            if (isset($authorsLookup[$norm])) {
                $targetAuthorId = $authorsLookup[$norm];
                if ($targetAuthorId !== (int)$da['author_identity_id']) {
                    // Check if duplicate exists on document
                    $isDuplicate = $conn->fetchOne(
                        'SELECT id FROM document_author WHERE document_id = ? AND author_identity_id = ? AND id != ?',
                        [$da['document_id'], $targetAuthorId, $da['id']]
                    );
                    if ($isDuplicate) {
                        $conn->executeStatement('DELETE FROM document_author WHERE id = ?', [$da['id']]);
                    } else {
                        $conn->executeStatement('UPDATE document_author SET author_identity_id = ? WHERE id = ?', [$targetAuthorId, $da['id']]);
                    }
                }
            }
        }

        // ── 5. Re-map Keywords ─────────────────────────────────────────
        $rawTerms = $conn->fetchFirstColumn(
            'SELECT DISTINCT dk.original_term 
             FROM document_keyword dk
             JOIN document d ON dk.document_id = d.id
             WHERE d.project_id = ? AND dk.original_term IS NOT NULL AND dk.original_term != ""',
            [$projectId]
        );
        $normalizedTerms = [];
        foreach ($rawTerms as $term) {
            $normalizedTerms[] = self::normalize($term);
        }
        $normalizedTerms = array_unique(array_filter($normalizedTerms));

        $keywordsLookup = $this->loadKeywordsLookup($conn, $normalizedTerms);
        $docKeywords = $conn->fetchAllAssociative(
            'SELECT dk.id, dk.document_id, dk.keyword_id, dk.original_term, k.keyword_type AS type
             FROM document_keyword dk
             JOIN document d ON dk.document_id = d.id
             JOIN keyword k ON dk.keyword_id = k.id
             WHERE d.project_id = ?',
            [$projectId]
        );

        foreach ($docKeywords as $dk) {
            if (!$dk['original_term']) continue;
            $norm = self::normalize($dk['original_term']);
            $key = $norm . '|' . $dk['type'];
            if (isset($keywordsLookup[$key])) {
                $targetKeywordId = $keywordsLookup[$key];
                if ($targetKeywordId !== (int)$dk['keyword_id']) {
                    // Check duplicate
                    $isDuplicate = $conn->fetchOne(
                        'SELECT id FROM document_keyword WHERE document_id = ? AND keyword_id = ? AND id != ?',
                        [$dk['document_id'], $targetKeywordId, $dk['id']]
                    );
                    if ($isDuplicate) {
                        $conn->executeStatement('DELETE FROM document_keyword WHERE id = ?', [$dk['id']]);
                    } else {
                        $conn->executeStatement('UPDATE document_keyword SET keyword_id = ? WHERE id = ?', [$targetKeywordId, $dk['id']]);
                    }
                }
            }
        }

        // Sort unresolved
        arsort($unresolvedInstitutions);
        arsort($unresolvedCountries);

        // ── 6. Compute unresolved and resolved stats for Authors & Keywords ──
        $unresolvedAuthorsCount = (int)$conn->fetchOne('
            SELECT COUNT(DISTINCT a.preferred_name)
            FROM document_author da
            JOIN author_identity a ON da.author_identity_id = a.id
            JOIN document d ON da.document_id = d.id
            WHERE d.project_id = ? AND a.status = 0
        ', [$projectId]);

        $unresolvedAuthors = [];
        $rawUnresolvedAuthors = $conn->fetchAllAssociative('
            SELECT a.preferred_name AS name, COUNT(da.document_id) AS count
            FROM document_author da
            JOIN author_identity a ON da.author_identity_id = a.id
            JOIN document d ON da.document_id = d.id
            WHERE d.project_id = ? AND a.status = 0
            GROUP BY a.preferred_name
            ORDER BY count DESC
            LIMIT 100
        ', [$projectId]);
        foreach ($rawUnresolvedAuthors as $row) {
            $unresolvedAuthors[$row['name']] = (int)$row['count'];
        }

        $unresolvedKeywordsCount = (int)$conn->fetchOne('
            SELECT COUNT(DISTINCT k.keyword_display)
            FROM document_keyword dk
            JOIN keyword k ON dk.keyword_id = k.id
            JOIN document d ON dk.document_id = d.id
            WHERE d.project_id = ? AND k.status = 0
        ', [$projectId]);

        $unresolvedKeywords = [];
        $rawUnresolvedKeywords = $conn->fetchAllAssociative('
            SELECT k.keyword_display AS term, COUNT(dk.document_id) AS count
            FROM document_keyword dk
            JOIN keyword k ON dk.keyword_id = k.id
            JOIN document d ON dk.document_id = d.id
            WHERE d.project_id = ? AND k.status = 0
            GROUP BY k.keyword_display
            ORDER BY count DESC
            LIMIT 100
        ', [$projectId]);
        foreach ($rawUnresolvedKeywords as $row) {
            $unresolvedKeywords[$row['term']] = (int)$row['count'];
        }

        $matchedAuthorsCount = (int)$conn->fetchOne('
            SELECT COUNT(da.document_id)
            FROM document_author da
            JOIN author_identity a ON da.author_identity_id = a.id
            JOIN document d ON da.document_id = d.id
            WHERE d.project_id = ? AND a.status = 1
        ', [$projectId]);

        $matchedAuthorsList = [];
        $rawMatchedAuthors = $conn->fetchAllAssociative('
            SELECT a.id, a.preferred_name AS name, COUNT(da.document_id) AS count
            FROM document_author da
            JOIN author_identity a ON da.author_identity_id = a.id
            JOIN document d ON da.document_id = d.id
            WHERE d.project_id = ? AND a.status = 1
            GROUP BY a.id, a.preferred_name
            ORDER BY count DESC
            LIMIT 100
        ', [$projectId]);
        foreach ($rawMatchedAuthors as $row) {
            $id = (int)$row['id'];
            $matchedAuthorsList[] = [
                'id' => $id,
                'name' => $row['name'],
                'count' => (int)$row['count']
            ];
        }

        $matchedKeywordsCount = (int)$conn->fetchOne('
            SELECT COUNT(dk.document_id)
            FROM document_keyword dk
            JOIN keyword k ON dk.keyword_id = k.id
            JOIN document d ON dk.document_id = d.id
            WHERE d.project_id = ? AND k.status = 1
        ', [$projectId]);

        $matchedKeywordsList = [];
        $rawMatchedKeywords = $conn->fetchAllAssociative('
            SELECT k.id, k.keyword_display AS term, k.keyword_type AS type, COUNT(dk.document_id) AS count
            FROM document_keyword dk
            JOIN keyword k ON dk.keyword_id = k.id
            JOIN document d ON dk.document_id = d.id
            WHERE d.project_id = ? AND k.status = 1
            GROUP BY k.id, k.keyword_display, k.keyword_type
            ORDER BY count DESC
            LIMIT 100
        ', [$projectId]);
        foreach ($rawMatchedKeywords as $row) {
            $id = (int)$row['id'];
            $matchedKeywordsList[] = [
                'id' => $id,
                'name' => $row['term'] . ' (' . ($row['type'] === 'author_keyword' ? 'Autor' : 'Indexada') . ')',
                'count' => (int)$row['count']
            ];
        }

        // Enriquecer Qualis CAPES para os documentos do projeto
        $this->enrichQualis($projectId, $conn);

        // Obter periódicos/revistas não resolvidos no sincronismo
        $unresolvedJournals = [];
        $rawUnresolvedJournals = $conn->fetchAllAssociative('
            SELECT source_title, issn, COUNT(id) AS count
            FROM document
            WHERE project_id = ? AND qualis_journal_id IS NULL AND source_title IS NOT NULL AND source_title != ""
            GROUP BY source_title, issn
            ORDER BY count DESC
            LIMIT 100
        ', [$projectId]);
        
        $unresolvedJournalsCount = 0;
        foreach ($rawUnresolvedJournals as $row) {
            $key = $row['source_title'] . ($row['issn'] ? " [{$row['issn']}]" : '');
            $unresolvedJournals[$key] = (int)$row['count'];
            $unresolvedJournalsCount += (int)$row['count'];
        }

        $executionTime = round(microtime(true) - $startTime, 2);
        $this->logger->info("Geographical and entity enrichment completed in {$executionTime}s. Processed {$processedDocs} documents.");

        // Form lists
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

        $matchedOrganizationsList = [];
        if (!empty($resolvedOrganizations)) {
            $orgIds = array_keys($resolvedOrganizations);
            $orgRows = $conn->fetchAllAssociative(
                'SELECT id, canonical_name, type FROM organizacoes WHERE id IN (' . implode(',', $orgIds) . ')'
            );
            foreach ($orgRows as $row) {
                $id = (int)$row['id'];
                $matchedOrganizationsList[] = [
                    'id' => $id,
                    'name' => $row['canonical_name'] . ($row['type'] ? " ({$row['type']})" : ''),
                    'count' => $resolvedOrganizations[$id]
                ];
            }
            usort($matchedOrganizationsList, fn($a, $b) => $b['count'] <=> $a['count']);
        }

        $matchedUnitsList = [];
        if (!empty($resolvedUnits)) {
            $unitIds = array_keys($resolvedUnits);
            $unitRows = $conn->fetchAllAssociative(
                'SELECT id, canonical_name, type FROM instituicao_unidades WHERE id IN (' . implode(',', $unitIds) . ')'
            );
            foreach ($unitRows as $row) {
                $id = (int)$row['id'];
                $matchedUnitsList[] = [
                    'id' => $id,
                    'name' => $row['canonical_name'] . ($row['type'] ? " ({$row['type']})" : ''),
                    'count' => $resolvedUnits[$id]
                ];
            }
            usort($matchedUnitsList, fn($a, $b) => $b['count'] <=> $a['count']);
        }

        return [
            'total_docs' => $totalDocs,
            'processed_docs' => $processedDocs,
            'matched_institutions_count' => $matchedInstitutionsCount,
            'matched_countries_count' => $matchedCountriesCount,
            'matched_organizations_count' => $matchedOrganizationsCount,
            'matched_units_count' => $matchedUnitsCount,
            'matched_authors_count' => $matchedAuthorsCount,
            'matched_keywords_count' => $matchedKeywordsCount,
            'unresolved_authors_count' => $unresolvedAuthorsCount,
            'unresolved_keywords_count' => $unresolvedKeywordsCount,
            'unresolved_journals_count' => $unresolvedJournalsCount,
            'execution_time' => $executionTime,
            'unresolved_institutions' => $unresolvedInstitutions,
            'unresolved_countries' => $unresolvedCountries,
            'unresolved_authors' => $unresolvedAuthors,
            'unresolved_keywords' => $unresolvedKeywords,
            'unresolved_journals' => $unresolvedJournals,
            'matched_institutions' => $matchedInstitutionsList,
            'matched_countries' => $matchedCountriesList,
            'matched_organizations' => $matchedOrganizationsList,
            'matched_units' => $matchedUnitsList,
            'matched_authors' => $matchedAuthorsList,
            'matched_keywords' => $matchedKeywordsList,
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

        $conn->executeStatement(
            "UPDATE document SET qualis_journal_id = NULL, qualis = NULL WHERE project_id = ?",
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
        return StringNormalizer::normalizeString($name, true);
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
                $lookup[(int)$row['country_id'] . '_' . self::normalize($row['sigla'])] = $data;
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

    private function loadOrganizationsLookup(Connection $conn): array
    {
        $lookup = [];
        $orgs = $conn->fetchAllAssociative('SELECT id, canonical_name, original_variation_name, type FROM organizacoes');
        foreach ($orgs as $row) {
            $id = (int)$row['id'];
            $data = [
                'id' => $id,
                'canonical_name' => $row['canonical_name'],
                'type' => $row['type'],
            ];
            $lookup[self::normalize($row['canonical_name'])] = $data;
            $lookup[self::normalize($row['original_variation_name'])] = $data;
        }
        return $lookup;
    }

    private function loadUnitsLookup(Connection $conn): array
    {
        $lookup = [];
        $units = $conn->fetchAllAssociative('SELECT id, canonical_name, original_variation_name, type, parent_institution_id FROM instituicao_unidades');
        foreach ($units as $row) {
            $id = (int)$row['id'];
            $data = [
                'id' => $id,
                'canonical_name' => $row['canonical_name'],
                'type' => $row['type'],
                'parent_institution_id' => $row['parent_institution_id'] !== null ? (int)$row['parent_institution_id'] : null,
            ];
            $lookup[self::normalize($row['canonical_name'])] = $data;
            $lookup[self::normalize($row['original_variation_name'])] = $data;
        }
        return $lookup;
    }

    private function parseUsLocationToken(string $raw): ?array
    {
        $raw = trim($raw);
        if (!preg_match('/^(?<state>[A-Z]{2})(?:\s+(?<zip>\d{5}(?:-\d{4})?))?\s*(?:USA)?$/i', $raw, $m)) {
            return null;
        }
        return [
            'country' => 'USA',
            'state' => strtoupper($m['state']),
            'postal_code' => $m['zip'] ?? null,
        ];
    }

    private function loadAuthorsLookup(Connection $conn, array $normalizedNames): array
    {
        $lookup = [];
        if (empty($normalizedNames)) {
            return $lookup;
        }

        $chunks = array_chunk($normalizedNames, 500);
        foreach ($chunks as $chunk) {
            $quoted = array_map(fn($val) => $conn->quote($val), $chunk);
            $inList = implode(',', $quoted);

            // 1. Get author identities
            $authors = $conn->fetchAllAssociative("SELECT id, normalized_name FROM author_identity WHERE normalized_name IN ($inList)");
            foreach ($authors as $row) {
                $id = (int)$row['id'];
                $lookup[$row['normalized_name']] = $id;
            }

            // 2. Variations
            $vars = $conn->fetchAllAssociative("SELECT v.normalized_name, v.author_identity_id FROM author_name_variant v WHERE v.normalized_name IN ($inList)");
            foreach ($vars as $row) {
                $lookup[$row['normalized_name']] = (int)$row['author_identity_id'];
            }
        }

        return $lookup;
    }

    private function loadKeywordsLookup(Connection $conn, array $normalizedTerms): array
    {
        $lookup = [];
        if (empty($normalizedTerms)) {
            return $lookup;
        }

        $chunks = array_chunk($normalizedTerms, 500);
        foreach ($chunks as $chunk) {
            $quoted = array_map(fn($val) => $conn->quote($val), $chunk);
            $inList = implode(',', $quoted);

            // 1. Get official keywords (point to concept_id if exists)
            $keywords = $conn->fetchAllAssociative("
                SELECT id, keyword_normalized, keyword_type, keyword_concept_id 
                FROM keyword 
                WHERE status = 1 AND keyword_normalized IN ($inList)
            ");
            foreach ($keywords as $row) {
                $conceptId = $row['keyword_concept_id'] ? (int)$row['keyword_concept_id'] : (int)$row['id'];
                $key = $row['keyword_normalized'] . '|' . $row['keyword_type'];
                $lookup[$key] = $conceptId;
            }

            // 2. Variations
            $vars = $conn->fetchAllAssociative(
                "SELECT v.normalized_name, v.keyword_id, k.keyword_type, k.keyword_concept_id 
                 FROM palavra_chave_variacoes_nome v 
                 JOIN keyword k ON k.id = v.keyword_id
                 WHERE v.status = 1 AND k.status = 1 AND v.normalized_name IN ($inList)"
            );
            foreach ($vars as $row) {
                $conceptId = $row['keyword_concept_id'] ? (int)$row['keyword_concept_id'] : (int)$row['keyword_id'];
                $key = $row['normalized_name'] . '|' . $row['keyword_type'];
                $lookup[$key] = $conceptId;
            }
        }

        return $lookup;
    }

    /**
     * Enriches project documents with CAPES Qualis classification based on journal ISSN.
     */
    private function enrichQualis(int $projectId, Connection $conn): void
    {
        $this->logger->info("Enriching Project #{$projectId} documents with CAPES Qualis classifications...");

        // Bulk UPDATE to set the qualis field on documents based on their normalized ISSN matching qualis_journal
        $sql = '
            UPDATE document d
            INNER JOIN qualis_journal q 
                ON LOWER(REPLACE(REPLACE(d.issn, "-", ""), " ", "")) = q.normalized_issn
            SET d.qualis = q.qualis, d.qualis_journal_id = q.id
            WHERE d.project_id = ? AND d.issn IS NOT NULL AND d.issn != ""
        ';

        $affected = $conn->executeStatement($sql, [$projectId]);
        $this->logger->info("Qualis enrichment complete. Affected {d.qualis} documents: {$affected}.");
    }
}
