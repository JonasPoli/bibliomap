<?php

namespace App\Controller;

use App\Entity\Country;
use App\Entity\CountryVariation;
use App\Entity\Region;
use App\Entity\State;
use App\Entity\StateVariation;
use App\Entity\City;
use App\Entity\CityVariation;
use App\Service\Import\DocumentEnrichmentService;
use App\Service\Import\StringNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use App\Service\Thesaurus\ThesaurusFileService;
use App\Service\Thesaurus\EntityMergeService;

#[Route('/admin/geography')]
#[IsGranted('ROLE_ADMIN')]
class AdminGeographyController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ThesaurusFileService $thesaurusService,
        private readonly EntityMergeService $mergeService,
    ) {}

    #[Route('', name: 'app_admin_geography_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $search = trim($request->query->getString('search', ''));
        $normSearch = StringNormalizer::normalizeString($search, true);
        $regions = $this->em->getRepository(Region::class)->findBy([], ['name' => 'ASC']);

        // Countries query with variations search
        $countryQb = $this->em->createQueryBuilder()
            ->select('DISTINCT c')
            ->from(Country::class, 'c')
            ->leftJoin('c.variations', 'v');

        if ($search !== '') {
            $countryQb->andWhere('c.officialName LIKE :search OR c.commonName LIKE :search OR c.sigla LIKE :search OR c.isoAlpha2 LIKE :search OR c.isoAlpha3 LIKE :search OR v.variationName LIKE :search OR v.normalizedName LIKE :normSearch')
                ->setParameter('search', '%' . $search . '%')
                ->setParameter('normSearch', '%' . $normSearch . '%');
        }
        $countries = $countryQb->orderBy('c.commonName', 'ASC')->getQuery()->getResult();

        // States query with variations search
        $stateQb = $this->em->createQueryBuilder()
            ->select('DISTINCT s')
            ->from(State::class, 's')
            ->leftJoin('s.country', 'c')
            ->leftJoin('s.region', 'r')
            ->leftJoin('s.variations', 'v');

        if ($search !== '') {
            $stateQb->andWhere('s.officialName LIKE :search OR s.sigla LIKE :search OR c.commonName LIKE :search OR r.name LIKE :search OR v.variationName LIKE :search OR v.normalizedName LIKE :normSearch')
                ->setParameter('search', '%' . $search . '%')
                ->setParameter('normSearch', '%' . $normSearch . '%');
        }
        $states = $stateQb->orderBy('s.officialName', 'ASC')->getQuery()->getResult();

        // Cities query with variations search
        $cityQb = $this->em->createQueryBuilder()
            ->select('DISTINCT ct')
            ->from(City::class, 'ct')
            ->leftJoin('ct.country', 'c')
            ->leftJoin('ct.state', 's')
            ->leftJoin('ct.variations', 'v');

        if ($search !== '') {
            $cityQb->andWhere('ct.officialName LIKE :search OR s.officialName LIKE :search OR c.commonName LIKE :search OR v.variationName LIKE :search OR v.normalizedName LIKE :normSearch')
                ->setParameter('search', '%' . $search . '%')
                ->setParameter('normSearch', '%' . $normSearch . '%');
        }
        $cities = $cityQb->orderBy('ct.officialName', 'ASC')->getQuery()->getResult();

        return $this->render('admin/geography/index.html.twig', [
            'countries' => $countries,
            'regions' => $regions,
            'states' => $states,
            'cities' => $cities,
            'search' => $search,
        ]);
    }

    // ── Countries CRUD ────────────────────────────────────────────────────────

    #[Route('/country/new', name: 'app_admin_geography_country_new', methods: ['GET', 'POST'])]
    public function newCountry(Request $request): Response
    {
        $country = new Country();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('new_country', $request->request->get('_token'))) {
                $this->addFlash('danger', 'Token CSRF inválido.');
                return $this->redirectToRoute('app_admin_geography_index');
            }

            $country->setOfficialName((string)$request->request->get('officialName'));
            $country->setCommonName((string)$request->request->get('commonName'));
            $country->setSigla($request->request->get('sigla') ?: null);
            $country->setIsoCode($request->request->get('isoCode') ?: null);
            $country->setContinente($request->request->get('continente') ?: null);
            $country->setNationality($request->request->get('nationality') ?: null);
            $country->setFoundationYear($request->request->get('foundationYear') !== '' && $request->request->get('foundationYear') !== null ? (int)$request->request->get('foundationYear') : null);
            $country->setExtinctionYear($request->request->get('extinctionYear') !== '' && $request->request->get('extinctionYear') !== null ? (int)$request->request->get('extinctionYear') : null);
            $country->setStatus($request->request->getBoolean('status', true));

            $this->em->persist($country);
            $this->em->flush();

            $this->syncCountryVariations($country, (string)$request->request->get('variationsText'));

            $this->addFlash('success', "País '{$country->getCommonName()}' criado com sucesso!");
            return $this->redirectToRoute('app_admin_geography_index');
        }

        return $this->render('admin/geography/new_country.html.twig', [
            'country' => $country,
        ]);
    }

    #[Route('/country/{id}/edit', name: 'app_admin_geography_country_edit', methods: ['GET', 'POST'])]
    public function editCountry(Country $country, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('edit_country_' . $country->getId(), $request->request->get('_token'))) {
                $this->addFlash('danger', 'Token CSRF inválido.');
                return $this->redirectToRoute('app_admin_geography_index');
            }

            $country->setOfficialName((string)$request->request->get('officialName'));
            $country->setCommonName((string)$request->request->get('commonName'));
            $country->setSigla($request->request->get('sigla') ?: null);
            $country->setIsoCode($request->request->get('isoCode') ?: null);
            $country->setContinente($request->request->get('continente') ?: null);
            $country->setNationality($request->request->get('nationality') ?: null);
            $country->setFoundationYear($request->request->get('foundationYear') !== '' && $request->request->get('foundationYear') !== null ? (int)$request->request->get('foundationYear') : null);
            $country->setExtinctionYear($request->request->get('extinctionYear') !== '' && $request->request->get('extinctionYear') !== null ? (int)$request->request->get('extinctionYear') : null);
            $country->setStatus($request->request->getBoolean('status', true));

            $this->em->flush();

            $this->syncCountryVariations($country, (string)$request->request->get('variationsText'));

            $this->addFlash('success', "País '{$country->getCommonName()}' atualizado!");
            return $this->redirectToRoute('app_admin_geography_index');
        }

        $variationNames = [];
        foreach ($country->getVariations() as $v) {
            if (DocumentEnrichmentService::normalize($v->getVariationName()) !== DocumentEnrichmentService::normalize($country->getCommonName()) &&
                DocumentEnrichmentService::normalize($v->getVariationName()) !== DocumentEnrichmentService::normalize($country->getOfficialName())) {
                $variationNames[] = $v->getVariationName();
            }
        }
        $variationsText = implode("\n", $variationNames);

        $otherCountries = $this->em->createQueryBuilder()
            ->select('c')
            ->from(Country::class, 'c')
            ->where('c.id != :id')
            ->setParameter('id', $country->getId())
            ->orderBy('c.commonName', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->render('admin/geography/edit_country.html.twig', [
            'country' => $country,
            'variationsText' => $variationsText,
            'other_countries' => $otherCountries,
        ]);
    }

    // ── States CRUD ───────────────────────────────────────────────────────────

    #[Route('/state/new', name: 'app_admin_geography_state_new', methods: ['GET', 'POST'])]
    public function newState(Request $request): Response
    {
        $state = new State();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('new_state', $request->request->get('_token'))) {
                $this->addFlash('danger', 'Token CSRF inválido.');
                return $this->redirectToRoute('app_admin_geography_index');
            }

            $state->setOfficialName((string)$request->request->get('officialName'));
            $state->setSigla($request->request->get('sigla') ?: null);
            $state->setOfficialCode($request->request->get('officialCode') ?: null);
            $state->setStatus($request->request->getBoolean('status', true));

            $countryId = $request->request->get('countryId');
            if ($countryId) {
                $country = $this->em->getRepository(Country::class)->find($countryId);
                $state->setCountry($country);
            }

            $regionId = $request->request->get('regionId');
            if ($regionId) {
                $region = $this->em->getRepository(Region::class)->find($regionId);
                $state->setRegion($region);
            }

            $this->em->persist($state);
            $this->em->flush();

            $this->syncStateVariations($state, (string)$request->request->get('variationsText'));

            $this->addFlash('success', "Estado '{$state->getOfficialName()}' criado!");
            return $this->redirectToRoute('app_admin_geography_index');
        }

        $countries = $this->em->getRepository(Country::class)->findBy(['status' => true], ['commonName' => 'ASC']);
        $regions = $this->em->getRepository(Region::class)->findBy(['status' => true], ['name' => 'ASC']);

        return $this->render('admin/geography/new_state.html.twig', [
            'state' => $state,
            'countries' => $countries,
            'regions' => $regions,
        ]);
    }

    #[Route('/state/{id}/edit', name: 'app_admin_geography_state_edit', methods: ['GET', 'POST'])]
    public function editState(State $state, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('edit_state_' . $state->getId(), $request->request->get('_token'))) {
                $this->addFlash('danger', 'Token CSRF inválido.');
                return $this->redirectToRoute('app_admin_geography_index');
            }

            $state->setOfficialName((string)$request->request->get('officialName'));
            $state->setSigla($request->request->get('sigla') ?: null);
            $state->setOfficialCode($request->request->get('officialCode') ?: null);
            $state->setStatus($request->request->getBoolean('status', true));

            $countryId = $request->request->get('countryId');
            if ($countryId) {
                $country = $this->em->getRepository(Country::class)->find($countryId);
                $state->setCountry($country);
            }

            $regionId = $request->request->get('regionId');
            if ($regionId) {
                $region = $this->em->getRepository(Region::class)->find($regionId);
                $state->setRegion($region);
            } else {
                $state->setRegion(null);
            }

            $this->em->flush();

            $this->syncStateVariations($state, (string)$request->request->get('variationsText'));

            $this->addFlash('success', "Estado '{$state->getOfficialName()}' atualizado!");
            return $this->redirectToRoute('app_admin_geography_index');
        }

        $countries = $this->em->getRepository(Country::class)->findBy(['status' => true], ['commonName' => 'ASC']);
        $regions = $this->em->getRepository(Region::class)->findBy(['status' => true], ['name' => 'ASC']);

        $variationNames = [];
        foreach ($state->getVariations() as $v) {
            if (DocumentEnrichmentService::normalize($v->getVariationName()) !== DocumentEnrichmentService::normalize($state->getOfficialName())) {
                $variationNames[] = $v->getVariationName();
            }
        }
        $variationsText = implode("\n", $variationNames);

        $otherStates = $this->em->createQueryBuilder()
            ->select('s')
            ->from(State::class, 's')
            ->where('s.id != :id')
            ->setParameter('id', $state->getId())
            ->orderBy('s.officialName', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->render('admin/geography/edit_state.html.twig', [
            'state' => $state,
            'countries' => $countries,
            'regions' => $regions,
            'variationsText' => $variationsText,
            'other_states' => $otherStates,
        ]);
    }

    // ── Cities CRUD ───────────────────────────────────────────────────────────

    #[Route('/city/new', name: 'app_admin_geography_city_new', methods: ['GET', 'POST'])]
    public function newCity(Request $request): Response
    {
        $city = new City();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('new_city', $request->request->get('_token'))) {
                $this->addFlash('danger', 'Token CSRF inválido.');
                return $this->redirectToRoute('app_admin_geography_index');
            }

            $city->setOfficialName((string)$request->request->get('officialName'));
            $city->setNormalizedName(DocumentEnrichmentService::normalize((string)$request->request->get('officialName')));
            $city->setOfficialCode($request->request->get('officialCode') ?: null);
            $city->setStatus($request->request->getBoolean('status', true));

            $countryId = $request->request->get('countryId');
            if ($countryId) {
                $country = $this->em->getRepository(Country::class)->find($countryId);
                $city->setCountry($country);
            }

            $stateId = $request->request->get('stateId');
            if ($stateId) {
                $state = $this->em->getRepository(State::class)->find($stateId);
                $city->setState($state);
            }

            $this->em->persist($city);
            $this->em->flush();

            $this->syncCityVariations($city, (string)$request->request->get('variationsText'));

            $this->addFlash('success', "Cidade '{$city->getOfficialName()}' criada!");
            return $this->redirectToRoute('app_admin_geography_index');
        }

        $countries = $this->em->getRepository(Country::class)->findBy(['status' => true], ['commonName' => 'ASC']);
        $states = $this->em->getRepository(State::class)->findBy(['status' => true], ['officialName' => 'ASC']);

        return $this->render('admin/geography/new_city.html.twig', [
            'city' => $city,
            'countries' => $countries,
            'states' => $states,
        ]);
    }

    #[Route('/city/{id}/edit', name: 'app_admin_geography_city_edit', methods: ['GET', 'POST'])]
    public function editCity(City $city, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('edit_city_' . $city->getId(), $request->request->get('_token'))) {
                $this->addFlash('danger', 'Token CSRF inválido.');
                return $this->redirectToRoute('app_admin_geography_index');
            }

            $city->setOfficialName((string)$request->request->get('officialName'));
            $city->setNormalizedName(DocumentEnrichmentService::normalize((string)$request->request->get('officialName')));
            $city->setOfficialCode($request->request->get('officialCode') ?: null);
            $city->setStatus($request->request->getBoolean('status', true));

            $countryId = $request->request->get('countryId');
            if ($countryId) {
                $country = $this->em->getRepository(Country::class)->find($countryId);
                $city->setCountry($country);
            }

            $stateId = $request->request->get('stateId');
            if ($stateId) {
                $state = $this->em->getRepository(State::class)->find($stateId);
                $city->setState($state);
            } else {
                $city->setState(null);
            }

            $this->em->flush();

            $this->syncCityVariations($city, (string)$request->request->get('variationsText'));

            $this->addFlash('success', "Cidade '{$city->getOfficialName()}' atualizada!");
            return $this->redirectToRoute('app_admin_geography_index');
        }

        $countries = $this->em->getRepository(Country::class)->findBy(['status' => true], ['commonName' => 'ASC']);
        $states = $this->em->getRepository(State::class)->findBy(['status' => true], ['officialName' => 'ASC']);

        $variationNames = [];
        foreach ($city->getVariations() as $v) {
            if (DocumentEnrichmentService::normalize($v->getVariationName()) !== DocumentEnrichmentService::normalize($city->getOfficialName())) {
                $variationNames[] = $v->getVariationName();
            }
        }
        $variationsText = implode("\n", $variationNames);

        $otherCities = $this->em->createQueryBuilder()
            ->select('c')
            ->from(City::class, 'c')
            ->where('c.id != :id')
            ->setParameter('id', $city->getId())
            ->orderBy('c.officialName', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->render('admin/geography/edit_city.html.twig', [
            'city' => $city,
            'countries' => $countries,
            'states' => $states,
            'variationsText' => $variationsText,
            'other_cities' => $otherCities,
        ]);
    }



    #[Route('/export/{type}', name: 'app_admin_geography_export', methods: ['GET'])]
    public function export(string $type): Response
    {
        $csv = \League\Csv\Writer::createFromString('');
        $filename = 'export.csv';

        if ($type === 'countries') {
            $filename = 'paises.csv';
            $csv->insertOne(['official_name', 'common_name', 'sigla', 'iso_code', 'continente', 'nationality', 'status', 'variations']);
            $countries = $this->em->getRepository(Country::class)->findBy([], ['commonName' => 'ASC']);
            foreach ($countries as $c) {
                $variationNames = [];
                foreach ($c->getVariations() as $v) {
                    $vName = $v->getVariationName();
                    $vNorm = DocumentEnrichmentService::normalize($vName);
                    if ($vNorm !== DocumentEnrichmentService::normalize($c->getOfficialName()) &&
                        $vNorm !== DocumentEnrichmentService::normalize($c->getCommonName()) &&
                        ($c->getSigla() === null || $vNorm !== DocumentEnrichmentService::normalize($c->getSigla()))
                    ) {
                        $variationNames[] = $vName;
                    }
                }
                $csv->insertOne([
                    $c->getOfficialName(),
                    $c->getCommonName(),
                    $c->getSigla() ?? '',
                    $c->getIsoCode() ?? '',
                    $c->getContinente() ?? '',
                    $c->getNationality() ?? '',
                    $c->isStatus() ? '1' : '0',
                    implode(';', $variationNames)
                ]);
            }
        } elseif ($type === 'states') {
            $filename = 'estados.csv';
            $csv->insertOne(['official_name', 'sigla', 'country_name', 'region_name', 'official_code', 'status', 'variations']);
            $states = $this->em->getRepository(State::class)->findBy([], ['officialName' => 'ASC']);
            foreach ($states as $s) {
                $variationNames = [];
                foreach ($s->getVariations() as $v) {
                    $vName = $v->getVariationName();
                    $vNorm = DocumentEnrichmentService::normalize($vName);
                    if ($vNorm !== DocumentEnrichmentService::normalize($s->getOfficialName()) &&
                        ($s->getSigla() === null || $vNorm !== DocumentEnrichmentService::normalize($s->getSigla()))
                    ) {
                        $variationNames[] = $vName;
                    }
                }
                $csv->insertOne([
                    $s->getOfficialName(),
                    $s->getSigla() ?? '',
                    $s->getCountry()->getCommonName(),
                    $s->getRegion() ? $s->getRegion()->getName() : '',
                    $s->getOfficialCode() ?? '',
                    $s->isStatus() ? '1' : '0',
                    implode(';', $variationNames)
                ]);
            }
        } elseif ($type === 'cities') {
            $filename = 'cidades.csv';
            $csv->insertOne(['official_name', 'state_sigla', 'country_name', 'official_code', 'status', 'variations']);
            $cities = $this->em->getRepository(City::class)->findBy([], ['officialName' => 'ASC']);
            foreach ($cities as $c) {
                $variationNames = [];
                foreach ($c->getVariations() as $v) {
                    $vName = $v->getVariationName();
                    $vNorm = DocumentEnrichmentService::normalize($vName);
                    if ($vNorm !== DocumentEnrichmentService::normalize($c->getOfficialName())) {
                        $variationNames[] = $vName;
                    }
                }
                $csv->insertOne([
                    $c->getOfficialName(),
                    $c->getState() ? $c->getState()->getSigla() : '',
                    $c->getCountry()->getCommonName(),
                    $c->getOfficialCode() ?? '',
                    $c->isStatus() ? '1' : '0',
                    implode(';', $variationNames)
                ]);
            }
        } else {
            throw $this->createNotFoundException('Tipo de exportação não suportado.');
        }

        $response = new Response($csv->toString());
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $response;
    }

    #[Route('/import/{type}', name: 'app_admin_geography_import', methods: ['POST'])]
    public function import(string $type, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('import_geography_' . $type, $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_admin_geography_index');
        }

        $file = $request->files->get('csv_file');
        if (!$file) {
            $this->addFlash('danger', 'Por favor, envie um arquivo CSV.');
            return $this->redirectToRoute('app_admin_geography_index');
        }

        try {
            set_time_limit(1200);

            // Preload maps to prevent database queries in loops
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

            $regions = $this->em->getRepository(Region::class)->findAll();
            $regionMap = [];
            foreach ($regions as $r) {
                $regionMap[$r->getCountry()->getId() . '_' . DocumentEnrichmentService::normalize($r->getName())] = $r;
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

            $csv = \League\Csv\Reader::createFromPath($file->getRealPath(), 'r');
            $csv->setHeaderOffset(0);

            $imported = 0;
            $updatedCount = 0;
            $batchSize = 200;
            $i = 0;

            foreach ($csv->getRecords() as $record) {
                if ($type === 'countries') {
                    $officialName = trim($record['official_name'] ?? '');
                    $commonName = trim($record['common_name'] ?? '');
                    if ($officialName === '' || $commonName === '') continue;

                    $sigla = trim($record['sigla'] ?? '') ?: null;
                    $isoCode = trim($record['iso_code'] ?? '') ?: null;
                    $continente = trim($record['continente'] ?? '') ?: null;
                    $nationality = trim($record['nationality'] ?? '') ?: null;
                    $status = ($record['status'] ?? '1') === '1';
                    $variationsStr = trim($record['variations'] ?? '');

                    $normName = DocumentEnrichmentService::normalize($officialName);
                    $country = $countryMap[$normName] ?? ($countryMap[DocumentEnrichmentService::normalize($commonName)] ?? null);

                    if ($country) {
                        $updatedCount++;
                    } else {
                        $country = new Country();
                        $this->em->persist($country);
                        $imported++;
                        $countryMap[$normName] = $country;
                    }

                    $country->setOfficialName($officialName);
                    $country->setCommonName($commonName);
                    $country->setSigla($sigla);
                    $country->setIsoCode($isoCode);
                    $country->setContinente($continente);
                    $country->setNationality($nationality);
                    $country->setStatus($status);

                    $rawVariations = $variationsStr !== '' ? explode(';', $variationsStr) : [];
                    $variationsText = implode("\n", $rawVariations);
                    $this->syncCountryVariations($country, $variationsText, false);

                } elseif ($type === 'states') {
                    $officialName = trim($record['official_name'] ?? '');
                    $sigla = trim($record['sigla'] ?? '');
                    $countryName = trim($record['country_name'] ?? '');
                    if ($officialName === '' || $countryName === '') continue;

                    $regionName = trim($record['region_name'] ?? '');
                    $officialCode = trim($record['official_code'] ?? '') ?: null;
                    $status = ($record['status'] ?? '1') === '1';
                    $variationsStr = trim($record['variations'] ?? '');

                    $country = $countryMap[DocumentEnrichmentService::normalize($countryName)] ?? null;
                    if (!$country) continue;

                    $region = null;
                    if ($regionName !== '') {
                        $regKey = $country->getId() . '_' . DocumentEnrichmentService::normalize($regionName);
                        $region = $regionMap[$regKey] ?? null;
                        if (!$region) {
                            $region = new Region();
                            $region->setCountry($country);
                            $region->setName($regionName);
                            $region->setSigla(strtoupper(substr($regionName, 0, 2)));
                            $region->setStatus(true);
                            $this->em->persist($region);
                            $regionMap[$regKey] = $region;
                        }
                    }

                    $stKey = $country->getId() . '_' . DocumentEnrichmentService::normalize($officialName);
                    $state = $stateMap[$stKey] ?? null;
                    if (!$state && $sigla !== '') {
                        $state = $stateMap[$country->getId() . '_' . DocumentEnrichmentService::normalize($sigla)] ?? null;
                    }

                    if ($state) {
                        $updatedCount++;
                    } else {
                        $state = new State();
                        $this->em->persist($state);
                        $imported++;
                        $stateMap[$stKey] = $state;
                    }

                    $state->setOfficialName($officialName);
                    $state->setSigla($sigla);
                    $state->setCountry($country);
                    $state->setRegion($region);
                    $state->setOfficialCode($officialCode);
                    $state->setStatus($status);

                    $rawVariations = $variationsStr !== '' ? explode(';', $variationsStr) : [];
                    $variationsText = implode("\n", $rawVariations);
                    $this->syncStateVariations($state, $variationsText, false);

                } elseif ($type === 'cities') {
                    $officialName = trim($record['official_name'] ?? '');
                    $stateSigla = trim($record['state_sigla'] ?? '');
                    $countryName = trim($record['country_name'] ?? '');
                    if ($officialName === '' || $countryName === '') continue;

                    $officialCode = trim($record['official_code'] ?? '') ?: null;
                    $status = ($record['status'] ?? '1') === '1';
                    $variationsStr = trim($record['variations'] ?? '');

                    $country = $countryMap[DocumentEnrichmentService::normalize($countryName)] ?? null;
                    if (!$country) continue;

                    $state = null;
                    if ($stateSigla !== '') {
                        $state = $stateMap[$country->getId() . '_' . DocumentEnrichmentService::normalize($stateSigla)] ?? null;
                    }

                    $stId = $state ? $state->getId() : 'null';
                    $cityKey = $country->getId() . '_' . $stId . '_' . DocumentEnrichmentService::normalize($officialName);
                    $city = $cityMap[$cityKey] ?? null;

                    if ($city) {
                        $updatedCount++;
                    } else {
                        $city = new City();
                        $this->em->persist($city);
                        $imported++;
                        $cityMap[$cityKey] = $city;
                    }

                    $city->setOfficialName($officialName);
                    $city->setNormalizedName(DocumentEnrichmentService::normalize($officialName));
                    $city->setCountry($country);
                    $city->setState($state);
                    $city->setOfficialCode($officialCode);
                    $city->setStatus($status);

                    $rawVariations = $variationsStr !== '' ? explode(';', $variationsStr) : [];
                    $variationsText = implode("\n", $rawVariations);
                    $this->syncCityVariations($city, $variationsText, false);
                }

                if ((++$i % $batchSize) === 0) {
                    $this->em->flush();
                }
            }

            $this->em->flush();

            $this->addFlash('success', "Importação de {$type} concluída! Criadas: {$imported}, Atualizadas: {$updatedCount}.");
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Erro ao processar arquivo: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_geography_index');
    }

    #[Route('/country/{id}/merge', name: 'app_admin_geography_country_merge', methods: ['POST'])]
    public function mergeCountry(Country $country, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('merge_country_' . $country->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_admin_geography_index');
        }

        $targetId = (int) $request->request->get('targetId');
        $target = $this->em->getRepository(Country::class)->find($targetId);

        if (!$target || $target->getId() === $country->getId()) {
            $this->addFlash('danger', 'País de destino inválido.');
            return $this->redirectToRoute('app_admin_geography_index');
        }

        $conn = $this->em->getConnection();
        $conn->executeStatement(
            'DELETE dp1 FROM documento_paises dp1
             JOIN documento_paises dp2 ON dp1.document_id = dp2.document_id
             WHERE dp1.country_id = ? AND dp2.country_id = ?',
            [$country->getId(), $target->getId()]
        );
        $conn->executeStatement(
            'UPDATE documento_paises SET country_id = ? WHERE country_id = ?',
            [$target->getId(), $country->getId()]
        );

        foreach ($country->getVariations() as $v) {
            $exists = false;
            foreach ($target->getVariations() as $tv) {
                if ($tv->getNormalizedName() === $v->getNormalizedName()) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $v->setCountry($target);
                $target->addVariation($v);
            } else {
                $this->em->remove($v);
            }
        }

        $names = array_filter([$country->getOfficialName(), $country->getCommonName(), $country->getSigla()]);
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
                $v = new CountryVariation();
                $v->setVariationName($name);
                $v->setNormalizedName($norm);
                $v->setVariationType('alternative');
                $v->setCountry($target);
                $this->em->persist($v);
            }
        }

        $this->em->remove($country);
        $this->em->flush();

        $this->addFlash('success', "País '{$country->getCommonName()}' mesclado com sucesso em '{$target->getCommonName()}'!");
        return $this->redirectToRoute('app_admin_geography_index');
    }

    #[Route('/state/{id}/merge', name: 'app_admin_geography_state_merge', methods: ['POST'])]
    public function mergeState(State $state, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('merge_state_' . $state->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_admin_geography_index');
        }

        $targetId = (int) $request->request->get('targetId');
        $target = $this->em->getRepository(State::class)->find($targetId);

        if (!$target || $target->getId() === $state->getId()) {
            $this->addFlash('danger', 'Estado de destino inválido.');
            return $this->redirectToRoute('app_admin_geography_index');
        }

        $conn = $this->em->getConnection();
        $conn->executeStatement(
            'DELETE de1 FROM documento_estados de1
             JOIN documento_estados de2 ON de1.document_id = de2.document_id
             WHERE de1.state_id = ? AND de2.state_id = ?',
            [$state->getId(), $target->getId()]
        );
        $conn->executeStatement(
            'UPDATE documento_estados SET state_id = ? WHERE state_id = ?',
            [$target->getId(), $state->getId()]
        );

        foreach ($state->getVariations() as $v) {
            $exists = false;
            foreach ($target->getVariations() as $tv) {
                if ($tv->getNormalizedName() === $v->getNormalizedName()) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $v->setState($target);
                $target->addVariation($v);
            } else {
                $this->em->remove($v);
            }
        }

        $names = array_filter([$state->getOfficialName(), $state->getSigla()]);
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
                $v = new StateVariation();
                $v->setVariationName($name);
                $v->setNormalizedName($norm);
                $v->setVariationType('alternative');
                $v->setState($target);
                $this->em->persist($v);
            }
        }

        $this->em->remove($state);
        $this->em->flush();

        $this->addFlash('success', "Estado '{$state->getOfficialName()}' mesclado em '{$target->getOfficialName()}' com sucesso!");
        return $this->redirectToRoute('app_admin_geography_index');
    }

    #[Route('/city/{id}/merge', name: 'app_admin_geography_city_merge', methods: ['POST'])]
    public function mergeCity(City $city, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('merge_city_' . $city->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_admin_geography_index');
        }

        $targetId = (int) $request->request->get('targetId');
        $target = $this->em->getRepository(City::class)->find($targetId);

        if (!$target || $target->getId() === $city->getId()) {
            $this->addFlash('danger', 'Cidade de destino inválida.');
            return $this->redirectToRoute('app_admin_geography_index');
        }

        $conn = $this->em->getConnection();
        $conn->executeStatement(
            'DELETE dc1 FROM documento_cidades dc1
             JOIN documento_cidades dc2 ON dc1.document_id = dc2.document_id
             WHERE dc1.city_id = ? AND dc2.city_id = ?',
            [$city->getId(), $target->getId()]
        );
        $conn->executeStatement(
            'UPDATE documento_cidades SET city_id = ? WHERE city_id = ?',
            [$target->getId(), $city->getId()]
        );

        foreach ($city->getVariations() as $v) {
            $exists = false;
            foreach ($target->getVariations() as $tv) {
                if ($tv->getNormalizedName() === $v->getNormalizedName()) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $v->setCity($target);
                $target->addVariation($v);
            } else {
                $this->em->remove($v);
            }
        }

        $names = array_filter([$city->getOfficialName()]);
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
                $v = new CityVariation();
                $v->setVariationName($name);
                $v->setNormalizedName($norm);
                $v->setVariationType('alternative');
                $v->setCity($target);
                $this->em->persist($v);
            }
        }

        $this->em->remove($city);
        $this->em->flush();

        $this->addFlash('success', "Cidade '{$city->getOfficialName()}' mesclada em '{$target->getOfficialName()}' com sucesso!");
        return $this->redirectToRoute('app_admin_geography_index');
    }

    #[Route('/variation/countries/{id}/separate', name: 'app_admin_geography_country_variation_separate', methods: ['POST'])]
    public function separateCountryVariation(int $id, Request $request): Response
    {
        $variation = $this->em->getRepository(CountryVariation::class)->find($id);
        if (!$variation) {
            $this->addFlash('danger', 'Variação não encontrada.');
            return $this->redirectToRoute('app_admin_geography_index');
        }

        if (!$this->isCsrfTokenValid('separate_var_' . $variation->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_admin_geography_country_edit', ['id' => $variation->getCountry()->getId()]);
        }

        $parent = $variation->getCountry();
        $varName = $variation->getVariationName();

        $newCountry = new Country();
        $newCountry->setOfficialName($varName);
        $newCountry->setCommonName($varName);
        $newCountry->setStatus(true);
        $this->em->persist($newCountry);

        $newVar = new CountryVariation();
        $newVar->setVariationName($varName);
        $newVar->setNormalizedName(DocumentEnrichmentService::normalize($varName));
        $newVar->setVariationType('official');
        $newVar->setCountry($newCountry);
        $this->em->persist($newVar);

        $parent->removeVariation($variation);
        $this->em->remove($variation);
        $this->em->flush();

        $this->addFlash('success', "Variação '{$varName}' desmembrada com sucesso!");
        return $this->redirectToRoute('app_admin_geography_country_edit', ['id' => $newCountry->getId()]);
    }

    #[Route('/variation/states/{id}/separate', name: 'app_admin_geography_state_variation_separate', methods: ['POST'])]
    public function separateStateVariation(int $id, Request $request): Response
    {
        $variation = $this->em->getRepository(StateVariation::class)->find($id);
        if (!$variation) {
            $this->addFlash('danger', 'Variação não encontrada.');
            return $this->redirectToRoute('app_admin_geography_index');
        }

        if (!$this->isCsrfTokenValid('separate_var_' . $variation->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_admin_geography_state_edit', ['id' => $variation->getState()->getId()]);
        }

        $parent = $variation->getState();
        $varName = $variation->getVariationName();

        $newState = new State();
        $newState->setOfficialName($varName);
        $newState->setSigla(strtoupper(substr($varName, 0, 2)));
        $newState->setCountry($parent->getCountry());
        $newState->setRegion($parent->getRegion());
        $newState->setStatus(true);
        $this->em->persist($newState);

        $newVar = new StateVariation();
        $newVar->setVariationName($varName);
        $newVar->setNormalizedName(DocumentEnrichmentService::normalize($varName));
        $newVar->setVariationType('official');
        $newVar->setState($newState);
        $this->em->persist($newVar);

        $parent->removeVariation($variation);
        $this->em->remove($variation);
        $this->em->flush();

        $this->addFlash('success', "Variação '{$varName}' desmembrada com sucesso!");
        return $this->redirectToRoute('app_admin_geography_state_edit', ['id' => $newState->getId()]);
    }

    #[Route('/variation/cities/{id}/separate', name: 'app_admin_geography_city_variation_separate', methods: ['POST'])]
    public function separateCityVariation(int $id, Request $request): Response
    {
        $variation = $this->em->getRepository(CityVariation::class)->find($id);
        if (!$variation) {
            $this->addFlash('danger', 'Variação não encontrada.');
            return $this->redirectToRoute('app_admin_geography_index');
        }

        if (!$this->isCsrfTokenValid('separate_var_' . $variation->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_admin_geography_city_edit', ['id' => $variation->getCity()->getId()]);
        }

        $parent = $variation->getCity();
        $varName = $variation->getVariationName();

        $newCity = new City();
        $newCity->setOfficialName($varName);
        $newCity->setNormalizedName(DocumentEnrichmentService::normalize($varName));
        $newCity->setCountry($parent->getCountry());
        $newCity->setState($parent->getState());
        $newCity->setStatus(true);
        $this->em->persist($newCity);

        $newVar = new CityVariation();
        $newVar->setVariationName($varName);
        $newVar->setNormalizedName(DocumentEnrichmentService::normalize($varName));
        $newVar->setVariationType('official');
        $newVar->setCity($newCity);
        $this->em->persist($newVar);

        $parent->removeVariation($variation);
        $this->em->remove($variation);
        $this->em->flush();

        $this->addFlash('success', "Variação '{$varName}' desmembrada com sucesso!");
        return $this->redirectToRoute('app_admin_geography_city_edit', ['id' => $newCity->getId()]);
    }

    private function findCountryHelper(string $name): ?Country
    {
        $name = trim($name);
        if ($name === '') return null;

        $country = $this->em->getRepository(Country::class)->createQueryBuilder('c')
            ->where('c.officialName = :name OR c.commonName = :name OR c.sigla = :name')
            ->setParameter('name', $name)
            ->getQuery()->getOneOrNullResult();

        if ($country) return $country;

        $norm = DocumentEnrichmentService::normalize($name);
        $variation = $this->em->getRepository(CountryVariation::class)->findOneBy(['normalizedName' => $norm]);
        return $variation ? $variation->getCountry() : null;
    }

    private function syncCountryVariations(Country $country, string $variationsText, bool $flush = true): void
    {
        $lines = explode("\n", $variationsText);
        $validVariationNames = [];
        
        $validVariationNames[$country->getOfficialName()] = 'official';
        $validVariationNames[$country->getCommonName()] = 'common';
        if ($country->getSigla()) {
            $validVariationNames[$country->getSigla()] = 'sigla';
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $validVariationNames[$line] = 'alternative';
            }
        }

        $existingVars = $country->getVariations();
        $existingMap = [];
        foreach ($existingVars as $v) {
            $existingMap[$v->getVariationName()] = $v;
        }

        foreach ($validVariationNames as $name => $type) {
            if (!isset($existingMap[$name])) {
                $v = new CountryVariation();
                $v->setVariationName($name);
                $v->setNormalizedName(DocumentEnrichmentService::normalize($name));
                $v->setVariationType($type);
                $country->addVariation($v);
                $this->em->persist($v);
            }
        }

        foreach ($existingMap as $name => $v) {
            if (!isset($validVariationNames[$name])) {
                $country->removeVariation($v);
                $this->em->remove($v);
            }
        }

        if ($flush) {
            $this->em->flush();
        }
    }

    private function syncStateVariations(State $state, string $variationsText, bool $flush = true): void
    {
        $lines = explode("\n", $variationsText);
        $validVariationNames = [];
        
        $validVariationNames[$state->getOfficialName()] = 'official';
        if ($state->getSigla()) {
            $validVariationNames[$state->getSigla()] = 'sigla';
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $validVariationNames[$line] = 'alternative';
            }
        }

        $existingVars = $state->getVariations();
        $existingMap = [];
        foreach ($existingVars as $v) {
            $existingMap[$v->getVariationName()] = $v;
        }

        foreach ($validVariationNames as $name => $type) {
            if (!isset($existingMap[$name])) {
                $v = new StateVariation();
                $v->setVariationName($name);
                $v->setNormalizedName(DocumentEnrichmentService::normalize($name));
                $v->setVariationType($type);
                $state->addVariation($v);
                $this->em->persist($v);
            }
        }

        foreach ($existingMap as $name => $v) {
            if (!isset($validVariationNames[$name])) {
                $state->removeVariation($v);
                $this->em->remove($v);
            }
        }

        if ($flush) {
            $this->em->flush();
        }
    }

    private function syncCityVariations(City $city, string $variationsText, bool $flush = true): void
    {
        $lines = explode("\n", $variationsText);
        $validVariationNames = [];
        
        $validVariationNames[$city->getOfficialName()] = 'official';

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $validVariationNames[$line] = 'alternative';
            }
        }

        $existingVars = $city->getVariations();
        $existingMap = [];
        foreach ($existingVars as $v) {
            $existingMap[$v->getVariationName()] = $v;
        }

        foreach ($validVariationNames as $name => $type) {
            if (!isset($existingMap[$name])) {
                $v = new CityVariation();
                $v->setVariationName($name);
                $v->setNormalizedName(DocumentEnrichmentService::normalize($name));
                $v->setVariationType($type);
                $city->addVariation($v);
                $this->em->persist($v);
            }
        }

        foreach ($existingMap as $name => $v) {
            if (!isset($validVariationNames[$name])) {
                $city->removeVariation($v);
                $this->em->remove($v);
            }
        }

        if ($flush) {
            $this->em->flush();
        }
    }

    #[Route('/export-thesaurus', name: 'app_admin_geography_export_thesaurus', methods: ['GET'])]
    public function exportThesaurus(Request $request): Response
    {
        $format = strtolower($request->query->get('format', 'the'));
        $filename = ($format === 'csv') ? 'thesauro_geografia.csv' : 'thesauro_geografia.the';
        $sql = 'SELECT c.common_name AS header, v.variation_name AS variation
                FROM paises c
                LEFT JOIN pais_variacoes_nome v ON v.country_id = c.id
                ORDER BY c.id ASC';

        return $this->thesaurusService->streamExport($this->em->getConnection(), $sql, $format, $filename);
    }

    #[Route('/import-thesaurus', name: 'app_admin_geography_import_thesaurus', methods: ['POST'])]
    public function importThesaurus(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('import_geography_thesaurus', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_admin_geography_index');
        }

        $file = $request->files->get('thesaurus_file');
        if (!$file) {
            $this->addFlash('danger', 'Por favor, envie um arquivo .the ou .csv.');
            return $this->redirectToRoute('app_admin_geography_index');
        }

        try {
            set_time_limit(600);
            $ext = strtolower($file->getClientOriginalExtension());
            $entries = $this->thesaurusService->parseFile($file->getRealPath(), $ext);

            $countriesMap = [];
            foreach ($this->em->getRepository(Country::class)->findAll() as $c) {
                $countriesMap[DocumentEnrichmentService::normalize($c->getCommonName())] = $c;
                $countriesMap[DocumentEnrichmentService::normalize($c->getOfficialName())] = $c;
                if ($c->getSigla()) {
                    $countriesMap[DocumentEnrichmentService::normalize($c->getSigla())] = $c;
                }
            }

            $addedVars = 0;
            $newCountries = 0;

            foreach ($entries as $entry) {
                $headerName = trim($entry['header'] ?? '');
                if ($headerName === '') continue;

                $normHeader = DocumentEnrichmentService::normalize($headerName);
                $country = $countriesMap[$normHeader] ?? null;

                if (!$country) {
                    $country = new Country();
                    $country->setOfficialName(mb_convert_case($headerName, MB_CASE_TITLE, 'UTF-8'));
                    $country->setCommonName(mb_convert_case($headerName, MB_CASE_TITLE, 'UTF-8'));
                    $country->setIsoCode(strtoupper(substr($normHeader, 0, 3)));
                    $country->setSigla(strtoupper(substr($normHeader, 0, 2)));
                    $country->setStatus(true);
                    $this->em->persist($country);
                    $countriesMap[$normHeader] = $country;
                    $newCountries++;
                }

                $existingVars = [];
                foreach ($country->getVariations() as $v) {
                    $existingVars[$v->getNormalizedName()] = true;
                }

                foreach ($entry['variations'] as $varName) {
                    $normVar = DocumentEnrichmentService::normalize($varName);
                    if ($normVar === '') continue;

                    $varName = mb_substr($varName, 0, 500, 'UTF-8');
                    $normVar = mb_substr($normVar, 0, 500, 'UTF-8');

                    if (!isset($existingVars[$normVar])) {
                        $v = new CountryVariation();
                        $v->setVariationName($varName);
                        $v->setNormalizedName($normVar);
                        $v->setVariationType('alternative');
                        $country->addVariation($v);
                        $existingVars[$normVar] = true;
                        $addedVars++;
                    }
                }
            }

            $this->em->flush();
            $this->addFlash('success', "Importação de Tesauro concluída! Novos Países: {$newCountries}, Novas Variações: {$addedVars}.");
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Erro na importação de tesauro: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_geography_index');
    }

    #[Route('/merge-preview', name: 'app_admin_geography_merge_preview', methods: ['POST'])]
    public function mergePreview(Request $request): Response
    {
        $ids = array_map('intval', (array) $request->request->all('ids'));
        $ids = array_values(array_filter($ids));

        if (count($ids) < 2 || count($ids) > 5) {
            $this->addFlash('warning', 'Selecione entre 2 e 5 países para mesclar.');
            return $this->redirectToRoute('app_admin_geography_index');
        }

        $countries = $this->em->getRepository(Country::class)->findBy(['id' => $ids]);
        if (count($countries) < 2) {
            $this->addFlash('danger', 'Países selecionados não foram encontrados.');
            return $this->redirectToRoute('app_admin_geography_index');
        }

        $allVariations = [];
        foreach ($countries as $c) {
            if ($c->getCommonName()) $allVariations[] = $c->getCommonName();
            if ($c->getOfficialName()) $allVariations[] = $c->getOfficialName();
            foreach ($c->getVariations() as $var) {
                if ($var->getVariationName()) $allVariations[] = $var->getVariationName();
            }
        }
        $allVariations = array_values(array_unique(array_filter($allVariations)));

        return $this->render('admin/geography/merge_preview.html.twig', [
            'countries' => $countries,
            'allVariations' => $allVariations,
        ]);
    }

    #[Route('/merge-execute', name: 'app_admin_geography_merge_execute', methods: ['POST'])]
    public function mergeExecute(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('merge_geography', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_admin_geography_index');
        }

        $masterId = (int) $request->request->get('master_id');
        $sourceIds = array_map('intval', (array) $request->request->all('source_ids'));
        $fields = (array) $request->request->all('fields');

        try {
            $master = $this->mergeService->mergeCountries($masterId, $sourceIds, $fields);
            $this->addFlash('success', "País '{$master->getCommonName()}' (#{$master->getId()}) mesclado e consolidado no Tesauro com sucesso!");
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Erro ao mesclar países: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_geography_index');
    }
}
