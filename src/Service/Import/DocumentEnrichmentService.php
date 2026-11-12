<?php

namespace App\Service\Import;

use App\Service\Authority\AuthorityMergeLogger;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class DocumentEnrichmentService
{
    private const BATCH_SIZE = 500;
    /** Documentos processados por vez: o projeto nunca é carregado inteiro na memória. */
    private const DOC_CHUNK = 2000;

    /** Siglas dos estados e territórios dos EUA (USPS). Só elas valem como estado quando a sigla vem sozinha. */
    private const US_STATE_CODES = [
        'AL' => 1, 'AK' => 1, 'AZ' => 1, 'AR' => 1, 'CA' => 1, 'CO' => 1, 'CT' => 1, 'DE' => 1, 'FL' => 1, 'GA' => 1,
        'HI' => 1, 'ID' => 1, 'IL' => 1, 'IN' => 1, 'IA' => 1, 'KS' => 1, 'KY' => 1, 'LA' => 1, 'ME' => 1, 'MD' => 1,
        'MA' => 1, 'MI' => 1, 'MN' => 1, 'MS' => 1, 'MO' => 1, 'MT' => 1, 'NE' => 1, 'NV' => 1, 'NH' => 1, 'NJ' => 1,
        'NM' => 1, 'NY' => 1, 'NC' => 1, 'ND' => 1, 'OH' => 1, 'OK' => 1, 'OR' => 1, 'PA' => 1, 'RI' => 1, 'SC' => 1,
        'SD' => 1, 'TN' => 1, 'TX' => 1, 'UT' => 1, 'VT' => 1, 'VA' => 1, 'WA' => 1, 'WV' => 1, 'WI' => 1, 'WY' => 1,
        'DC' => 1, 'PR' => 1, 'GU' => 1, 'VI' => 1, 'AS' => 1, 'MP' => 1,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private ?AuthorityMergeLogger $mergeLogger = null,
    ) {
        $this->mergeLogger ??= new AuthorityMergeLogger($em);
    }

    /**
     * Id do cabeçalho do diário para o par (id anterior -> id novo) de autor ou palavra-chave, criado na
     * primeira vez que o par aparece no processo (origem enrichment, sem usuário). Uma linha de link por
     * vínculo remapeado ou apagado.
     *
     * @param array<string,int> $cache
     */
    private function remapLogId(array &$cache, string $entityType, int $from, int $to, Connection $conn, int $projectId): int
    {
        $key = $entityType . ':' . $from . ':' . $to;
        if (!isset($cache[$key])) {
            $label = (string) $conn->fetchOne(
                $entityType === 'author' ? 'SELECT preferred_name FROM author_identity WHERE id = ?' : 'SELECT keyword_original FROM keyword WHERE id = ?',
                [$from]
            );
            $cache[$key] = $this->mergeLogger->begin('enrichment', 'remap', $entityType, $from, $to, $label, ['project_id' => $projectId], null, $projectId);
        }

        return $cache[$key];
    }

    /**
     * Recalculates and enriches all documents in a project with institutions, countries, states, and cities.
     *
     * Os vínculos são trocados em blocos de documentos, cada bloco numa transação: se o processo
     * morrer no meio, os blocos já feitos ficam completos e os demais continuam com os vínculos antigos.
     *
     * @param int  $projectId
     * @param bool $withReport false = pula as listas e contagens do relatório (a importação não as usa)
     * @return array Sync stats/report
     */
    public function enrichProject(int $projectId, bool $withReport = true): array
    {
        $conn = $this->em->getConnection();
        $this->logger->info("Starting geographical and institutional enrichment for Project #{$projectId}...");
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $startTime = microtime(true);

        // 1. Load lookups into memory
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

        // 2. Documentos em blocos (por id), para não carregar o projeto inteiro
        $totalDocs = 0;
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

        $lastDocId = 0;
        while (true) {
            $docs = $conn->fetchAllAssociative(
                'SELECT id, countries, institutions FROM document WHERE project_id = ? AND id > ? ORDER BY id LIMIT ' . self::DOC_CHUNK,
                [$projectId, $lastDocId]
            );
            if (!$docs) {
                break;
            }
            $totalDocs += count($docs);
            $chunkDocIds = [];

            // Vínculos do bloco
            $instLinks = [];
            $countryLinks = [];
            $stateLinks = [];
            $cityLinks = [];

            foreach ($docs as $doc) {
                $docId = (int) $doc['id'];
                $chunkDocIds[] = $docId;
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
                    } elseif ($withReport) {
                        $unresolvedInstitutions[$rawInst] = ($unresolvedInstitutions[$rawInst] ?? 0) + 1;
                    }
                }

                // Process countries
                foreach ($docCountries as $rawCountry) {
                    $rawCountry = trim($rawCountry);
                    if ($rawCountry === '') continue;

                    // Padrão de endereço dos EUA ("NY 10027 USA", "CA 95616", "TX"). A sigla sozinha só vale
                    // quando é mesmo um estado dos EUA: "UK", "BR" etc. seguem para o cadastro de países.
                    $usLoc = self::parseUsLocationToken($rawCountry);
                    if ($usLoc !== null && ($usLoc['known_state'] || $usLoc['explicit_usa'])) {
                        if ($usaCountryId !== null) {
                            $boundCountryIds[$usaCountryId] = true;
                            $stateKey = $usaCountryId . '_' . self::normalize($usLoc['state']);
                            if (!$usLoc['known_state']) {
                                // "XX 12345 USA" com sigla desconhecida: é dos EUA, mas não se cria estado
                            } elseif (isset($statesLookup[$stateKey])) {
                                $boundStateIds[$statesLookup[$stateKey]['id']] = true;
                            } else {
                                $conn->insert('estados', [
                                    'country_id' => $usaCountryId,
                                    'official_name' => $usLoc['state'],
                                    'sigla' => $usLoc['state'],
                                    'status' => 1,
                                    'created_at' => $now,
                                    'updated_at' => $now,
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
                        if (isset($statesLookup[$norm]) && !$this->isBogusUsState($norm, $statesLookup[$norm], $usaCountryId)) {
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
                        } elseif ($withReport) {
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
            }

            // Troca os vínculos do bloco de uma vez (apaga os antigos e grava os novos na mesma transação)
            $this->replaceLinks($chunkDocIds, [
                'documento_instituicoes' => $instLinks,
                'documento_paises'       => $countryLinks,
                'documento_estados'      => $stateLinks,
                'documento_cidades'      => $cityLinks,
            ], $conn);

            $lastDocId = end($chunkDocIds);
            unset($docs);
        }

        // ── 4 e 5. Re-map Authors e Keywords (com diário) ──
        $this->remapAuthorsAndKeywords($projectId, $conn);

        // Enriquecer Qualis CAPES para os documentos do projeto
        $this->enrichQualis($projectId, $conn);

        // Registrar periódicos faltantes localizando pelo ISSN no Crossref
        $this->registerMissingJournals($projectId, $conn);

        // Registrar e vincular periódicos com as bases acadêmicas
        $this->registerAndLinkAcademicDatabases($projectId, $conn);

        // ── 6. Automatically apply Thesaurus Concepts to Keywords ──
        $conn->executeStatement("
            UPDATE keyword k
            JOIN thesaurus_label tl ON k.keyword_normalized = tl.normalized_label
            JOIN thesaurus_concept tc ON tc.id = tl.concept_id
            -- só o esquema de palavras-chave: um conceito de lugar (ex. Brazil) não deve capturar termos
            JOIN thesaurus_scheme ts ON ts.id = tc.scheme_id AND ts.slug = 'keyword'
            SET k.thesaurus_concept_id = tl.concept_id
            WHERE k.thesaurus_concept_id IS NULL
        ");

        if (!$withReport) {
            $executionTime = round(microtime(true) - $startTime, 2);
            $this->logger->info("Geographical and entity enrichment completed in {$executionTime}s. Processed {$processedDocs} documents.");

            return [
                'total_docs' => $totalDocs,
                'processed_docs' => $processedDocs,
                'matched_institutions_count' => $matchedInstitutionsCount,
                'matched_countries_count' => $matchedCountriesCount,
                'matched_organizations_count' => $matchedOrganizationsCount,
                'matched_units_count' => $matchedUnitsCount,
                'execution_time' => $executionTime,
            ];
        }

        // Sort unresolved
        arsort($unresolvedInstitutions);
        arsort($unresolvedCountries);

        // ── 7. Relatório: unresolved and resolved stats for Authors & Keywords ──
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

    /**
     * Passos 4 e 5 do enriquecimento: troca o id de autor/palavra-chave do vínculo pelo cadastro que casa com o
     * texto original, apagando a linha que repetiria um vínculo do documento. Cada troca vai para o diário.
     */
    private function remapAuthorsAndKeywords(int $projectId, Connection $conn): void
    {
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

        unset($rawNames);

        $authorsLookup = $this->loadAuthorsLookup($conn, $normalizedNames);
        unset($normalizedNames);

        $remapLogIds = [];
        foreach ($this->iterateDocIdChunks($projectId, $conn) as $docIds) {
            // Dentro do documento, na ordem do id: é ela que decide qual linha repetida sai
            $docAuthors = $conn->fetchAllAssociative(
                'SELECT da.id, da.document_id, da.author_identity_id, da.original_name
                 FROM document_author da
                 WHERE da.document_id IN (' . implode(',', $docIds) . ')
                 ORDER BY da.document_id, da.id'
            );

            // Cada bloco numa transação: o diário e a troca de vínculos andam juntos
            $conn->beginTransaction();
            try {
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
                        // Diário antes de trocar ou apagar o vínculo
                        $this->mergeLogger->logLinks(
                            $this->remapLogId($remapLogIds, 'author', (int)$da['author_identity_id'], $targetAuthorId, $conn, $projectId),
                            'document_author',
                            (int)$da['author_identity_id'],
                            (int)$da['id']
                        );
                        if ($isDuplicate) {
                            $conn->executeStatement('DELETE FROM document_author WHERE id = ?', [$da['id']]);
                        } else {
                            $conn->executeStatement('UPDATE document_author SET author_identity_id = ? WHERE id = ?', [$targetAuthorId, $da['id']]);
                        }
                    }
                }
            }
            $conn->commit();
            } catch (\Throwable $e) {
                $conn->rollBack();
                throw $e;
            }
        }
        unset($authorsLookup, $docAuthors);

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

        unset($rawTerms);

        $keywordsLookup = $this->loadKeywordsLookup($conn, $normalizedTerms);
        unset($normalizedTerms);
        $remapLogIds = [];

        foreach ($this->iterateDocIdChunks($projectId, $conn) as $docIds) {
            $docKeywords = $conn->fetchAllAssociative(
                'SELECT dk.id, dk.document_id, dk.keyword_id, dk.original_term, k.keyword_type AS type
                 FROM document_keyword dk
                 JOIN keyword k ON dk.keyword_id = k.id
                 WHERE dk.document_id IN (' . implode(',', $docIds) . ')
                 ORDER BY dk.document_id, dk.id'
            );

            $conn->beginTransaction();
            try {
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
                        // Diário antes de trocar ou apagar o vínculo
                        $this->mergeLogger->logLinks(
                            $this->remapLogId($remapLogIds, 'keyword', (int)$dk['keyword_id'], $targetKeywordId, $conn, $projectId),
                            'document_keyword',
                            (int)$dk['keyword_id'],
                            (int)$dk['id']
                        );
                        if ($isDuplicate) {
                            $conn->executeStatement('DELETE FROM document_keyword WHERE id = ?', [$dk['id']]);
                        } else {
                            $conn->executeStatement('UPDATE document_keyword SET keyword_id = ? WHERE id = ?', [$targetKeywordId, $dk['id']]);
                        }
                    }
                }
            }
            $conn->commit();
            } catch (\Throwable $e) {
                $conn->rollBack();
                throw $e;
            }
        }
        unset($keywordsLookup, $docKeywords);
    }

    /**
     * Blocos de ids de documentos do projeto, em ordem crescente.
     *
     * @return \Generator<int, int[]>
     */
    private function iterateDocIdChunks(int $projectId, Connection $conn): \Generator
    {
        $lastId = 0;
        while (true) {
            $ids = $conn->fetchFirstColumn(
                'SELECT id FROM document WHERE project_id = ? AND id > ? ORDER BY id LIMIT ' . self::DOC_CHUNK,
                [$projectId, $lastId]
            );
            if (!$ids) {
                return;
            }
            $ids = array_map('intval', $ids);
            $lastId = end($ids);
            yield $ids;
        }
    }

    /**
     * Apaga os vínculos antigos dos documentos do bloco e grava os novos, tudo numa transação.
     *
     * @param int[]                              $docIds
     * @param array<string, array<int, array>>   $linksByTable  tabela => linhas
     */
    private function replaceLinks(array $docIds, array $linksByTable, Connection $conn): void
    {
        if (!$docIds) {
            return;
        }
        $inList = implode(',', array_map('intval', $docIds));

        $conn->beginTransaction();
        try {
            foreach ($linksByTable as $table => $rows) {
                $conn->executeStatement("DELETE FROM {$table} WHERE document_id IN ({$inList})");

                foreach (array_chunk($rows, self::BATCH_SIZE) as $chunk) {
                    $columns = array_keys($chunk[0]);
                    $placeholder = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
                    $params = [];
                    foreach ($chunk as $row) {
                        foreach ($columns as $col) {
                            $params[] = $row[$col];
                        }
                    }
                    $conn->executeStatement(
                        "INSERT INTO {$table} (" . implode(', ', $columns) . ') VALUES '
                        . implode(', ', array_fill(0, count($chunk), $placeholder)),
                        $params
                    );
                }
            }
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            $this->logger->error('Failed to replace document links: ' . $e->getMessage());
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

    /**
     * Reconhece o último trecho de um endereço dos EUA: "NY 10027 USA", "CA 95616", "TX USA", "MA".
     * known_state diz se a sigla é de um estado/território dos EUA; explicit_usa, se "USA" veio escrito.
     * Uma sigla de duas letras fora da lista e sem "USA" (ex.: "UK", "BR") não é endereço dos EUA.
     *
     * @return array{country: string, state: string, postal_code: ?string, known_state: bool, explicit_usa: bool}|null
     */
    public static function parseUsLocationToken(string $raw): ?array
    {
        $raw = trim($raw);
        if (!preg_match('/^(?<state>[A-Z]{2})(?:\s+(?<zip>\d{5}(?:-\d{4})?))?\s*(?<usa>USA)?$/i', $raw, $m)) {
            return null;
        }
        $state = strtoupper($m['state']);
        return [
            'country' => 'USA',
            'state' => $state,
            'postal_code' => ($m['zip'] ?? '') !== '' ? $m['zip'] : null,
            'known_state' => isset(self::US_STATE_CODES[$state]),
            'explicit_usa' => ($m['usa'] ?? '') !== '',
        ];
    }

    /**
     * Estado dos EUA com sigla de duas letras que não existe (criado no passado a partir de "UK", "BR"...):
     * não é usado para vincular documentos.
     */
    private function isBogusUsState(string $normalizedToken, array $stateData, ?int $usaCountryId): bool
    {
        return $usaCountryId !== null
            && (int) $stateData['country_id'] === $usaCountryId
            && preg_match('/^[a-z]{2}$/', $normalizedToken) === 1
            && !isset(self::US_STATE_CODES[strtoupper($normalizedToken)]);
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

    /** Chave de comparação de ISSN como o MySQL compara a coluna: sem diferenciar maiúsculas nem espaço no fim. */
    private static function issnKey(string $normalizedIssn): string
    {
        return rtrim(mb_strtolower($normalizedIssn, 'UTF-8'));
    }

    /**
     * Enriches project documents with CAPES Qualis classification based on journal ISSN.
     */
    private function enrichQualis(int $projectId, Connection $conn): void
    {
        $this->logger->info("Enriching Project #{$projectId} documents with CAPES Qualis classifications...");

        // Um UPDATE só deixa cada documento com a revista do seu ISSN (ou sem revista, se não houver),
        // e só toca nas linhas que mudam: não é preciso zerar o Qualis do projeto antes.
        $sql = '
            UPDATE document d
            LEFT JOIN qualis_journal q
                ON d.issn IS NOT NULL AND d.issn != ""
               AND LOWER(REPLACE(REPLACE(d.issn, "-", ""), " ", "")) = q.normalized_issn
            SET d.qualis = q.qualis, d.qualis_journal_id = q.id
            WHERE d.project_id = ?
              AND (NOT (CAST(d.qualis AS BINARY) <=> CAST(q.qualis AS BINARY)) OR NOT (d.qualis_journal_id <=> q.id))
        ';

        $affected = $conn->executeStatement($sql, [$projectId]);
        $this->logger->info("Qualis enrichment complete. Affected {d.qualis} documents: {$affected}.");
    }

    /**
     * Finds unresolved ISSNs in project documents, queries Crossref to register them.
     */
    private function registerMissingJournals(int $projectId, Connection $conn): void
    {
        $this->logger->info("Searching for missing journals to register by ISSN for Project #{$projectId}...");

        $rows = $conn->fetchAllAssociative('
            SELECT issn, source_title, COUNT(*) AS doc_count
            FROM document 
            WHERE project_id = ? AND qualis_journal_id IS NULL AND issn IS NOT NULL AND issn != "" AND issn != "-" AND issn != "0000-0000"
            GROUP BY issn, source_title
            ORDER BY doc_count DESC
            LIMIT 100
        ', [$projectId]);

        if (empty($rows)) {
            return;
        }

        $registeredCount = 0;
        foreach ($rows as $row) {
            $originalIssn = trim($row['issn']);
            $normalizedIssn = strtolower(str_replace([' ', '-'], '', $originalIssn));
            $title = trim($row['source_title'] ?? 'Sem título');

            if (strlen($normalizedIssn) < 7 || $title === '') {
                continue;
            }

            $existingId = $conn->fetchOne(
                'SELECT id FROM qualis_journal WHERE normalized_issn = ?',
                [$normalizedIssn]
            );
            if ($existingId) {
                continue;
            }

            try {
                $conn->insert('qualis_journal', [
                    'title' => substr($title, 0, 500),
                    'issn' => $originalIssn,
                    'normalized_issn' => $normalizedIssn,
                    'qualis' => null,
                ]);
                $registeredCount++;
            } catch (\Throwable) {
                // ignore constraint violations
            }
        }

        if ($registeredCount > 0) {
            $this->logger->info("Registered {$registeredCount} new journals.");
            $this->enrichQualis($projectId, $conn);
        }
    }

    /**
     * Maps and links journals to the academic databases where their documents were found.
     */
    private function registerAndLinkAcademicDatabases(int $projectId, Connection $conn): void
    {
        $this->logger->info("Mapping and linking journals to academic databases for Project #{$projectId}...");

        $rows = $conn->fetchAllAssociative('
            SELECT DISTINCT issn, source, source_title
            FROM document
            WHERE project_id = ? AND issn IS NOT NULL AND issn != "" AND issn != "-" AND issn != "0000-0000"
        ', [$projectId]);

        if (empty($rows)) {
            return;
        }

        $dbRows = $conn->fetchAllAssociative('SELECT id, acronym FROM academic_database');
        $dbCache = [];
        foreach ($dbRows as $db) {
            $dbCache[strtolower($db['acronym'])] = (int)$db['id'];
        }

        // Revistas e vínculos já existentes, lidos uma vez (antes: 2 a 4 consultas por ISSN do projeto)
        $journalIds = [];
        foreach ($conn->iterateNumeric('SELECT normalized_issn, id FROM qualis_journal') as [$issnKey, $jid]) {
            $journalIds[self::issnKey((string) $issnKey)] = (int) $jid;
        }
        $linked = [];
        foreach ($conn->iterateNumeric('SELECT qualis_journal_id, academic_database_id FROM qualis_journal_academic_database') as [$jid, $dbId]) {
            $linked[$jid . ':' . $dbId] = true;
        }

        foreach ($rows as $row) {
            $rawIssn = trim($row['issn']);
            $source = strtolower(trim($row['source']));
            $sourceTitle = trim($row['source_title'] ?? 'Sem título');

            if ($source === '' || $source === 'unknown') {
                continue;
            }

            $normalizedIssn = strtolower(str_replace([' ', '-'], '', $rawIssn));
            if (strlen($normalizedIssn) < 7) {
                continue;
            }

            $journalId = $journalIds[self::issnKey($normalizedIssn)] ?? null;

            if (!$journalId) {
                try {
                    $conn->insert('qualis_journal', [
                        'title' => substr($sourceTitle, 0, 500),
                        'issn' => $rawIssn,
                        'normalized_issn' => $normalizedIssn,
                        'qualis' => null,
                    ]);
                    $journalId = (int) $conn->lastInsertId();
                } catch (\Throwable) {
                    $journalId = $conn->fetchOne(
                        'SELECT id FROM qualis_journal WHERE normalized_issn = ?',
                        [$normalizedIssn]
                    );
                }
                if ($journalId) {
                    $journalIds[self::issnKey($normalizedIssn)] = (int) $journalId;
                }
            }

            if ($journalId && isset($dbCache[$source])) {
                $dbId = $dbCache[$source];
                if (!isset($linked[$journalId . ':' . $dbId])) {
                    try {
                        $conn->insert('qualis_journal_academic_database', [
                            'qualis_journal_id' => $journalId,
                            'academic_database_id' => $dbId,
                        ]);
                    } catch (\Throwable) {
                        // ignore duplicate key exceptions
                    }
                    $linked[$journalId . ':' . $dbId] = true;
                }
            }
        }

        // Re-run the linking query to link documents to any newly created journals
        $this->enrichQualis($projectId, $conn);
    }
}
