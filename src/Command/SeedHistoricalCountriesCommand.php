<?php

namespace App\Command;

use App\Entity\Country;
use App\Entity\CountryVariation;
use App\Service\Import\DocumentEnrichmentService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:geography:seed-historical-countries',
    description: 'Seed and update historical countries (dissolved or formed in the last 50 years) with foundation and extinction years',
)]
class SeedHistoricalCountriesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title("Seeding Historical and Recently Formed Countries (Last 50 Years)");

        $countriesData = [
            // Extinct / Dissolved Countries
            [
                'commonName' => 'União Soviética',
                'officialName' => 'União das Repúblicas Socialistas Soviéticas',
                'sigla' => 'SU',
                'isoCode' => 'SUN',
                'continente' => 'Europa / Ásia',
                'nationality' => 'Soviética',
                'foundationYear' => 1922,
                'extinctionYear' => 1991,
                'variations' => ['URSS', 'USSR', 'Soviet Union', 'CCCP', 'Union of Soviet Socialist Republics', 'União Soviética', 'suoviet union'],
            ],
            [
                'commonName' => 'Iugoslávia',
                'officialName' => 'República Socialista Federativa da Iugoslávia',
                'sigla' => 'YU',
                'isoCode' => 'YUG',
                'continente' => 'Europa',
                'nationality' => 'Iugoslava',
                'foundationYear' => 1918,
                'extinctionYear' => 1992,
                'variations' => ['Iugoslávia', 'Yugoslavia', 'SFR Yugoslavia', 'Yugoslávia', 'República Socialista Federativa da Iugoslávia', 'sfr yugoslavia'],
            ],
            [
                'commonName' => 'Sérvia e Montenegro',
                'officialName' => 'República Federal da Iugoslávia',
                'sigla' => 'CS',
                'isoCode' => 'SCG',
                'continente' => 'Europa',
                'nationality' => 'Sérvio-montenegrina',
                'foundationYear' => 1992,
                'extinctionYear' => 2006,
                'variations' => ['Sérvia e Montenegro', 'Serbia and Montenegro', 'República Federal da Iugoslávia', 'FR Yugoslavia', 'serbia & montenegro'],
            ],
            [
                'commonName' => 'Tchecoslováquia',
                'officialName' => 'República da Tchecoslováquia',
                'sigla' => 'CS',
                'isoCode' => 'CSK',
                'continente' => 'Europa',
                'nationality' => 'Tchecoslovaca',
                'foundationYear' => 1918,
                'extinctionYear' => 1992,
                'variations' => ['Tchecoslováquia', 'Czechoslovakia', 'Checoslováquia', 'CZECHOSLOVAKIA'],
            ],
            [
                'commonName' => 'Alemanha Oriental',
                'officialName' => 'República Democrática Alemã',
                'sigla' => 'DD',
                'isoCode' => 'DDR',
                'continente' => 'Europa',
                'nationality' => 'Alemã Oriental',
                'foundationYear' => 1949,
                'extinctionYear' => 1990,
                'variations' => ['Alemanha Oriental', 'RDA', 'RDT', 'German Democratic Republic', 'GDR', 'East Germany', 'german dem rep'],
            ],
            [
                'commonName' => 'Iêmen do Sul',
                'officialName' => 'República Democrática Popular do Iêmen',
                'sigla' => 'YD',
                'isoCode' => 'YMD',
                'continente' => 'Ásia',
                'nationality' => 'Sul-iemenita',
                'foundationYear' => 1967,
                'extinctionYear' => 1990,
                'variations' => ['Iêmen do Sul', 'South Yemen', 'Democratic Yemen', "People's Democratic Republic of Yemen"],
            ],

            // Newly Formed Countries (1974-2026)
            [
                'commonName' => 'Sudão do Sul',
                'officialName' => 'República do Sudão do Sul',
                'sigla' => 'SS',
                'isoCode' => 'SSD',
                'continente' => 'África',
                'nationality' => 'Sul-sudanesa',
                'foundationYear' => 2011,
                'extinctionYear' => null,
                'variations' => ['Sudão do Sul', 'South Sudan', 'Republic of South Sudan'],
            ],
            [
                'commonName' => 'Kosovo',
                'officialName' => 'República do Kosovo',
                'sigla' => 'XK',
                'isoCode' => 'XKX',
                'continente' => 'Europa',
                'nationality' => 'Kosovara',
                'foundationYear' => 2008,
                'extinctionYear' => null,
                'variations' => ['Kosovo', 'Republic of Kosovo'],
            ],
            [
                'commonName' => 'Montenegro',
                'officialName' => 'Montenegro',
                'sigla' => 'ME',
                'isoCode' => 'MNE',
                'continente' => 'Europa',
                'nationality' => 'Montenegrina',
                'foundationYear' => 2006,
                'extinctionYear' => null,
                'variations' => ['Montenegro'],
            ],
            [
                'commonName' => 'Sérvia',
                'officialName' => 'República da Sérvia',
                'sigla' => 'RS',
                'isoCode' => 'SRB',
                'continente' => 'Europa',
                'nationality' => 'Sérvia',
                'foundationYear' => 2006,
                'extinctionYear' => null,
                'variations' => ['Sérvia', 'Serbia', 'Republic of Serbia'],
            ],
            [
                'commonName' => 'Timor-Leste',
                'officialName' => 'República Democrática de Timor-Leste',
                'sigla' => 'TL',
                'isoCode' => 'TLS',
                'continente' => 'Ásia',
                'nationality' => 'Timorense',
                'foundationYear' => 2002,
                'extinctionYear' => null,
                'variations' => ['Timor-Leste', 'East Timor', 'Democratic Republic of Timor-Leste'],
            ],
            [
                'commonName' => 'Eritreia',
                'officialName' => 'Estado da Eritreia',
                'sigla' => 'ER',
                'isoCode' => 'ERI',
                'continente' => 'África',
                'nationality' => 'Eritreia',
                'foundationYear' => 1993,
                'extinctionYear' => null,
                'variations' => ['Eritreia', 'Eritrea'],
            ],
            [
                'commonName' => 'República Tcheca',
                'officialName' => 'República Tcheca',
                'sigla' => 'CZ',
                'isoCode' => 'CZE',
                'continente' => 'Europa',
                'nationality' => 'Tcheca',
                'foundationYear' => 1993,
                'extinctionYear' => null,
                'variations' => ['República Tcheca', 'Czech Republic', 'Czechia', 'Tchequia', 'Czech Rep'],
            ],
            [
                'commonName' => 'Eslováquia',
                'officialName' => 'República Eslovaca',
                'sigla' => 'SK',
                'isoCode' => 'SVK',
                'continente' => 'Europa',
                'nationality' => 'Eslovaca',
                'foundationYear' => 1993,
                'extinctionYear' => null,
                'variations' => ['Eslováquia', 'Slovakia', 'Slovak Republic'],
            ],
            [
                'commonName' => 'Croácia',
                'officialName' => 'República da Croácia',
                'sigla' => 'HR',
                'isoCode' => 'HRV',
                'continente' => 'Europa',
                'nationality' => 'Croata',
                'foundationYear' => 1991,
                'extinctionYear' => null,
                'variations' => ['Croácia', 'Croatia'],
            ],
            [
                'commonName' => 'Eslovênia',
                'officialName' => 'República da Eslovênia',
                'sigla' => 'SI',
                'isoCode' => 'SVN',
                'continente' => 'Europa',
                'nationality' => 'Eslovena',
                'foundationYear' => 1991,
                'extinctionYear' => null,
                'variations' => ['Eslovênia', 'Slovenia'],
            ],
            [
                'commonName' => 'Bósnia e Herzegovina',
                'officialName' => 'Bósnia e Herzegovina',
                'sigla' => 'BA',
                'isoCode' => 'BIH',
                'continente' => 'Europa',
                'nationality' => 'Bósnia',
                'foundationYear' => 1992,
                'extinctionYear' => null,
                'variations' => ['Bósnia e Herzegovina', 'Bosnia and Herzegovina', 'Bosnia-Herzegovina', 'Bosnia'],
            ],
            [
                'commonName' => 'Macedônia do Norte',
                'officialName' => 'República da Macedônia do Norte',
                'sigla' => 'MK',
                'isoCode' => 'MKD',
                'continente' => 'Europa',
                'nationality' => 'Macedônia',
                'foundationYear' => 1991,
                'extinctionYear' => null,
                'variations' => ['Macedônia do Norte', 'North Macedonia', 'Macedonia', 'FYROM'],
            ],
            [
                'commonName' => 'Namíbia',
                'officialName' => 'República da Namíbia',
                'sigla' => 'NA',
                'isoCode' => 'NAM',
                'continente' => 'África',
                'nationality' => 'Namibiana',
                'foundationYear' => 1990,
                'extinctionYear' => null,
                'variations' => ['Namíbia', 'Namibia'],
            ],
        ];

        $repo = $this->em->getRepository(Country::class);
        $addedCount = 0;
        $updatedCount = 0;

        foreach ($countriesData as $item) {
            $country = null;

            if (!empty($item['isoCode'])) {
                $country = $repo->findOneBy(['isoCode' => $item['isoCode']]);
            }
            if (!$country && !empty($item['sigla'])) {
                $country = $repo->findOneBy(['sigla' => $item['sigla']]);
            }
            if (!$country) {
                $country = $repo->findOneBy(['commonName' => $item['commonName']]);
            }

            if (!$country) {
                $country = new Country();
                $addedCount++;
            } else {
                $updatedCount++;
            }

            $country->setCommonName($item['commonName']);
            $country->setOfficialName($item['officialName']);
            $country->setSigla($item['sigla']);
            $country->setIsoCode($item['isoCode']);
            $country->setContinente($item['continente']);
            $country->setNationality($item['nationality']);
            $country->setFoundationYear($item['foundationYear']);
            $country->setExtinctionYear($item['extinctionYear']);
            $country->setStatus(true);

            $this->em->persist($country);
            $this->em->flush();

            // Sync variations
            $existingVars = [];
            foreach ($country->getVariations() as $v) {
                $existingVars[$v->getNormalizedName()] = true;
            }

            foreach ($item['variations'] as $varName) {
                $norm = DocumentEnrichmentService::normalize($varName);
                if ($norm !== '' && !isset($existingVars[$norm])) {
                    $v = new CountryVariation();
                    $v->setVariationName($varName);
                    $v->setNormalizedName($norm);
                    $v->setVariationType('alternative');
                    $country->addVariation($v);
                    $existingVars[$norm] = true;
                }
            }
        }

        $this->em->flush();

        $io->success("Completed: {$addedCount} new countries added, {$updatedCount} historical countries updated with foundation & extinction years!");
        return Command::SUCCESS;
    }
}
