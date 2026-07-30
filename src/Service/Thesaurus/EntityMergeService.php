<?php

namespace App\Service\Thesaurus;

use App\Entity\AuthorIdentity;
use App\Entity\AuthorNameVariant;
use App\Entity\City;
use App\Entity\CityVariationName;
use App\Entity\Country;
use App\Entity\CountryVariationName;
use App\Entity\Institution;
use App\Entity\InstitutionVariationName;
use App\Entity\Keyword;
use App\Entity\KeywordVariationName;
use App\Entity\QualisJournal;
use App\Entity\JournalVariationName;
use App\Entity\State;
use App\Entity\StateVariationName;
use Doctrine\ORM\EntityManagerInterface;

class EntityMergeService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ThesaurusFileService $thesaurusFileService
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
        $sources = array_filter($instMap, fn($id) => $id !== $masterId, ARRAY_FILTER_USE_KEY);

        // Collect names and variations to consolidate into Master
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

        // Apply chosen field values to Master
        if (isset($selectedFields['officialName'])) $master->setOfficialName($selectedFields['officialName']);
        if (isset($selectedFields['shortName'])) $master->setShortName($selectedFields['shortName'] ?: null);
        if (isset($selectedFields['sigla'])) $master->setSigla($selectedFields['sigla'] ?: null);
        if (isset($selectedFields['razaoSocial'])) $master->setRazaoSocial($selectedFields['razaoSocial'] ?: null);
        if (isset($selectedFields['cnpj'])) $master->setCnpj($selectedFields['cnpj'] ?: null);
        if (isset($selectedFields['codigoIes'])) $master->setCodigoIes($selectedFields['codigoIes'] !== '' ? (int)$selectedFields['codigoIes'] : null);
        if (isset($selectedFields['institutionType'])) $master->setInstitutionType($selectedFields['institutionType'] ?: null);
        if (isset($selectedFields['natureza'])) $master->setNatureza($selectedFields['natureza'] ?: null);
        if (isset($selectedFields['vantagepoint'])) $master->setVantagepoint($selectedFields['vantagepoint'] ?: null);
        if (isset($selectedFields['officialWebsite'])) $master->setOfficialWebsite($selectedFields['officialWebsite'] ?: null);
        if (isset($selectedFields['foundationYear'])) $master->setFoundationYear($selectedFields['foundationYear'] !== '' ? (int)$selectedFields['foundationYear'] : null);
        if (isset($selectedFields['extinctionYear'])) $master->setExtinctionYear($selectedFields['extinctionYear'] !== '' ? (int)$selectedFields['extinctionYear'] : null);

        if (isset($selectedFields['countryId'])) {
            $country = $selectedFields['countryId'] ? $this->em->getRepository(Country::class)->find($selectedFields['countryId']) : null;
            $master->setCountry($country);
        }
        if (isset($selectedFields['stateId'])) {
            $state = $selectedFields['stateId'] ? $this->em->getRepository(State::class)->find($selectedFields['stateId']) : null;
            $master->setState($state);
        }
        if (isset($selectedFields['cityId'])) {
            $city = $selectedFields['cityId'] ? $this->em->getRepository(City::class)->find($selectedFields['cityId']) : null;
            $master->setCity($city);
        }

        // Deduplicate and re-attach variations to Master
        $existingVariations = [];
        foreach ($master->getVariations() as $var) {
            $existingVariations[$var->getNormalizedName()] = true;
        }

        foreach ($allVariationStrings as $rawVar) {
            $rawVar = trim($rawVar);
            if ($rawVar === '') continue;
            
            $norm = $this->thesaurusFileService->normalizeName($rawVar);
            if ($norm === '' || isset($existingVariations[$norm])) continue;

            $varObj = new InstitutionVariationName();
            $varObj->setInstitution($master);
            $varObj->setVariationName($rawVar);
            $varObj->setNormalizedName($norm);
            $varObj->setVariationType('alternative');
            $varObj->setStatus(true);

            $this->em->persist($varObj);
            $existingVariations[$norm] = true;
        }

        // Remove non-master institutions
        foreach ($sources as $source) {
            $this->em->remove($source);
        }

        $this->em->flush();
        return $master;
    }

    /**
     * Merge multiple Authors into Master AuthorIdentity
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
        $sources = array_filter($authorMap, fn($id) => $id !== $masterId, ARRAY_FILTER_USE_KEY);

        $allVariationStrings = [];
        foreach ($authorMap as $auth) {
            if ($auth->getPreferredName()) $allVariationStrings[] = $auth->getPreferredName();
            foreach ($auth->getVariations() as $var) {
                if ($var->getVariationName()) $allVariationStrings[] = $var->getVariationName();
            }
        }

        if (isset($selectedFields['preferredName'])) {
            $master->setPreferredName($selectedFields['preferredName']);
            $master->setNormalizedName($this->thesaurusFileService->normalizeName($selectedFields['preferredName']));
        }
        if (isset($selectedFields['orcid'])) $master->setOrcid($selectedFields['orcid'] ?: null);
        if (isset($selectedFields['lattesId'])) $master->setLattesId($selectedFields['lattesId'] ?: null);
        if (isset($selectedFields['notes'])) $master->setNotes($selectedFields['notes'] ?: null);

        $existingVariations = [];
        foreach ($master->getVariations() as $var) {
            $existingVariations[$var->getNormalizedName()] = true;
        }

        foreach ($allVariationStrings as $rawVar) {
            $rawVar = trim($rawVar);
            if ($rawVar === '') continue;
            
            $norm = $this->thesaurusFileService->normalizeName($rawVar);
            if ($norm === '' || isset($existingVariations[$norm])) continue;

            $varObj = new AuthorNameVariant();
            $varObj->setAuthorIdentity($master);
            $varObj->setVariationName($rawVar);
            $varObj->setNormalizedName($norm);
            $varObj->setVariationType('alternative');

            $this->em->persist($varObj);
            $existingVariations[$norm] = true;
        }

        foreach ($sources as $source) {
            $this->em->remove($source);
        }

        $this->em->flush();
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
        $sources = array_filter($journalMap, fn($id) => $id !== $masterId, ARRAY_FILTER_USE_KEY);

        $allVariationStrings = [];
        foreach ($journalMap as $j) {
            if ($j->getTitle()) $allVariationStrings[] = $j->getTitle();
            foreach ($j->getVariations() as $var) {
                if ($var->getVariationName()) $allVariationStrings[] = $var->getVariationName();
            }
        }

        if (isset($selectedFields['title'])) $master->setTitle($selectedFields['title']);
        if (isset($selectedFields['issn'])) $master->setIssn($selectedFields['issn'] ?: null);
        if (isset($selectedFields['qualis'])) $master->setQualis($selectedFields['qualis'] ?: null);

        $existingVariations = [];
        foreach ($master->getVariations() as $var) {
            $existingVariations[$var->getNormalizedName()] = true;
        }

        foreach ($allVariationStrings as $rawVar) {
            $rawVar = trim($rawVar);
            if ($rawVar === '') continue;
            
            $norm = $this->thesaurusFileService->normalizeName($rawVar);
            if ($norm === '' || isset($existingVariations[$norm])) continue;

            $varObj = new JournalVariationName();
            $varObj->setJournal($master);
            $varObj->setVariationName($rawVar);
            $varObj->setNormalizedName($norm);
            $varObj->setVariationType('alternative');
            $varObj->setStatus(true);

            $this->em->persist($varObj);
            $existingVariations[$norm] = true;
        }

        foreach ($sources as $source) {
            $this->em->remove($source);
        }

        $this->em->flush();
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
        $sources = array_filter($kwMap, fn($id) => $id !== $masterId, ARRAY_FILTER_USE_KEY);

        $allVariationStrings = [];
        foreach ($kwMap as $kw) {
            if ($kw->getKeywordOriginal()) $allVariationStrings[] = $kw->getKeywordOriginal();
            if ($kw->getKeywordDisplay()) $allVariationStrings[] = $kw->getKeywordDisplay();
            foreach ($kw->getVariations() as $var) {
                if ($var->getVariationName()) $allVariationStrings[] = $var->getVariationName();
            }
        }

        if (isset($selectedFields['keywordOriginal'])) $master->setKeywordOriginal($selectedFields['keywordOriginal']);
        if (isset($selectedFields['keywordDisplay'])) $master->setKeywordDisplay($selectedFields['keywordDisplay'] ?: null);
        if (isset($selectedFields['keywordType'])) $master->setKeywordType($selectedFields['keywordType'] ?: Keyword::TYPE_AUTHOR);

        $existingVariations = [];
        foreach ($master->getVariations() as $var) {
            $existingVariations[$var->getNormalizedName()] = true;
        }

        foreach ($allVariationStrings as $rawVar) {
            $rawVar = trim($rawVar);
            if ($rawVar === '') continue;
            
            $norm = $this->thesaurusFileService->normalizeName($rawVar);
            if ($norm === '' || isset($existingVariations[$norm])) continue;

            $varObj = new KeywordVariationName();
            $varObj->setKeyword($master);
            $varObj->setVariationName($rawVar);
            $varObj->setNormalizedName($norm);
            $varObj->setVariationType('alternative');
            $varObj->setStatus(true);

            $this->em->persist($varObj);
            $existingVariations[$norm] = true;
        }

        foreach ($sources as $source) {
            $this->em->remove($source);
        }

        $this->em->flush();
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
        $sources = array_filter($countryMap, fn($id) => $id !== $masterId, ARRAY_FILTER_USE_KEY);

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
        if (isset($selectedFields['isoAlpha2'])) $master->setIsoAlpha2($selectedFields['isoAlpha2'] ?: null);
        if (isset($selectedFields['isoAlpha3'])) $master->setIsoAlpha3($selectedFields['isoAlpha3'] ?: null);
        if (isset($selectedFields['foundationYear'])) $master->setFoundationYear($selectedFields['foundationYear'] !== '' ? (int)$selectedFields['foundationYear'] : null);
        if (isset($selectedFields['extinctionYear'])) $master->setExtinctionYear($selectedFields['extinctionYear'] !== '' ? (int)$selectedFields['extinctionYear'] : null);

        $existingVariations = [];
        foreach ($master->getVariations() as $var) {
            $existingVariations[$var->getNormalizedName()] = true;
        }

        foreach ($allVariationStrings as $rawVar) {
            $rawVar = trim($rawVar);
            if ($rawVar === '') continue;
            
            $norm = $this->thesaurusFileService->normalizeName($rawVar);
            if ($norm === '' || isset($existingVariations[$norm])) continue;

            $varObj = new CountryVariationName();
            $varObj->setCountry($master);
            $varObj->setVariationName($rawVar);
            $varObj->setNormalizedName($norm);
            $varObj->setVariationType('alternative');
            $varObj->setStatus(true);

            $this->em->persist($varObj);
            $existingVariations[$norm] = true;
        }

        foreach ($sources as $source) {
            $this->em->remove($source);
        }

        $this->em->flush();
        return $master;
    }
}
