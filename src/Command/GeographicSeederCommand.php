<?php

namespace App\Command;

use App\Entity\Country;
use App\Entity\CountryVariation;
use App\Entity\Region;
use App\Entity\State;
use App\Entity\StateVariation;
use App\Entity\City;
use App\Entity\CityVariation;
use App\Entity\Institution;
use App\Entity\InstitutionVariation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:geography:seed',
    description: 'Seed standard countries, Brazilian regions, states, cities, and common variations',
)]
class GeographicSeederCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('BiblioMap — Seed Geography & Institutions Data');

        // Check if database is already seeded
        $countryRepo = $this->em->getRepository(Country::class);
        if ($countryRepo->count([]) > 0) {
            $io->warning('Database already has geographical data. Skipping seed.');
            return Command::SUCCESS;
        }

        $io->section('Seeding Countries and Variations...');

        $countriesData = [
            'Brazil' => [
                'commonName' => 'Brasil',
                'sigla' => 'BR',
                'isoCode' => 'BRA',
                'continente' => 'América do Sul',
                'nationality' => 'Brasileiro',
                'variations' => ['brasil', 'brazil', 'br', 'republica federativa do brasil']
            ],
            'United States' => [
                'commonName' => 'Estados Unidos',
                'sigla' => 'US',
                'isoCode' => 'USA',
                'continente' => 'América do Norte',
                'nationality' => 'Norte-americano',
                'variations' => ['usa', 'us', 'u.s.a.', 'united states', 'united states of america', 'america', 'wi 53707 usa', 'co 80309 usa', 'ca 94305 usa', 'ny 10027 usa']
            ],
            'United Kingdom' => [
                'commonName' => 'Reino Unido',
                'sigla' => 'UK',
                'isoCode' => 'GBR',
                'continente' => 'Europa',
                'nationality' => 'Britânico',
                'variations' => ['uk', 'u.k.', 'united kingdom', 'england', 'scotland', 'wales', 'great britain']
            ],
            'China' => [
                'commonName' => 'China',
                'sigla' => 'CN',
                'isoCode' => 'CHN',
                'continente' => 'Ásia',
                'nationality' => 'Chinês',
                'variations' => ['china', 'peoples r china', 'pr china', 'p.r. china', 'peoples republic of china']
            ],
            'Portugal' => [
                'commonName' => 'Portugal',
                'sigla' => 'PT',
                'isoCode' => 'PRT',
                'continente' => 'Europa',
                'nationality' => 'Português',
                'variations' => ['portugal', 'pt']
            ],
            'Germany' => [
                'commonName' => 'Alemanha',
                'sigla' => 'DE',
                'isoCode' => 'DEU',
                'continente' => 'Europa',
                'nationality' => 'Alemão',
                'variations' => ['germany', 'deutschland', 'alemanha', 'de']
            ],
            'France' => [
                'commonName' => 'França',
                'sigla' => 'FR',
                'isoCode' => 'FRA',
                'continente' => 'Europa',
                'nationality' => 'Francês',
                'variations' => ['france', 'frankreich', 'franca', 'fr']
            ],
            'Switzerland' => [
                'commonName' => 'Suíça',
                'sigla' => 'CH',
                'isoCode' => 'CHE',
                'continente' => 'Europa',
                'nationality' => 'Suíço',
                'variations' => ['switzerland', 'swiss', 'suisse', 'schweiz', 'ch']
            ],
            'Canada' => [
                'commonName' => 'Canadá',
                'sigla' => 'CA',
                'isoCode' => 'CAN',
                'continente' => 'América do Norte',
                'nationality' => 'Canadense',
                'variations' => ['canada', 'ca']
            ],
            'India' => [
                'commonName' => 'Índia',
                'sigla' => 'IN',
                'isoCode' => 'IND',
                'continente' => 'Ásia',
                'nationality' => 'Indiano',
                'variations' => ['india', 'in']
            ]
        ];

        $countriesEntities = [];
        foreach ($countriesData as $officialName => $data) {
            $country = new Country();
            $country->setOfficialName($officialName);
            $country->setCommonName($data['commonName']);
            $country->setSigla($data['sigla']);
            $country->setIsoCode($data['isoCode']);
            $country->setContinente($data['continente']);
            $country->setNationality($data['nationality']);
            $country->setStatus(true);

            $this->em->persist($country);
            $countriesEntities[$officialName] = $country;

            // Add self as first variation
            $selfVar = new CountryVariation();
            $selfVar->setVariationName($officialName);
            $selfVar->setNormalizedName($this->normalize($officialName));
            $selfVar->setVariationType('official');
            $country->addVariation($selfVar);
            $this->em->persist($selfVar);

            // Add common name variation
            if ($data['commonName'] !== $officialName) {
                $cVar = new CountryVariation();
                $cVar->setVariationName($data['commonName']);
                $cVar->setNormalizedName($this->normalize($data['commonName']));
                $cVar->setVariationType('common');
                $country->addVariation($cVar);
                $this->em->persist($cVar);
            }

            // Add other variations
            foreach ($data['variations'] as $v) {
                $cVar = new CountryVariation();
                $cVar->setVariationName($v);
                $cVar->setNormalizedName($this->normalize($v));
                $cVar->setVariationType('alternative');
                $country->addVariation($cVar);
                $this->em->persist($cVar);
            }
        }

        $io->section('Seeding Regions (Brazil)...');

        $regionsData = [
            'Norte' => 'N',
            'Nordeste' => 'NE',
            'Centro-Oeste' => 'CO',
            'Sudeste' => 'SE',
            'Sul' => 'S'
        ];

        $regionsEntities = [];
        $brazil = $countriesEntities['Brazil'];
        $order = 1;
        foreach ($regionsData as $name => $sigla) {
            $region = new Region();
            $region->setCountry($brazil);
            $region->setName($name);
            $region->setSigla($sigla);
            $region->setDisplayOrder($order++);
            $region->setStatus(true);

            $this->em->persist($region);
            $regionsEntities[$name] = $region;
        }

        $io->section('Seeding States (Brazil)...');

        $statesData = [
            'São Paulo' => ['sigla' => 'SP', 'region' => 'Sudeste', 'variations' => ['sp', 'sao paulo', 'estado de sao paulo']],
            'Rio de Janeiro' => ['sigla' => 'RJ', 'region' => 'Sudeste', 'variations' => ['rj', 'rio de janeiro', 'estado do rio de janeiro']],
            'Minas Gerais' => ['sigla' => 'MG', 'region' => 'Sudeste', 'variations' => ['mg', 'minas gerais', 'estado de minas gerais']],
            'Espírito Santo' => ['sigla' => 'ES', 'region' => 'Sudeste', 'variations' => ['es', 'espirito santo']],
            'Paraná' => ['sigla' => 'PR', 'region' => 'Sul', 'variations' => ['pr', 'parana']],
            'Santa Catarina' => ['sigla' => 'SC', 'region' => 'Sul', 'variations' => ['sc', 'santa catarina']],
            'Rio Grande do Sul' => ['sigla' => 'RS', 'region' => 'Sul', 'variations' => ['rs', 'rio grande do sul']],
            'Bahia' => ['sigla' => 'BA', 'region' => 'Nordeste', 'variations' => ['ba', 'bahia']],
            'Pernambuco' => ['sigla' => 'PE', 'region' => 'Nordeste', 'variations' => ['pe', 'pernambuco']],
            'Ceará' => ['sigla' => 'CE', 'region' => 'Nordeste', 'variations' => ['ce', 'ceara']],
            'Distrito Federal' => ['sigla' => 'DF', 'region' => 'Centro-Oeste', 'variations' => ['df', 'distrito federal', 'brasilia']],
            'Goiás' => ['sigla' => 'GO', 'region' => 'Centro-Oeste', 'variations' => ['go', 'goias']],
            'Mato Grosso' => ['sigla' => 'MT', 'region' => 'Centro-Oeste', 'variations' => ['mt', 'mato grosso']],
            'Mato Grosso do Sul' => ['sigla' => 'MS', 'region' => 'Centro-Oeste', 'variations' => ['ms', 'mato grosso do sul']],
            'Amazonas' => ['sigla' => 'AM', 'region' => 'Norte', 'variations' => ['am', 'amazonas']],
            'Pará' => ['sigla' => 'PA', 'region' => 'Norte', 'variations' => ['pa', 'para']]
        ];

        $statesEntities = [];
        foreach ($statesData as $officialName => $data) {
            $state = new State();
            $state->setCountry($brazil);
            $state->setRegion($regionsEntities[$data['region']]);
            $state->setOfficialName($officialName);
            $state->setSigla($data['sigla']);
            $state->setStatus(true);

            $this->em->persist($state);
            $statesEntities[$data['sigla']] = $state;

            // Add self variation
            $selfVar = new StateVariation();
            $selfVar->setVariationName($officialName);
            $selfVar->setNormalizedName($this->normalize($officialName));
            $selfVar->setVariationType('official');
            $state->addVariation($selfVar);
            $this->em->persist($selfVar);

            // Add variations
            foreach ($data['variations'] as $v) {
                $sVar = new StateVariation();
                $sVar->setVariationName($v);
                $sVar->setNormalizedName($this->normalize($v));
                $sVar->setVariationType('alternative');
                $state->addVariation($sVar);
                $this->em->persist($sVar);
            }
        }

        $io->section('Seeding Cities (Brazil)...');

        $citiesData = [
            'São Paulo' => ['state' => 'SP', 'variations' => ['sao paulo', 's. paulo', 's paulo']],
            'São Carlos' => ['state' => 'SP', 'variations' => ['sao carlos', 's. carlos', 's carlos']],
            'Campinas' => ['state' => 'SP', 'variations' => ['campinas']],
            'Rio de Janeiro' => ['state' => 'RJ', 'variations' => ['rio de janeiro', 'rio', 'rj']],
            'Belo Horizonte' => ['state' => 'MG', 'variations' => ['belo horizonte', 'bh']],
            'Curitiba' => ['state' => 'PR', 'variations' => ['curitiba']],
            'Porto Alegre' => ['state' => 'RS', 'variations' => ['porto alegre']],
            'Florianópolis' => ['state' => 'SC', 'variations' => ['florianopolis', 'floripa']],
            'Salvador' => ['state' => 'BA', 'variations' => ['salvador']],
            'Recife' => ['state' => 'PE', 'variations' => ['recife']],
            'Fortaleza' => ['state' => 'CE', 'variations' => ['fortaleza']],
            'Brasília' => ['state' => 'DF', 'variations' => ['brasilia']]
        ];

        foreach ($citiesData as $name => $data) {
            $city = new City();
            $city->setCountry($brazil);
            $city->setState($statesEntities[$data['state']]);
            $city->setOfficialName($name);
            $city->setNormalizedName($this->normalize($name));
            $city->setStatus(true);

            $this->em->persist($city);

            // Add self variation
            $selfVar = new CityVariation();
            $selfVar->setVariationName($name);
            $selfVar->setNormalizedName($this->normalize($name));
            $selfVar->setVariationType('official');
            $city->addVariation($selfVar);
            $this->em->persist($selfVar);

            // Add variations
            foreach ($data['variations'] as $v) {
                $cVar = new CityVariation();
                $cVar->setVariationName($v);
                $cVar->setNormalizedName($this->normalize($v));
                $cVar->setVariationType('alternative');
                $city->addVariation($cVar);
                $this->em->persist($cVar);
            }
        }

        $io->section('Seeding Sample Institutions...');

        $institutionsData = [
            'Universidade de São Paulo' => [
                'shortName' => 'USP',
                'sigla' => 'USP',
                'type' => 'Universidade',
                'natureza' => 'Pública',
                'country' => 'Brazil',
                'state' => 'SP',
                'city' => 'São Paulo',
                'website' => 'https://usp.br',
                'variations' => ['usp', 'universidade de sao paulo', 'univ. de sao paulo', 'universidade sao paulo', 'university of sao paulo', 'university of são paulo', 'univ de sao paulo']
            ],
            'Universidade Estadual de Campinas' => [
                'shortName' => 'UNICAMP',
                'sigla' => 'UNICAMP',
                'type' => 'Universidade',
                'natureza' => 'Pública',
                'country' => 'Brazil',
                'state' => 'SP',
                'city' => 'Campinas',
                'website' => 'https://unicamp.br',
                'variations' => ['unicamp', 'universidade estadual de campinas', 'state university of campinas']
            ],
            'Universidade Federal de São Carlos' => [
                'shortName' => 'UFSCar',
                'sigla' => 'UFSCar',
                'type' => 'Universidade',
                'natureza' => 'Pública',
                'country' => 'Brazil',
                'state' => 'SP',
                'city' => 'São Carlos',
                'website' => 'https://ufscar.br',
                'variations' => ['ufscar', 'universidade federal de sao carlos', 'federal university of sao carlos']
            ],
            'Harvard University' => [
                'shortName' => 'Harvard',
                'sigla' => 'HARVARD',
                'type' => 'Universidade',
                'natureza' => 'Privada',
                'country' => 'United States',
                'state' => null,
                'city' => null,
                'website' => 'https://harvard.edu',
                'variations' => ['harvard', 'harvard university', 'harvard univ']
            ]
        ];

        foreach ($institutionsData as $officialName => $data) {
            $inst = new Institution();
            $inst->setOfficialName($officialName);
            $inst->setShortName($data['shortName']);
            $inst->setSigla($data['sigla']);
            $inst->setInstitutionType($data['type']);
            $inst->setNatureza($data['natureza']);
            $inst->setOfficialWebsite($data['website']);
            $inst->setStatus(true);

            if ($data['country']) {
                $inst->setCountry($countriesEntities[$data['country']]);
            }
            if ($data['state']) {
                $inst->setState($statesEntities[$data['state']]);
            }
            // City search
            if ($data['city']) {
                $city = $this->em->getRepository(City::class)->findOneBy(['normalizedName' => $this->normalize($data['city'])]);
                if ($city) {
                    $inst->setCity($city);
                }
            }

            $this->em->persist($inst);

            // Add self variation
            $selfVar = new InstitutionVariation();
            $selfVar->setVariationName($officialName);
            $selfVar->setNormalizedName($this->normalize($officialName));
            $selfVar->setVariationType('official');
            $inst->addVariation($selfVar);
            $this->em->persist($selfVar);

            // Add other variations
            foreach ($data['variations'] as $v) {
                $iVar = new InstitutionVariation();
                $iVar->setVariationName($v);
                $iVar->setNormalizedName($this->normalize($v));
                $iVar->setVariationType('alternative');
                $inst->addVariation($iVar);
                $this->em->persist($iVar);
            }
        }

        $this->em->flush();
        $io->success('Geographical and Institutional seed data successfully loaded!');

        return Command::SUCCESS;
    }

    private function normalize(string $name): string
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
}
