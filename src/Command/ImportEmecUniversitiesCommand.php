<?php

namespace App\Command;

use App\Entity\City;
use App\Entity\Country;
use App\Entity\Institution;
use App\Entity\InstitutionVariation;
use App\Entity\State;
use App\Service\Import\DocumentEnrichmentService;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import:emec-universities',
    description: 'Import e-MEC federal universities, acronyms (siglas), and thesaurus rules from Excel and .the files',
)]
class ImportEmecUniversitiesCommand extends Command
{
    private const DEFAULT_FILE_PATH = '/Volumes/Dados/work/bibliomap/docs/roniberto/E-mec -  dados UNIVERSIDADES FEDERAIS.xlsx';
    private const DEFAULT_THESAURUS_PATH = '/Volumes/Dados/work/bibliomap/docs/roniberto/thesauro_Instituicoes_2020.the';

    public function __construct(
        private readonly EntityManagerInterface $em
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('filePath', InputArgument::OPTIONAL, 'Path to the e-MEC Excel (.xlsx) file', self::DEFAULT_FILE_PATH)
             ->addOption('thesaurusFile', null, InputOption::VALUE_OPTIONAL, 'Path to the .the thesaurus file', self::DEFAULT_THESAURUS_PATH);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', '2048M');
        set_time_limit(1800);

        $io = new SymfonyStyle($input, $output);
        $filePath = (string) $input->getArgument('filePath');

        if (!file_exists($filePath)) {
            $io->error("File not found at path: {$filePath}");
            return Command::FAILURE;
        }

        $io->title("Importing e-MEC Universities & Acronyms from Excel");
        $io->text("File: {$filePath}");

        try {
            $spreadsheet = IOFactory::load($filePath);
        } catch (\Throwable $e) {
            $io->error("Error loading Excel file: " . $e->getMessage());
            return Command::FAILURE;
        }

        // 1. Prepare Brazil Country & Location Lookups
        $brazil = $this->getOrCreateCountry('Brasil', 'BRA', 'BR');
        $statesMap = $this->loadStatesMap($brazil);
        $citiesMap = $this->loadCitiesMap($brazil);
        $institutionsMap = $this->loadInstitutionsMap();

        // 2. Process Sheet 1: Cópia de relatorio_consulta_pub (Federal Universities)
        $sheet1Name = 'Cópia de relatorio_consulta_pub';
        $sheet1 = $spreadsheet->getSheetByName($sheet1Name);

        $importedSheet1 = 0;
        $updatedSheet1 = 0;

        if ($sheet1) {
            $io->section("Processing Sheet 1: Federal Universities ({$sheet1Name})");
            $highestRow1 = $sheet1->getHighestRow();

            $io->progressStart($highestRow1 - 6);

            for ($row = 7; $row <= $highestRow1; $row++) {
                $io->progressAdvance();

                $officialName = trim((string) $sheet1->getCell([6, $row])->getValue());
                if ($officialName === '') continue;

                $codigoMantenedora = $this->parseInt($sheet1->getCell([1, $row])->getValue());
                $razaoSocial       = $this->parseString($sheet1->getCell([2, $row])->getValue());
                $cnpj              = $this->parseString($sheet1->getCell([3, $row])->getValue());
                $natureza          = $this->parseString($sheet1->getCell([4, $row])->getValue());
                $codigoIes          = $this->parseInt($sheet1->getCell([5, $row])->getValue());
                $sigla             = $this->parseString($sheet1->getCell([7, $row])->getValue());
                $latitude          = $this->formatCoordinate($sheet1->getCell([8, $row])->getValue());
                $longitude         = $this->formatCoordinate($sheet1->getCell([9, $row])->getValue());
                $telefone          = $this->parseString($sheet1->getCell([10, $row])->getValue());
                $website           = $this->parseString($sheet1->getCell([11, $row])->getValue());
                $email             = $this->parseString($sheet1->getCell([12, $row])->getValue());
                $enderecoSede      = $this->parseString($sheet1->getCell([13, $row])->getValue());
                $municipioName     = $this->parseString($sheet1->getCell([14, $row])->getValue());
                $ufSigla           = $this->parseString($sheet1->getCell([15, $row])->getValue());
                $orgAcademica      = $this->parseString($sheet1->getCell([16, $row])->getValue());
                $tipoCredenciament = $this->parseString($sheet1->getCell([17, $row])->getValue());
                $categoria         = $this->parseString($sheet1->getCell([18, $row])->getValue());
                $categoriaAdmin    = $this->parseString($sheet1->getCell([19, $row])->getValue());
                $dataCriacao       = $this->parseDate($sheet1->getCell([20, $row])->getValue());
                $ci                = $this->parseString($sheet1->getCell([21, $row])->getValue());
                $anoCi             = $this->parseInt($sheet1->getCell([22, $row])->getValue());
                $ciEad             = $this->parseString($sheet1->getCell([23, $row])->getValue());
                $anoCiEad          = $this->parseInt($sheet1->getCell([24, $row])->getValue());
                $igc               = $this->parseString($sheet1->getCell([25, $row])->getValue());
                $anoIgc            = $this->parseInt($sheet1->getCell([26, $row])->getValue());
                $reitor            = $this->parseString($sheet1->getCell([27, $row])->getValue());
                $repLegal          = $this->parseString($sheet1->getCell([28, $row])->getValue());
                $sinalizacoes      = $this->parseString($sheet1->getCell([29, $row])->getValue());
                $situacaoIes       = $this->parseString($sheet1->getCell([30, $row])->getValue());

                // Resolve state and city
                $state = null;
                if ($ufSigla) {
                    $state = $statesMap[strtoupper($ufSigla)] ?? null;
                }

                $city = null;
                if ($municipioName && $state) {
                    $cityKey = $state->getId() . '_' . DocumentEnrichmentService::normalize($municipioName);
                    if (isset($citiesMap[$cityKey])) {
                        $city = $citiesMap[$cityKey];
                    } else {
                        $city = new City();
                        $city->setOfficialName($municipioName);
                        $city->setCountry($brazil);
                        $city->setState($state);
                        $this->em->persist($city);
                        $this->em->flush();
                        $citiesMap[$cityKey] = $city;
                    }
                }

                $normName = DocumentEnrichmentService::normalize($officialName);
                $inst = $institutionsMap[$normName] ?? null;

                if (!$inst && $sigla) {
                    $inst = $institutionsMap[DocumentEnrichmentService::normalize($sigla)] ?? null;
                }

                if ($inst) {
                    $updatedSheet1++;
                } else {
                    $inst = new Institution();
                    $this->em->persist($inst);
                    $importedSheet1++;
                }

                $inst->setOfficialName($officialName);
                $inst->setSigla($sigla);
                $inst->setCodigoMantenedora($codigoMantenedora);
                $inst->setRazaoSocial($razaoSocial);
                $inst->setCnpj($cnpj);
                $inst->setNatureza($natureza ?: 'Pública');
                $inst->setCodigoIes($codigoIes);
                $inst->setLatitude($latitude);
                $inst->setLongitude($longitude);
                $inst->setTelefone($telefone);
                $inst->setOfficialWebsite($website);
                $inst->setInstitutionalEmail($email);
                $inst->setEnderecoSede($enderecoSede);
                $inst->setCountry($brazil);
                $inst->setState($state);
                $inst->setCity($city);
                $inst->setOrganizacaoAcademica($orgAcademica);
                $inst->setTipoCredenciamento($tipoCredenciament);
                $inst->setCategoria($categoria);
                $inst->setCategoriaAdministrativa($categoriaAdmin);
                $inst->setDataCriacao($dataCriacao);
                $inst->setCi($ci);
                $inst->setAnoCi($anoCi);
                $inst->setCiEad($ciEad);
                $inst->setAnoCiEad($anoCiEad);
                $inst->setIgc($igc);
                $inst->setAnoIgc($anoIgc);
                $inst->setReitor($reitor);
                $inst->setRepresentanteLegal($repLegal);
                $inst->setSinalizacoesVigentes($sinalizacoes);
                $inst->setSituacaoIes($situacaoIes ?: 'Ativa');
                $inst->setStatus(true);

                $this->em->flush();

                // Add to lookup
                $institutionsMap[$normName] = $inst;
                if ($sigla) {
                    $institutionsMap[DocumentEnrichmentService::normalize($sigla)] = $inst;
                }

                // Sync variations
                $this->syncInstitutionVariations($inst, array_filter([$officialName, $sigla, $razaoSocial]));
            }

            $io->progressFinish();
            $io->success("Sheet 1 finished! Created: {$importedSheet1}, Updated: {$updatedSheet1}");
        }

        // 3. Process Sheet 2: Siglas (3,117 IES)
        $sheet2Name = 'Siglas';
        $sheet2 = $spreadsheet->getSheetByName($sheet2Name);

        $importedSheet2 = 0;
        $updatedSheet2 = 0;

        if ($sheet2) {
            $io->section("Processing Sheet 2: All Institutions & Acronyms ({$sheet2Name})");
            $highestRow2 = $sheet2->getHighestRow();

            $io->progressStart($highestRow2 - 1);

            for ($row = 2; $row <= $highestRow2; $row++) {
                $io->progressAdvance();

                $iesName = trim((string) $sheet2->getCell([1, $row])->getValue());
                if ($iesName === '' || $iesName === 'Instituição(IES') continue;

                $sigla = $this->parseString($sheet2->getCell([2, $row])->getValue());
                if ($sigla === '-') $sigla = null;

                $vantagepoint = $this->parseString($sheet2->getCell([3, $row])->getValue());
                if ($vantagepoint === '#N/A' || str_starts_with((string)$vantagepoint, '=')) {
                    $vantagepoint = null;
                }
                if (!$vantagepoint && $sigla) {
                    $vantagepoint = "**{$sigla}0 1 {$iesName}";
                }

                $normName = DocumentEnrichmentService::normalize($iesName);
                $inst = $institutionsMap[$normName] ?? null;

                if (!$inst && $sigla) {
                    $inst = $institutionsMap[DocumentEnrichmentService::normalize($sigla)] ?? null;
                }

                if ($inst) {
                    $updatedSheet2++;
                } else {
                    $inst = new Institution();
                    $inst->setOfficialName($iesName);
                    $inst->setCountry($brazil);
                    $inst->setStatus(true);
                    $this->em->persist($inst);
                    $importedSheet2++;
                }

                if ($sigla && (!$inst->getSigla() || $inst->getSigla() === '')) {
                    $inst->setSigla($sigla);
                }
                if ($vantagepoint && !$inst->getVantagepoint()) {
                    $inst->setVantagepoint($vantagepoint);
                }

                // Add to lookup
                $institutionsMap[$normName] = $inst;
                if ($sigla) {
                    $institutionsMap[DocumentEnrichmentService::normalize($sigla)] = $inst;
                }

                // Sync variations
                $this->syncInstitutionVariations($inst, array_filter([$iesName, $sigla]));

                if ($row % 100 === 0) {
                    $this->em->flush();
                }
            }

            $this->em->flush();
            $io->progressFinish();
            $io->success("Sheet 2 finished! Created: {$importedSheet2}, Updated: {$updatedSheet2}");
        }

        // 4. Process Thesaurus File (.the)
        $thesaurusPath = (string) $input->getOption('thesaurusFile');
        $addedThesaurusVars = 0;

        if (file_exists($thesaurusPath)) {
            $io->section("Processing Thesaurus (.the) File: {$thesaurusPath}");
            $content = file_get_contents($thesaurusPath);
            if (!mb_detect_encoding($content, 'UTF-8', true)) {
                $content = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
            }

            $lines = explode("\n", $content);
            $currentHeader = null;
            $currentVars = [];
            $thesaurusEntries = [];

            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') continue;
                if (str_starts_with($line, '**')) {
                    if ($currentHeader !== null) {
                        $thesaurusEntries[] = ['header' => $currentHeader, 'vars' => $currentVars];
                    }
                    $currentHeader = trim(ltrim($line, '*#'));
                    $currentVars = [];
                } else {
                    if (preg_match('/\\^(.*?)\\$?$/', $line, $m)) {
                        $v = trim($m[1]);
                        if ($v !== '') $currentVars[] = $v;
                    } else {
                        $v = trim(rtrim($line, '$'));
                        if ($v !== '') $currentVars[] = $v;
                    }
                }
            }
            if ($currentHeader !== null) {
                $thesaurusEntries[] = ['header' => $currentHeader, 'vars' => $currentVars];
            }

            $io->progressStart(count($thesaurusEntries));

            foreach ($thesaurusEntries as $entry) {
                $io->progressAdvance();
                $headerName = $entry['header'];
                $normHeader = DocumentEnrichmentService::normalize($headerName);
                if ($normHeader === '') continue;

                $inst = $institutionsMap[$normHeader] ?? null;
                if (!$inst) {
                    $inst = new Institution();
                    $inst->setOfficialName(mb_convert_case($headerName, MB_CASE_TITLE, 'UTF-8'));
                    $inst->setCountry($brazil);
                    $inst->setStatus(true);
                    $this->em->persist($inst);
                    $this->em->flush();
                    $institutionsMap[$normHeader] = $inst;
                }

                $existingVars = [];
                foreach ($inst->getVariations() as $v) {
                    $existingVars[$v->getNormalizedName()] = true;
                }

                foreach ($entry['vars'] as $varName) {
                    $normVar = DocumentEnrichmentService::normalize($varName);
                    if ($normVar === '') continue;

                    if (!isset($existingVars[$normVar])) {
                        $variation = new InstitutionVariation();
                        $variation->setVariationName($varName);
                        $variation->setNormalizedName($normVar);
                        $variation->setVariationType('alternative');
                        $variation->setInstitution($inst);
                        $this->em->persist($variation);
                        $existingVars[$normVar] = true;
                        $addedThesaurusVars++;
                    }
                }
            }

            $this->em->flush();
            $io->progressFinish();
            $io->success("Thesaurus file finished! Added {$addedThesaurusVars} new variation rules.");
        }

        $totalInsts = $this->em->getRepository(Institution::class)->count([]);
        $totalVars  = $this->em->getRepository(InstitutionVariation::class)->count([]);

        $io->title("e-MEC & Thesaurus Import Summary");
        $io->listing([
            "Total Institutions in DB: {$totalInsts}",
            "Total Institution Variations in DB: {$totalVars}",
            "Federal Universities (Sheet 1) Created/Updated: {$importedSheet1} / {$updatedSheet1}",
            "General Institutions (Sheet 2) Created/Updated: {$importedSheet2} / {$updatedSheet2}",
            "Thesaurus (.the) Variations Added: {$addedThesaurusVars}",
        ]);

        return Command::SUCCESS;
    }

    private function getOrCreateCountry(string $name, string $isoCode, string $sigla): Country
    {
        $repo = $this->em->getRepository(Country::class);
        $country = $repo->findOneBy(['isoCode' => $isoCode]);
        if (!$country) {
            $country = $repo->findOneBy(['commonName' => $name]);
        }
        if (!$country) {
            $country = new Country();
            $country->setOfficialName($name);
            $country->setCommonName($name);
            $country->setIsoCode($isoCode);
            $country->setSigla($sigla);
            $country->setStatus(true);
            $this->em->persist($country);
            $this->em->flush();
        }
        return $country;
    }

    private function loadStatesMap(Country $country): array
    {
        $states = $this->em->getRepository(State::class)->findBy(['country' => $country]);
        $map = [];
        foreach ($states as $s) {
            if ($s->getSigla()) {
                $map[strtoupper($s->getSigla())] = $s;
            }
            $map[strtoupper(DocumentEnrichmentService::normalize($s->getOfficialName()))] = $s;
        }
        return $map;
    }

    private function loadCitiesMap(Country $country): array
    {
        $cities = $this->em->getRepository(City::class)->findBy(['country' => $country]);
        $map = [];
        foreach ($cities as $c) {
            if ($c->getState()) {
                $key = $c->getState()->getId() . '_' . DocumentEnrichmentService::normalize($c->getOfficialName());
                $map[$key] = $c;
            }
        }
        return $map;
    }

    private function loadInstitutionsMap(): array
    {
        $institutions = $this->em->getRepository(Institution::class)->findAll();
        $map = [];
        foreach ($institutions as $inst) {
            $map[DocumentEnrichmentService::normalize($inst->getOfficialName())] = $inst;
            if ($inst->getSigla()) {
                $map[DocumentEnrichmentService::normalize($inst->getSigla())] = $inst;
            }
        }
        $variations = $this->em->getRepository(InstitutionVariation::class)->findAll();
        foreach ($variations as $v) {
            $map[$v->getNormalizedName()] = $v->getInstitution();
        }
        return $map;
    }

    private function syncInstitutionVariations(Institution $inst, array $names): void
    {
        $existing = [];
        foreach ($inst->getVariations() as $v) {
            $existing[$v->getNormalizedName()] = true;
        }

        foreach ($names as $name) {
            $name = trim($name);
            if ($name === '') continue;

            $norm = DocumentEnrichmentService::normalize($name);
            if (!isset($existing[$norm])) {
                $variation = new InstitutionVariation();
                $variation->setVariationName($name);
                $variation->setNormalizedName($norm);
                $variation->setVariationType('official');
                $variation->setInstitution($inst);
                $this->em->persist($variation);
                $existing[$norm] = true;
            }
        }
    }

    private function parseString(mixed $val): ?string
    {
        if ($val === null) return null;
        $str = trim((string) $val);
        return $str !== '' ? $str : null;
    }

    private function parseInt(mixed $val): ?int
    {
        if ($val === null || $val === '') return null;
        if (is_numeric($val)) return (int) $val;
        return null;
    }

    private function parseDate(mixed $val): ?\DateTimeImmutable
    {
        if ($val === null || $val === '') return null;
        if ($val instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($val);
        }
        $str = trim((string) $val);
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $str, $m)) {
            return \DateTimeImmutable::createFromFormat('Y-m-d', "{$m[3]}-{$m[2]}-{$m[1]}") ?: null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $str, $m)) {
            return \DateTimeImmutable::createFromFormat('Y-m-d', "{$m[1]}-{$m[2]}-{$m[3]}") ?: null;
        }
        return null;
    }

    private function formatCoordinate(mixed $val): ?string
    {
        if ($val === null || $val === '') return null;
        $str = trim((string) $val);
        if ($str === '' || $str === '-') return null;

        if (preg_match('/^-?(\d+)\.?(\d*)e\+(\d+)$/i', $str, $matches)) {
            $digits = $matches[1] . $matches[2];
            $sign = str_starts_with($str, '-') ? '-' : '';
            if (strlen($digits) > 2) {
                $str = $sign . substr($digits, 0, 2) . '.' . substr($digits, 2, 10);
            }
        } elseif (is_numeric($str) && !str_contains($str, '.')) {
            $sign = str_starts_with($str, '-') ? '-' : '';
            $num = ltrim($str, '-');
            if (strlen($num) > 2) {
                $str = $sign . substr($num, 0, 2) . '.' . substr($num, 2, 10);
            }
        }
        return substr($str, 0, 50);
    }
}
