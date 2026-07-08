<?php

namespace App\Controller;

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
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/institutions')]
#[IsGranted('ROLE_ADMIN')]
class AdminInstitutionController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em
    ) {}

    #[Route('', name: 'app_admin_institutions_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $search = $request->query->getString('search', '');
        
        $qb = $this->em->createQueryBuilder()
            ->select('i')
            ->from(Institution::class, 'i')
            ->leftJoin('i.country', 'co')
            ->leftJoin('i.state', 'st')
            ->leftJoin('i.city', 'ci');

        if ($search !== '') {
            $qb->andWhere('i.officialName LIKE :search OR i.shortName LIKE :search OR i.sigla LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }

        $institutions = $qb->orderBy('i.officialName', 'ASC')->getQuery()->getResult();

        return $this->render('admin/institutions/index.html.twig', [
            'institutions' => $institutions,
            'search' => $search,
        ]);
    }

    #[Route('/new', name: 'app_admin_institutions_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $institution = new Institution();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('new_institution', $request->request->get('_token'))) {
                $this->addFlash('danger', 'Token CSRF inválido.');
                return $this->redirectToRoute('app_admin_institutions_index');
            }

            $this->saveInstitutionData($institution, $request);

            $this->em->persist($institution);
            $this->em->flush();

            // Save variations from textarea
            $this->syncVariations($institution, (string) $request->request->get('variationsText'));

            $this->addFlash('success', "Instituição '{$institution->getOfficialName()}' criada com sucesso!");
            return $this->redirectToRoute('app_admin_institutions_index');
        }

        $countries = $this->em->getRepository(Country::class)->findBy(['status' => true], ['commonName' => 'ASC']);
        $states = $this->em->getRepository(State::class)->findBy(['status' => true], ['officialName' => 'ASC']);
        $cities = $this->em->getRepository(City::class)->findBy(['status' => true], ['officialName' => 'ASC']);

        return $this->render('admin/institutions/new.html.twig', [
            'institution' => $institution,
            'countries' => $countries,
            'states' => $states,
            'cities' => $cities,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_admin_institutions_edit', methods: ['GET', 'POST'])]
    public function edit(Institution $institution, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('edit_institution_' . $institution->getId(), $request->request->get('_token'))) {
                $this->addFlash('danger', 'Token CSRF inválido.');
                return $this->redirectToRoute('app_admin_institutions_index');
            }

            $this->saveInstitutionData($institution, $request);
            $this->em->flush();

            // Sync variations
            $this->syncVariations($institution, (string) $request->request->get('variationsText'));

            $this->addFlash('success', "Instituição '{$institution->getOfficialName()}' atualizada!");
            return $this->redirectToRoute('app_admin_institutions_index');
        }

        $countries = $this->em->getRepository(Country::class)->findBy(['status' => true], ['commonName' => 'ASC']);
        $states = $this->em->getRepository(State::class)->findBy(['status' => true], ['officialName' => 'ASC']);
        $cities = $this->em->getRepository(City::class)->findBy(['status' => true], ['officialName' => 'ASC']);

        // Gather current variations
        $vars = $institution->getVariations();
        $variationNames = [];
        foreach ($vars as $v) {
            // Don't show officialName itself as variation since it is auto-linked
            if (DocumentEnrichmentService::normalize($v->getVariationName()) !== DocumentEnrichmentService::normalize($institution->getOfficialName())) {
                $variationNames[] = $v->getVariationName();
            }
        }
        $variationsText = implode("\n", $variationNames);

        $otherInstitutions = $this->em->createQueryBuilder()
            ->select('i')
            ->from(Institution::class, 'i')
            ->where('i.id != :id')
            ->setParameter('id', $institution->getId())
            ->orderBy('i.officialName', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->render('admin/institutions/edit.html.twig', [
            'institution' => $institution,
            'countries' => $countries,
            'states' => $states,
            'cities' => $cities,
            'variationsText' => $variationsText,
            'other_institutions' => $otherInstitutions,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_admin_institutions_delete', methods: ['POST'])]
    public function delete(Institution $institution, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete_institution_' . $institution->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_admin_institutions_index');
        }

        $name = $institution->getOfficialName();
        $this->em->remove($institution);
        $this->em->flush();

        $this->addFlash('success', "Instituição '{$name}' removida!");
        return $this->redirectToRoute('app_admin_institutions_index');
    }

    private function saveInstitutionData(Institution $institution, Request $request): void
    {
        $institution->setOfficialName((string) $request->request->get('officialName'));
        $institution->setShortName($request->request->get('shortName') ?: null);
        $institution->setSigla($request->request->get('sigla') ?: null);
        $institution->setInstitutionType($request->request->get('institutionType') ?: null);
        $institution->setNatureza($request->request->get('natureza') ?: null);
        $institution->setOfficialWebsite($request->request->get('officialWebsite') ?: null);
        $institution->setInstitutionalEmail($request->request->get('institutionalEmail') ?: null);
        $institution->setNotes($request->request->get('notes') ?: null);
        $institution->setStatus($request->request->getBoolean('status', true));

        $countryId = $request->request->get('countryId');
        if ($countryId) {
            $country = $this->em->getRepository(Country::class)->find($countryId);
            $institution->setCountry($country);
        } else {
            $institution->setCountry(null);
        }

        $stateId = $request->request->get('stateId');
        if ($stateId) {
            $state = $this->em->getRepository(State::class)->find($stateId);
            $institution->setState($state);
        } else {
            $institution->setState(null);
        }

        $cityId = $request->request->get('cityId');
        if ($cityId) {
            $city = $this->em->getRepository(City::class)->find($cityId);
            $institution->setCity($city);
        } else {
            $institution->setCity(null);
        }

        // Set Audit Info
        if ($institution->getId() === null) {
            $institution->setCreatedBy($this->getUser());
        }
        $institution->setUpdatedBy($this->getUser());
    }

    #[Route('/export', name: 'app_admin_institutions_export', methods: ['GET'])]
    public function export(): Response
    {
        $institutions = $this->em->getRepository(Institution::class)->findBy([], ['officialName' => 'ASC']);

        $csv = \League\Csv\Writer::createFromString('');
        $csv->insertOne([
            'official_name', 'short_name', 'sigla', 'institution_type', 'natureza', 
            'country_name', 'state_sigla', 'city_name', 'official_website', 
            'institutional_email', 'status', 'notes', 'variations'
        ]);

        foreach ($institutions as $inst) {
            $variationNames = [];
            foreach ($inst->getVariations() as $v) {
                $vName = $v->getVariationName();
                $vNorm = DocumentEnrichmentService::normalize($vName);
                if ($vNorm !== DocumentEnrichmentService::normalize($inst->getOfficialName()) &&
                    ($inst->getShortName() === null || $vNorm !== DocumentEnrichmentService::normalize($inst->getShortName())) &&
                    ($inst->getSigla() === null || $vNorm !== DocumentEnrichmentService::normalize($inst->getSigla()))
                ) {
                    $variationNames[] = $vName;
                }
            }

            $csv->insertOne([
                $inst->getOfficialName(),
                $inst->getShortName() ?? '',
                $inst->getSigla() ?? '',
                $inst->getInstitutionType() ?? '',
                $inst->getNatureza() ?? '',
                $inst->getCountry() ? $inst->getCountry()->getCommonName() : '',
                $inst->getState() ? $inst->getState()->getSigla() : '',
                $inst->getCity() ? $inst->getCity()->getOfficialName() : '',
                $inst->getOfficialWebsite() ?? '',
                $inst->getInstitutionalEmail() ?? '',
                $inst->isStatus() ? '1' : '0',
                $inst->getNotes() ?? '',
                implode(';', $variationNames)
            ]);
        }

        $response = new Response($csv->toString());
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="instituicoes.csv"');

        return $response;
    }

    #[Route('/import', name: 'app_admin_institutions_import', methods: ['POST'])]
    public function import(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('import_institutions', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_admin_institutions_index');
        }

        $file = $request->files->get('csv_file');
        if (!$file) {
            $this->addFlash('danger', 'Por favor, envie um arquivo CSV.');
            return $this->redirectToRoute('app_admin_institutions_index');
        }

        try {
            set_time_limit(1200);

            // Preload Countries, States, Cities, Institutions in memory maps to prevent timeouts
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

            $institutions = $this->em->getRepository(Institution::class)->findAll();
            $instMap = [];
            foreach ($institutions as $inst) {
                $instMap[DocumentEnrichmentService::normalize($inst->getOfficialName())] = $inst;
            }
            $instVars = $this->em->getRepository(InstitutionVariation::class)->findAll();
            foreach ($instVars as $iv) {
                $instMap[$iv->getNormalizedName()] = $iv->getInstitution();
            }

            $csv = \League\Csv\Reader::createFromPath($file->getRealPath(), 'r');
            $csv->setHeaderOffset(0);

            $imported = 0;
            $updatedCount = 0;
            $batchSize = 200;
            $i = 0;

            foreach ($csv->getRecords() as $record) {
                $officialName = trim($record['official_name'] ?? '');
                if ($officialName === '') continue;

                $shortName = trim($record['short_name'] ?? '') ?: null;
                $sigla = trim($record['sigla'] ?? '') ?: null;
                $instType = trim($record['institution_type'] ?? '') ?: null;
                $natureza = trim($record['natureza'] ?? '') ?: null;
                $countryName = trim($record['country_name'] ?? '');
                $stateSigla = trim($record['state_sigla'] ?? '');
                $cityName = trim($record['city_name'] ?? '');
                $website = trim($record['official_website'] ?? '') ?: null;
                $email = trim($record['institutional_email'] ?? '') ?: null;
                $status = ($record['status'] ?? '1') === '1';
                $notes = trim($record['notes'] ?? '') ?: null;
                $variationsStr = trim($record['variations'] ?? '');

                $normCountry = DocumentEnrichmentService::normalize($countryName);
                $country = $countryMap[$normCountry] ?? null;

                $state = null;
                if ($country && $stateSigla !== '') {
                    $state = $stateMap[$country->getId() . '_' . DocumentEnrichmentService::normalize($stateSigla)] ?? null;
                }

                $city = null;
                if ($country && $cityName !== '') {
                    $stKey = $state ? $state->getId() : 'null';
                    $city = $cityMap[$country->getId() . '_' . $stKey . '_' . DocumentEnrichmentService::normalize($cityName)] ?? null;
                }

                $normInstName = DocumentEnrichmentService::normalize($officialName);
                $inst = $instMap[$normInstName] ?? null;

                if ($inst) {
                    $updatedCount++;
                } else {
                    $inst = new Institution();
                    $inst->setCreatedBy($this->getUser());
                    $this->em->persist($inst);
                    $imported++;
                    $instMap[$normInstName] = $inst;
                }

                $inst->setOfficialName($officialName);
                $inst->setShortName($shortName);
                $inst->setSigla($sigla);
                $inst->setInstitutionType($instType);
                $inst->setNatureza($natureza);
                $inst->setCountry($country);
                $inst->setState($state);
                $inst->setCity($city);
                $inst->setOfficialWebsite($website);
                $inst->setInstitutionalEmail($email);
                $inst->setStatus($status);
                $inst->setNotes($notes);
                $inst->setUpdatedBy($this->getUser());

                $rawVariations = $variationsStr !== '' ? explode(';', $variationsStr) : [];
                $variationsText = implode("\n", $rawVariations);
                $this->syncVariations($inst, $variationsText, false);

                if ((++$i % $batchSize) === 0) {
                    $this->em->flush();
                }
            }

            $this->em->flush();

            $this->addFlash('success', "Importação concluída! Criadas: {$imported}, Atualizadas: {$updatedCount}.");
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Erro ao processar arquivo: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_institutions_index');
    }

    #[Route('/{id}/merge', name: 'app_admin_institutions_merge', methods: ['POST'])]
    public function merge(Institution $institution, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('merge_institution_' . $institution->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_admin_institutions_index');
        }

        $targetId = (int) $request->request->get('targetId');
        $target = $this->em->getRepository(Institution::class)->find($targetId);

        if (!$target || $target->getId() === $institution->getId()) {
            $this->addFlash('danger', 'Instituição de destino inválida.');
            return $this->redirectToRoute('app_admin_institutions_edit', ['id' => $institution->getId()]);
        }

        $conn = $this->em->getConnection();
        $conn->executeStatement(
            'DELETE di1 FROM documento_instituicoes di1
             JOIN documento_instituicoes di2 ON di1.document_id = di2.document_id
             WHERE di1.institution_id = ? AND di2.institution_id = ?',
            [$institution->getId(), $target->getId()]
        );
        $conn->executeStatement(
            'UPDATE documento_instituicoes SET institution_id = ? WHERE institution_id = ?',
            [$target->getId(), $institution->getId()]
        );

        foreach ($institution->getVariations() as $v) {
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

        $names = array_filter([$institution->getOfficialName(), $institution->getShortName(), $institution->getSigla()]);
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

        $this->em->remove($institution);
        $this->em->flush();

        $this->addFlash('success', "Instituição '{$institution->getOfficialName()}' mesclada com sucesso em '{$target->getOfficialName()}'!");
        return $this->redirectToRoute('app_admin_institutions_index');
    }

    #[Route('/variation/{id}/separate', name: 'app_admin_institutions_variation_separate', methods: ['POST'])]
    public function separateVariation(int $id, Request $request): Response
    {
        $variation = $this->em->getRepository(InstitutionVariation::class)->find($id);
        if (!$variation) {
            $this->addFlash('danger', 'Variação não encontrada.');
            return $this->redirectToRoute('app_admin_institutions_index');
        }

        if (!$this->isCsrfTokenValid('separate_var_' . $variation->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_admin_institutions_edit', ['id' => $variation->getInstitution()->getId()]);
        }

        $parent = $variation->getInstitution();
        $varName = $variation->getVariationName();

        $newInst = new Institution();
        $newInst->setOfficialName($varName);
        $newInst->setCountry($parent->getCountry());
        $newInst->setState($parent->getState());
        $newInst->setCity($parent->getCity());
        $newInst->setInstitutionType($parent->getInstitutionType());
        $newInst->setNatureza($parent->getNatureza());
        $newInst->setCreatedBy($this->getUser());
        $newInst->setUpdatedBy($this->getUser());
        $newInst->setStatus(true);

        $this->em->persist($newInst);

        $newVar = new InstitutionVariation();
        $newVar->setVariationName($varName);
        $newVar->setNormalizedName(DocumentEnrichmentService::normalize($varName));
        $newVar->setVariationType('official');
        $newVar->setInstitution($newInst);
        $this->em->persist($newVar);

        $parent->removeVariation($variation);
        $this->em->remove($variation);

        $this->em->flush();

        $this->addFlash('success', "Variação '{$varName}' desmembrada em uma nova instituição com sucesso!");
        return $this->redirectToRoute('app_admin_institutions_edit', ['id' => $newInst->getId()]);
    }

    private function syncVariations(Institution $institution, string $variationsText, bool $flush = true): void
    {
        $lines = explode("\n", $variationsText);
        $validVariationNames = [];
        
        $validVariationNames[$institution->getOfficialName()] = 'official';
        
        if ($institution->getShortName()) {
            $validVariationNames[$institution->getShortName()] = 'short';
        }
        if ($institution->getSigla()) {
            $validVariationNames[$institution->getSigla()] = 'sigla';
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $validVariationNames[$line] = 'alternative';
            }
        }

        $existingVars = $institution->getVariations();
        $existingMap = [];
        foreach ($existingVars as $v) {
            $existingMap[$v->getVariationName()] = $v;
        }

        foreach ($validVariationNames as $name => $type) {
            if (!isset($existingMap[$name])) {
                $v = new InstitutionVariation();
                $v->setVariationName($name);
                $v->setNormalizedName(DocumentEnrichmentService::normalize($name));
                $v->setVariationType($type);
                $institution->addVariation($v);
                $this->em->persist($v);
            } else {
                $existingMap[$name]->setVariationType($type);
            }
        }

        foreach ($existingMap as $name => $v) {
            if (!isset($validVariationNames[$name])) {
                $institution->removeVariation($v);
                $this->em->remove($v);
            }
        }

        if ($flush) {
            $this->em->flush();
        }
    }
}
