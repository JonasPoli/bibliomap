<?php

namespace App\Service\Thesaurus;

use App\Entity\AuthorExternalIdentifier;
use App\Entity\AuthorIdentity;
use App\Entity\AuthorNameVariant;
use App\Entity\City;
use App\Entity\CityVariation;
use App\Entity\Country;
use App\Entity\CountryVariation;
use App\Entity\Institution;
use App\Entity\InstitutionVariation;
use App\Entity\Keyword;
use App\Entity\KeywordVariation;
use App\Entity\QualisJournal;
use App\Entity\JournalVariation;
use App\Entity\State;
use App\Entity\StateVariation;
use App\Service\Import\StringNormalizer;
use Doctrine\ORM\EntityManagerInterface;

class EntityMergeService
{
    public function __construct(
        private readonly EntityManagerInterface $em
    ) {}

    /**
     * Merge multiple Institutions into one Master Institution
     */
    public function mergeInstitutions(int $masterId, array $sourceIds, array $selectedFields): Institution
    {
        $allIds = array_values(array_unique(array_merge([$masterId], $sourceIds)));
        $institutions = $this->em->getRepository(Institution::class)->findBy(['id' => $allIds]);
        
        $instMap = [];
        foreach ($institutions as $inst) {
            $instMap[$inst->getId()] = $inst;
        }

        if (!isset($instMap[$masterId])) {
            throw new \InvalidArgumentException("Instituição principal #{$masterId} não encontrada.");
        }

        $master = $instMap[$masterId];
        $sources = array_filter($instMap, fn($inst) => $inst->getId() !== $masterId);

        // Collect all variations from master and source institutions
        $allVariationStrings = [];
        foreach ($instMap as $inst) {
            if ($inst->getOfficialName()) $allVariationStrings[] = $inst->getOfficialName();
            if ($inst->getShortName()) $allVariationStrings[] = $inst->getShortName();
            if ($inst->getSigla()) $allVariationStrings[] = $inst->getSigla();
            if ($inst->getRazaoSocial()) $allVariationStrings[] = $inst->getRazaoSocial();

            foreach ($inst->getVariations() as $var) {
                if ($var->getVariationName()) $allVariationStrings[] = $var->getVariationName();
            }
        }

        // Apply selected fields to Master
        if (isset($selectedFields['officialName'])) $master->setOfficialName($selectedFields['officialName']);
        if (isset($selectedFields['shortName'])) $master->setShortName($selectedFields['shortName'] ?: null);
        if (isset($selectedFields['sigla'])) $master->setSigla($selectedFields['sigla'] ?: null);
        if (isset($selectedFields['razaoSocial'])) $master->setRazaoSocial($selectedFields['razaoSocial'] ?: null);
        if (isset($selectedFields['cnpj'])) $master->setCnpj($selectedFields['cnpj'] ?: null);
        if (isset($selectedFields['codigoIes'])) $master->setCodigoIes($selectedFields['codigoIes'] !== '' ? (int)$selectedFields['codigoIes'] : null);
        if (isset($selectedFields['codigoMantenedora'])) $master->setCodigoMantenedora($selectedFields['codigoMantenedora'] !== '' ? (int)$selectedFields['codigoMantenedora'] : null);
        if (isset($selectedFields['institutionType'])) $master->setInstitutionType($selectedFields['institutionType'] ?: null);
        if (isset($selectedFields['natureza'])) $master->setNatureza($selectedFields['natureza'] ?: null);
        if (isset($selectedFields['categoria'])) $master->setCategoria($selectedFields['categoria'] ?: null);
        if (isset($selectedFields['categoriaAdministrativa'])) $master->setCategoriaAdministrativa($selectedFields['categoriaAdministrativa'] ?: null);
        if (isset($selectedFields['organizacaoAcademica'])) $master->setOrganizacaoAcademica($selectedFields['organizacaoAcademica'] ?: null);
        if (isset($selectedFields['tipoCredenciamento'])) $master->setTipoCredenciamento($selectedFields['tipoCredenciamento'] ?: null);
        if (isset($selectedFields['situacaoIes'])) $master->setSituacaoIes($selectedFields['situacaoIes'] ?: null);
        if (isset($selectedFields['reitor'])) $master->setReitor($selectedFields['reitor'] ?: null);
        if (isset($selectedFields['representanteLegal'])) $master->setRepresentanteLegal($selectedFields['representanteLegal'] ?: null);
        if (isset($selectedFields['officialWebsite'])) $master->setOfficialWebsite($selectedFields['officialWebsite'] ?: null);
        if (isset($selectedFields['institutionalEmail'])) $master->setInstitutionalEmail($selectedFields['institutionalEmail'] ?: null);
        if (isset($selectedFields['telefone'])) $master->setTelefone($selectedFields['telefone'] ?: null);
        if (isset($selectedFields['enderecoSede'])) $master->setEnderecoSede($selectedFields['enderecoSede'] ?: null);
        if (isset($selectedFields['vantagepoint'])) $master->setVantagepoint($selectedFields['vantagepoint'] ?: null);
        if (isset($selectedFields['sinalizacoesVigentes'])) $master->setSinalizacoesVigentes($selectedFields['sinalizacoesVigentes'] ?: null);
        if (isset($selectedFields['notes'])) $master->setNotes($selectedFields['notes'] ?: null);

        if (isset($selectedFields['foundationYear'])) $master->setFoundationYear($selectedFields['foundationYear'] !== '' ? (int)$selectedFields['foundationYear'] : null);
        if (isset($selectedFields['extinctionYear'])) $master->setExtinctionYear($selectedFields['extinctionYear'] !== '' ? (int)$selectedFields['extinctionYear'] : null);

        if (isset($selectedFields['coordsInstId'])) {
            $cInst = $instMap[(int)$selectedFields['coordsInstId']] ?? null;
            if ($cInst) {
                $master->setLatitude($cInst->getLatitude());
                $master->setLongitude($cInst->getLongitude());
            }
        }
        if (isset($selectedFields['ciInstId'])) {
            $cInst = $instMap[(int)$selectedFields['ciInstId']] ?? null;
            if ($cInst) {
                $master->setCi($cInst->getCi());
                $master->setAnoCi($cInst->getAnoCi());
            }
        }
        if (isset($selectedFields['ciEadInstId'])) {
            $cInst = $instMap[(int)$selectedFields['ciEadInstId']] ?? null;
            if ($cInst) {
                $master->setCiEad($cInst->getCiEad());
                $master->setAnoCiEad($cInst->getAnoCiEad());
            }
        }
        if (isset($selectedFields['igcInstId'])) {
            $cInst = $instMap[(int)$selectedFields['igcInstId']] ?? null;
            if ($cInst) {
                $master->setIgc($cInst->getIgc());
                $master->setAnoIgc($cInst->getAnoIgc());
            }
        }
        
        if (isset($selectedFields['locationInstId'])) {
            $locInst = $instMap[(int)$selectedFields['locationInstId']] ?? null;
            if ($locInst) {
                $master->setCountry($locInst->getCountry());
                $master->setState($locInst->getState());
                $master->setCity($locInst->getCity());
            }
        }

        // Deduplicate and re-attach variations to Master
        $existingVariations = [];
        foreach ($master->getVariations() as $var) {
            $existingVariations[$var->getNormalizedName()] = true;
        }

        foreach ($allVariationStrings as $rawVar) {
            $rawVar = trim($rawVar);
            if ($rawVar === '') continue;
            
            $norm = StringNormalizer::normalizeString($rawVar, true);
            if ($norm === '' || isset($existingVariations[$norm])) continue;

            $varObj = new InstitutionVariation();
            $varObj->setInstitution($master);
            $varObj->setVariationName($rawVar);
            $varObj->setNormalizedName($norm);
            $varObj->setVariationType('alternative');
            $varObj->setStatus(true);

            $this->em->persist($varObj);
            $existingVariations[$norm] = true;
        }

        // Remove source institutions (vínculos remapeados antes, senão o ON DELETE CASCADE apaga)
        $this->removeSourcesAfterRemap($sources, fn(int $sourceId) => $this->remapInstitutionLinks($sourceId, $masterId));
        return $master;
    }

    /**
     * Merge multiple AuthorIdentities into Master AuthorIdentity
     */
    public function mergeAuthors(int $masterId, array $sourceIds, array $selectedFields): AuthorIdentity
    {
        $allIds = array_values(array_unique(array_merge([$masterId], $sourceIds)));
        $authors = $this->em->getRepository(AuthorIdentity::class)->findBy(['id' => $allIds]);
        
        $authorMap = [];
        foreach ($authors as $auth) {
            $authorMap[$auth->getId()] = $auth;
        }

        if (!isset($authorMap[$masterId])) {
            throw new \InvalidArgumentException("Autor principal #{$masterId} não encontrado.");
        }

        $master = $authorMap[$masterId];
        $sources = array_filter($authorMap, fn($auth) => $auth->getId() !== $masterId);

        $allVariationStrings = [];
        foreach ($authorMap as $auth) {
            if ($auth->getPreferredName()) $allVariationStrings[] = $auth->getPreferredName();
            foreach ($auth->getVariations() as $var) {
                if ($var->getVariationName()) $allVariationStrings[] = $var->getVariationName();
            }
        }

        if (isset($selectedFields['preferredName'])) {
            $master->setPreferredName($selectedFields['preferredName']);
            $master->setNormalizedName(StringNormalizer::normalizeString($selectedFields['preferredName'], true));
        }

        // Consolidate external identifiers from merged authors
        foreach ($sources as $source) {
            foreach ($source->getIdentifiers() as $ident) {
                $hasIdent = false;
                foreach ($master->getIdentifiers() as $mIdent) {
                    if ($mIdent->getProvider() === $ident->getProvider() && $mIdent->getIdentifier() === $ident->getIdentifier()) {
                        $hasIdent = true;
                        break;
                    }
                }
                if (!$hasIdent) {
                    $newIdent = new AuthorExternalIdentifier();
                    $newIdent->setAuthorIdentity($master);
                    $newIdent->setProvider($ident->getProvider());
                    $newIdent->setIdentifier($ident->getIdentifier());
                    $newIdent->setUrl($ident->getUrl());
                    $master->addIdentifier($newIdent);
                    $this->em->persist($newIdent);
                }
            }
        }

        $existingVariations = [];
        foreach ($master->getVariations() as $var) {
            $existingVariations[$var->getNormalizedName()] = true;
        }

        foreach ($allVariationStrings as $rawVar) {
            $rawVar = trim($rawVar);
            if ($rawVar === '') continue;
            
            $norm = StringNormalizer::normalizeString($rawVar, true);
            if ($norm === '' || isset($existingVariations[$norm])) continue;

            $varObj = new AuthorNameVariant();
            $varObj->setAuthorIdentity($master);
            $varObj->setOriginalName($rawVar);
            $varObj->setDisplayName($rawVar);
            $varObj->setNormalizedName($norm);
            $varObj->setSource('merge');

            $this->em->persist($varObj);
            $existingVariations[$norm] = true;
        }

        $this->removeSourcesAfterRemap($sources, fn(int $sourceId) => $this->remapAuthorLinks($sourceId, $masterId));
        return $master;
    }

    /**
     * Merge multiple QualisJournals into Master QualisJournal
     */
    public function mergeJournals(int $masterId, array $sourceIds, array $selectedFields): QualisJournal
    {
        $allIds = array_values(array_unique(array_merge([$masterId], $sourceIds)));
        $journals = $this->em->getRepository(QualisJournal::class)->findBy(['id' => $allIds]);
        
        $journalMap = [];
        foreach ($journals as $j) {
            $journalMap[$j->getId()] = $j;
        }

        if (!isset($journalMap[$masterId])) {
            throw new \InvalidArgumentException("Revista principal #{$masterId} não encontrada.");
        }

        $master = $journalMap[$masterId];
        $sources = array_filter($journalMap, fn($j) => $j->getId() !== $masterId);

        $allVariationStrings = [];
        foreach ($journalMap as $j) {
            if ($j->getTitle()) $allVariationStrings[] = $j->getTitle();
            foreach ($j->getVariations() as $var) {
                if ($var->getVariationName()) $allVariationStrings[] = $var->getVariationName();
            }
        }

        if (isset($selectedFields['title'])) $master->setTitle($selectedFields['title']);
        if (isset($selectedFields['qualis'])) $master->setQualis($selectedFields['qualis'] ?: null);

        // Bases acadêmicas dos secundários passam para o master
        foreach ($sources as $source) {
            foreach ($source->getAcademicDatabases() as $db) {
                if (!$master->getAcademicDatabases()->contains($db)) {
                    $master->addAcademicDatabase($db);
                }
            }
        }

        $existingVariations = [];
        foreach ($master->getVariations() as $var) {
            $existingVariations[$var->getNormalizedName()] = true;
        }

        foreach ($allVariationStrings as $rawVar) {
            $rawVar = trim($rawVar);
            if ($rawVar === '') continue;
            
            $norm = StringNormalizer::normalizeString($rawVar, true);
            if ($norm === '' || isset($existingVariations[$norm])) continue;

            $varObj = new JournalVariation();
            $varObj->setJournal($master);
            $varObj->setVariationName($rawVar);
            $varObj->setNormalizedName($norm);
            $varObj->setVariationType('alternative');
            $varObj->setStatus(true);

            $this->em->persist($varObj);
            $existingVariations[$norm] = true;
        }

        // O ISSN escolhido só é aplicado depois de excluir os secundários (normalized_issn é UNIQUE).
        // ISSN vazio não apaga o do master: é a chave de casamento com os documentos.
        $selectedIssn = trim((string) ($selectedFields['issn'] ?? ''));
        $this->removeSourcesAfterRemap(
            $sources,
            fn(int $sourceId) => $this->remapJournalLinks($sourceId, $masterId),
            function () use ($master, $selectedIssn) {
                if ($selectedIssn !== '') {
                    $master->setIssn($selectedIssn);
                    $master->setNormalizedIssn(self::normalizeIssn($selectedIssn));
                }
                $this->em->flush();
                // Documentos herdam o Qualis do master (como no enriquecimento por ISSN)
                $this->em->getConnection()->executeStatement(
                    'UPDATE document SET qualis = ? WHERE qualis_journal_id = ?',
                    [$master->getQualis(), $master->getId()]
                );
            }
        );
        return $master;
    }

    /**
     * Merge multiple Keywords into Master Keyword
     */
    public function mergeKeywords(int $masterId, array $sourceIds, array $selectedFields): Keyword
    {
        $allIds = array_values(array_unique(array_merge([$masterId], $sourceIds)));
        $keywords = $this->em->getRepository(Keyword::class)->findBy(['id' => $allIds]);
        
        $kwMap = [];
        foreach ($keywords as $kw) {
            $kwMap[$kw->getId()] = $kw;
        }

        if (!isset($kwMap[$masterId])) {
            throw new \InvalidArgumentException("Palavra-chave principal #{$masterId} não encontrada.");
        }

        $master = $kwMap[$masterId];
        $sources = array_filter($kwMap, fn($kw) => $kw->getId() !== $masterId);

        $allVariationStrings = [];
        foreach ($kwMap as $kw) {
            if ($kw->getKeywordOriginal()) $allVariationStrings[] = $kw->getKeywordOriginal();
            if ($kw->getKeywordDisplay()) $allVariationStrings[] = $kw->getKeywordDisplay();
            foreach ($kw->getVariations() as $var) {
                if ($var->getVariationName()) $allVariationStrings[] = $var->getVariationName();
            }
        }

        if (isset($selectedFields['keywordOriginal'])) {
            $master->setKeywordOriginal($selectedFields['keywordOriginal']);
            $master->setKeywordNormalized(StringNormalizer::normalizeString($selectedFields['keywordOriginal'], true));
        }
        if (isset($selectedFields['keywordDisplay'])) $master->setKeywordDisplay($selectedFields['keywordDisplay'] ?: null);
        if (isset($selectedFields['keywordType'])) $master->setKeywordType($selectedFields['keywordType'] ?: Keyword::TYPE_AUTHOR);

        $existingVariations = [];
        foreach ($master->getVariations() as $var) {
            $existingVariations[$var->getNormalizedName()] = true;
        }

        foreach ($allVariationStrings as $rawVar) {
            $rawVar = trim($rawVar);
            if ($rawVar === '') continue;
            
            $norm = StringNormalizer::normalizeString($rawVar, true);
            if ($norm === '' || isset($existingVariations[$norm])) continue;

            $varObj = new KeywordVariation();
            $varObj->setKeyword($master);
            $varObj->setVariationName($rawVar);
            $varObj->setNormalizedName($norm);
            $varObj->setVariationType('alternative');
            $varObj->setStatus(true);

            $this->em->persist($varObj);
            $existingVariations[$norm] = true;
        }

        // Se o master apontava para um secundário como conceito, passa a ser o próprio conceito
        if ($master->getKeywordConcept() !== null && isset($sources[$master->getKeywordConcept()->getId()])) {
            $master->setKeywordConcept($master);
        }

        $this->removeSourcesAfterRemap($sources, fn(int $sourceId) => $this->remapKeywordLinks($sourceId, $masterId));
        return $master;
    }

    /**
     * Merge Countries
     */
    public function mergeCountries(int $masterId, array $sourceIds, array $selectedFields): Country
    {
        $allIds = array_values(array_unique(array_merge([$masterId], $sourceIds)));
        $countries = $this->em->getRepository(Country::class)->findBy(['id' => $allIds]);
        
        $countryMap = [];
        foreach ($countries as $c) {
            $countryMap[$c->getId()] = $c;
        }

        if (!isset($countryMap[$masterId])) {
            throw new \InvalidArgumentException("País principal #{$masterId} não encontrado.");
        }

        $master = $countryMap[$masterId];
        $sources = array_filter($countryMap, fn($c) => $c->getId() !== $masterId);

        $allVariationStrings = [];
        foreach ($countryMap as $c) {
            if ($c->getCommonName()) $allVariationStrings[] = $c->getCommonName();
            if ($c->getOfficialName()) $allVariationStrings[] = $c->getOfficialName();
            foreach ($c->getVariations() as $var) {
                if ($var->getVariationName()) $allVariationStrings[] = $var->getVariationName();
            }
        }

        if (isset($selectedFields['commonName'])) $master->setCommonName($selectedFields['commonName']);
        if (isset($selectedFields['officialName'])) $master->setOfficialName($selectedFields['officialName'] ?: null);
        if (isset($selectedFields['foundationYear'])) $master->setFoundationYear($selectedFields['foundationYear'] !== '' ? (int)$selectedFields['foundationYear'] : null);
        if (isset($selectedFields['extinctionYear'])) $master->setExtinctionYear($selectedFields['extinctionYear'] !== '' ? (int)$selectedFields['extinctionYear'] : null);

        $existingVariations = [];
        foreach ($master->getVariations() as $var) {
            $existingVariations[$var->getNormalizedName()] = true;
        }

        foreach ($allVariationStrings as $rawVar) {
            $rawVar = trim($rawVar);
            if ($rawVar === '') continue;
            
            $norm = StringNormalizer::normalizeString($rawVar, true);
            if ($norm === '' || isset($existingVariations[$norm])) continue;

            $varObj = new CountryVariation();
            $varObj->setCountry($master);
            $varObj->setVariationName($rawVar);
            $varObj->setNormalizedName($norm);
            $varObj->setVariationType('alternative');
            $varObj->setStatus(true);

            $this->em->persist($varObj);
            $existingVariations[$norm] = true;
        }

        $this->removeSourcesAfterRemap($sources, fn(int $sourceId) => $this->remapCountryLinks($sourceId, $masterId));
        return $master;
    }

    public static function normalizeIssn(string $issn): string
    {
        return strtolower(str_replace(['-', ' '], '', trim($issn)));
    }

    /**
     * Remapeia os vínculos e só então exclui os secundários, tudo numa transação.
     * Sem o remapeamento, os FKs ON DELETE CASCADE/SET NULL apagariam os vínculos dos documentos.
     */
    private function removeSourcesAfterRemap(array $sources, callable $remap, ?callable $afterRemove = null): void
    {
        $conn = $this->em->getConnection();
        $conn->beginTransaction();
        try {
            foreach ($sources as $source) {
                $remap((int) $source->getId());
                $this->em->remove($source);
            }
            $this->em->flush();
            if ($afterRemove !== null) {
                $afterRemove();
            }
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    /**
     * Move as linhas de uma tabela de vínculo documento×entidade do secundário para o master,
     * descartando as que duplicariam um vínculo que o documento já tem com o master.
     */
    private function remapDocumentLinks(string $table, string $column, int $sourceId, int $masterId): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            "DELETE l1 FROM {$table} l1
             JOIN {$table} l2 ON l1.document_id = l2.document_id
             WHERE l1.{$column} = ? AND l2.{$column} = ?",
            [$sourceId, $masterId]
        );
        $conn->executeStatement("UPDATE {$table} SET {$column} = ? WHERE {$column} = ?", [$masterId, $sourceId]);
    }

    public function remapAuthorLinks(int $sourceId, int $masterId): void
    {
        $this->remapDocumentLinks('document_author', 'author_identity_id', $sourceId, $masterId);
    }

    public function remapKeywordLinks(int $sourceId, int $masterId): void
    {
        $conn = $this->em->getConnection();
        $this->remapDocumentLinks('document_keyword', 'keyword_id', $sourceId, $masterId);
        // Termos agrupados sob o secundário passam para o conceito do master
        $conn->executeStatement('UPDATE keyword SET keyword_concept_id = ? WHERE keyword_concept_id = ? AND id <> ?', [$masterId, $sourceId, $sourceId]);
        $conn->executeStatement('UPDATE thesaurus_match SET keyword_id = ? WHERE keyword_id = ?', [$masterId, $sourceId]);
        $conn->executeStatement('UPDATE keyword_treatment_log SET keyword_id = ? WHERE keyword_id = ?', [$masterId, $sourceId]);
    }

    public function remapInstitutionLinks(int $sourceId, int $masterId): void
    {
        $this->remapDocumentLinks('documento_instituicoes', 'institution_id', $sourceId, $masterId);
        $this->em->getConnection()->executeStatement(
            'UPDATE instituicao_unidades SET parent_institution_id = ? WHERE parent_institution_id = ?',
            [$masterId, $sourceId]
        );
    }

    public function remapJournalLinks(int $sourceId, int $masterId): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE document SET qualis_journal_id = ? WHERE qualis_journal_id = ?',
            [$masterId, $sourceId]
        );
    }

    /**
     * País: documentos, regiões, estados, cidades e instituições passam para o master
     * (estados/regiões/cidades têm FK ON DELETE CASCADE para o país).
     */
    public function remapCountryLinks(int $sourceId, int $masterId): void
    {
        $conn = $this->em->getConnection();
        $this->remapDocumentLinks('documento_paises', 'country_id', $sourceId, $masterId);

        // Região de mesmo nome já existente no master: reaproveita e descarta a do secundário
        $conn->executeStatement(
            'UPDATE estados e
             JOIN regioes rs ON rs.id = e.region_id AND rs.country_id = ?
             JOIN regioes rm ON rm.country_id = ? AND rm.name = rs.name
             SET e.region_id = rm.id',
            [$sourceId, $masterId]
        );
        $conn->executeStatement(
            'DELETE rs FROM regioes rs
             JOIN regioes rm ON rm.country_id = ? AND rm.name = rs.name
             WHERE rs.country_id = ?',
            [$masterId, $sourceId]
        );
        $conn->executeStatement('UPDATE regioes SET country_id = ? WHERE country_id = ?', [$masterId, $sourceId]);
        $conn->executeStatement('UPDATE estados SET country_id = ? WHERE country_id = ?', [$masterId, $sourceId]);
        $conn->executeStatement('UPDATE cidades SET country_id = ? WHERE country_id = ?', [$masterId, $sourceId]);
        $conn->executeStatement('UPDATE instituicoes_ensino SET country_id = ? WHERE country_id = ?', [$masterId, $sourceId]);
    }

    /** Estado: documentos, cidades e instituições passam para o master (FK SET NULL os deixaria sem estado). */
    public function remapStateLinks(int $sourceId, int $masterId): void
    {
        $conn = $this->em->getConnection();
        $this->remapDocumentLinks('documento_estados', 'state_id', $sourceId, $masterId);
        $conn->executeStatement('UPDATE cidades SET state_id = ? WHERE state_id = ?', [$masterId, $sourceId]);
        $conn->executeStatement('UPDATE instituicoes_ensino SET state_id = ? WHERE state_id = ?', [$masterId, $sourceId]);
    }

    /** Cidade: documentos e instituições passam para o master. */
    public function remapCityLinks(int $sourceId, int $masterId): void
    {
        $this->remapDocumentLinks('documento_cidades', 'city_id', $sourceId, $masterId);
        $this->em->getConnection()->executeStatement(
            'UPDATE instituicoes_ensino SET city_id = ? WHERE city_id = ?',
            [$masterId, $sourceId]
        );
    }
}
