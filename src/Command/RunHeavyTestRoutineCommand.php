<?php

namespace App\Command;

use App\Entity\BibliometricProject;
use App\Entity\Dataset;
use App\Entity\User;
use App\Service\Import\DocumentImportService;
use App\Service\Import\DocumentEnrichmentService;
use App\Service\Import\WosImporter;
use App\Service\Analytics\ReportService;
use App\Service\Analytics\ThreeFieldsService;
use App\Service\SlugService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:run-heavy-test-routine',
    description: 'Runs the heavy testing routine: import, sync, report validation, and CSV auditing.',
)]
class RunHeavyTestRoutineCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $conn,
        private readonly DocumentImportService $importService,
        private readonly DocumentEnrichmentService $enrichmentService,
        private readonly ReportService $reportService,
        private readonly ThreeFieldsService $threeFieldsService,
        private readonly SlugService $slugService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('files', InputArgument::IS_ARRAY, 'Files to import and test', []);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title("Iniciando Rotina de Testes Pesado (Multi-Arquivo)...");

        // 1. Encontrar o usuário padrão
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'jonaspoli@gmail.com']);
        if (!$user) {
            $user = $this->em->getRepository(User::class)->findOneBy([]);
        }
        if (!$user) {
            $io->error("Nenhum usuário cadastrado no sistema.");
            return Command::FAILURE;
        }

        $io->text("Usuário selecionado: " . $user->getEmail());

        // 2. Limpar projeto anterior de testes se existir
        $existingProj = $this->em->getRepository(BibliometricProject::class)->findOneBy([
            'title' => 'Rotina de Testes Pesado - Auto'
        ]);
        if ($existingProj) {
            $io->text("Removendo projeto de teste anterior (#" . $existingProj->getId() . ")...");
            $this->deleteProjectData($existingProj->getId());
            $this->em->remove($existingProj);
            $this->em->flush();
            $io->text("Projeto anterior removido com sucesso.");
        }

        // 3. Cadastrar novo projeto
        $project = new BibliometricProject();
        $project->setUser($user);
        $project->setTitle('Rotina de Testes Pesado - Auto');
        $project->setSlug($this->slugService->generate($project->getTitle(), BibliometricProject::class));
        $project->setDescription('Projeto gerado automaticamente pela rotina de testes pesados.');
        $project->setStatus(BibliometricProject::STATUS_DRAFT);
        $this->em->persist($project);
        $this->em->flush();

        $projectId = $project->getId();
        $io->success("Novo projeto criado com ID: " . $projectId);

        // Resolver arquivos de entrada
        $filesInput = $input->getArgument('files');
        if (empty($filesInput)) {
            $filesInput = ['savedrecs.txt', 'savedrecs02.txt', 'savedrecs03.txt'];
        }

        $filePaths = [];
        foreach ($filesInput as $f) {
            if (str_starts_with($f, '/')) {
                $path = $f;
            } else {
                $path = '/Volumes/Dados/work/bibliomap/docs/temp/pesquisas/' . $f;
            }
            if (!file_exists($path)) {
                $io->error("Arquivo de pesquisa não encontrado em: " . $path);
                return Command::FAILURE;
            }
            $filePaths[] = $path;
        }

        $totalStats = ['imported' => 0, 'skipped' => 0, 'errors' => 0];

        // 4 & 5. Para cada arquivo, criar dataset e importar
        $importer = new WosImporter();
        foreach ($filePaths as $filePath) {
            $filename = basename($filePath);
            $io->text("Processando arquivo: " . $filename);

            $dataset = new Dataset();
            $dataset->setProject($project);
            $dataset->setName($filename);
            $dataset->setOriginalFilename($filename);
            $dataset->setFilePath($filePath);
            $dataset->setFileFormat('txt');
            $dataset->setSource('wos');
            $dataset->setStatus(Dataset::STATUS_PENDING);
            $this->em->persist($dataset);
            $this->em->flush();

            $io->text("Iniciando importação de {$filename} via streaming...");
            $stream = $importer->parseStream($filePath);

            $stats = $this->importService->importAll($stream, $dataset, function (array $currentStats) use ($dataset) {
                $dataset->setImportedCount($currentStats['imported']);
                $dataset->setDuplicatedCount($currentStats['skipped']);
                $dataset->setErrorCount($currentStats['errors']);
                $this->em->flush();
            });

            $dataset->setStatus(Dataset::STATUS_IMPORTED);
            $dataset->setImportedAt(new \DateTimeImmutable());
            $this->em->flush();

            $totalStats['imported'] += $stats['imported'];
            $totalStats['skipped']  += $stats['skipped'];
            $totalStats['errors']   += $stats['errors'];

            $io->success(sprintf("Arquivo %s concluído: %d importados, %d duplicados/pulados, %d erros.", $filename, $stats['imported'], $stats['skipped'], $stats['errors']));
        }

        $project->setStatus(BibliometricProject::STATUS_READY);
        $this->em->flush();

        // 6. Análise detalhada dos registros importados vs arquivo original
        $io->section("Auditoria dos Registros Importados...");
        
        $wosRecords = [];
        foreach ($filePaths as $path) {
            $wosRecords = array_merge($wosRecords, $this->parseWosFile($path));
        }
        $totalWosRecords = count($wosRecords);
        $io->text("Total de registros lidos diretamente pelo parser de referência do teste: " . $totalWosRecords);

        // Puxar todos os documentos salvos no banco para este projeto
        $dbDocs = $this->conn->fetchAllAssociative('SELECT * FROM document WHERE project_id = ?', [$projectId]);
        $dbDocsByUt = [];
        $dbDocsByTitle = [];
        foreach ($dbDocs as $doc) {
            if ($doc['external_id']) {
                $dbDocsByUt[$doc['external_id']] = $doc;
            }
            if ($doc['title']) {
                $normTitle = $this->normalizeKey($doc['title']);
                $dbDocsByTitle[$normTitle] = $doc;
            }
        }

        $issues = [];
        $correctImportsCount = 0;

        // Manter registro de UTs, DOIs e títulos já mapeados/processados para ignorar avisos sobre duplicados reais entre arquivos
        $matchedUts = [];
        $matchedDois = [];
        $matchedTitles = [];

        foreach ($wosRecords as $idx => $rawRec) {
            $ut = $rawRec['UT'] ?? null;
            $title = $rawRec['TI'] ?? '';
            $normTitle = $this->normalizeKey($title);
            $fileDoi = $this->normalizeDoi($rawRec['DI'] ?? null);

            // Verificar se é duplicata (já processada nesta rodada de auditoria)
            $isDuplicate = false;
            if ($ut && isset($matchedUts[$ut])) {
                $isDuplicate = true;
            }
            if ($fileDoi && isset($matchedDois[$fileDoi])) {
                $isDuplicate = true;
            }
            if (isset($matchedTitles[$normTitle])) {
                $isDuplicate = true;
            }

            if ($isDuplicate) {
                // Duplicados são pulados pelo sistema de forma correta
                $correctImportsCount++;
                continue;
            }

            $matchedDoc = null;
            if ($ut && isset($dbDocsByUt[$ut])) {
                $matchedDoc = $dbDocsByUt[$ut];
            } elseif (isset($dbDocsByTitle[$normTitle])) {
                $matchedDoc = $dbDocsByTitle[$normTitle];
            }

            if (!$matchedDoc) {
                $issues[] = sprintf("Registro #%d (UT: %s, TI: %s) não foi importado no banco de dados.", $idx + 1, $ut ?? 'N/A', substr($title, 0, 80));
                continue;
            }

            // Marcar como processado
            if ($ut) $matchedUts[$ut] = true;
            if ($fileDoi) $matchedDois[$fileDoi] = true;
            $matchedTitles[$normTitle] = true;

            // Comparar campos individuais
            $mismatches = [];

            // Title comparison
            $dbTitleNorm = $this->normalizeKey($matchedDoc['title'] ?? '');
            if ($normTitle !== $dbTitleNorm) {
                $mismatches[] = sprintf("Título divergente: [F] '%s' vs [DB] '%s'", $title, $matchedDoc['title']);
            }

            // DOI
            $dbDoi = $matchedDoc['doi'];
            if ($fileDoi !== $dbDoi) {
                $mismatches[] = sprintf("DOI divergente: [F] '%s' vs [DB] '%s'", $fileDoi ?? 'NULL', $dbDoi ?? 'NULL');
            }

            // Year
            $fileYear = isset($rawRec['PY']) && ctype_digit($rawRec['PY']) ? (int)$rawRec['PY'] : null;
            $dbYear = $matchedDoc['year'] !== null ? (int)$matchedDoc['year'] : null;
            if ($fileYear !== $dbYear) {
                $mismatches[] = sprintf("Ano divergente: [F] %s vs [DB] %s", $fileYear ?? 'NULL', $dbYear ?? 'NULL');
            }

            // Source/Journal
            $fileSource = $rawRec['SO'] ?? null;
            $dbSource = $matchedDoc['source_title'];
            if ($fileSource && $this->normalizeKey($fileSource) !== $this->normalizeKey($dbSource ?? '')) {
                $mismatches[] = sprintf("Source divergente: [F] '%s' vs [DB] '%s'", $fileSource, $dbSource);
            }

            // ISSN / ISBN
            $fileIssn = $rawRec['SN'] ?? null;
            $dbIssn = $matchedDoc['issn'];
            if ($fileIssn !== $dbIssn) {
                $mismatches[] = sprintf("ISSN divergente: [F] '%s' vs [DB] '%s'", $fileIssn ?? 'NULL', $dbIssn ?? 'NULL');
            }

            // Citations
            $fileTc = isset($rawRec['TC']) && ctype_digit($rawRec['TC']) ? (int)$rawRec['TC'] : 0;
            $dbCited = $matchedDoc['cited_by'] !== null ? (int)$matchedDoc['cited_by'] : 0;
            if ($fileTc !== $dbCited) {
                $mismatches[] = sprintf("Citações divergentes: [F] %d vs [DB] %d", $fileTc, $dbCited);
            }

            // Authors check
            $fileAuthors = $rawRec['AF'] ?? $rawRec['AU'] ?? [];
            if (is_string($fileAuthors)) {
                $fileAuthors = [$fileAuthors];
            }
            $fileAuthors = array_values(array_filter(array_map('trim', $fileAuthors)));

            $dbAuthors = $this->conn->fetchAllAssociative(
                'SELECT a.preferred_name, da.original_name, da.position 
                 FROM document_author da
                 JOIN author_identity a ON a.id = da.author_identity_id
                 WHERE da.document_id = ?
                 ORDER BY da.position ASC',
                [$matchedDoc['id']]
            );

            if (count($fileAuthors) !== count($dbAuthors)) {
                $mismatches[] = sprintf("Número de autores divergente: [F] %d vs [DB] %d", count($fileAuthors), count($dbAuthors));
            } else {
                foreach ($fileAuthors as $pos => $fa) {
                    $dbAuth = $dbAuthors[$pos] ?? null;
                    if ($dbAuth) {
                        $faNorm = $this->normalizeKey($fa);
                        $dbOrigNorm = $this->normalizeKey($dbAuth['original_name']);
                        $dbPrefNorm = $this->normalizeKey($dbAuth['preferred_name']);
                        if ($faNorm !== $dbOrigNorm && $faNorm !== $dbPrefNorm) {
                            $mismatches[] = sprintf("Autor na posição %d divergente: [F] '%s' vs [DB-Orig] '%s' / [DB-Pref] '%s'", $pos, $fa, $dbAuth['original_name'], $dbAuth['preferred_name']);
                        }
                    }
                }
            }

            // Keywords check
            $fileKws = [];
            foreach (['DE', 'ID'] as $kwTag) {
                if (!empty($rawRec[$kwTag])) {
                    $vals = is_string($rawRec[$kwTag]) ? explode(';', $rawRec[$kwTag]) : $rawRec[$kwTag];
                    foreach ($vals as $v) {
                        $v = trim($v);
                        if ($v !== '') {
                            $fileKws[] = $this->normalizeKey($v);
                        }
                    }
                }
            }
            $fileKws = array_unique($fileKws);

            $dbKws = $this->conn->fetchAllAssociative(
                'SELECT dk.original_term, k.keyword_display 
                 FROM document_keyword dk
                 JOIN keyword k ON k.id = dk.keyword_id
                 WHERE dk.document_id = ?',
                [$matchedDoc['id']]
            );
            $dbKwKeys = [];
            foreach ($dbKws as $dkw) {
                $dbKwKeys[] = $this->normalizeKey($dkw['original_term']);
                $dbKwKeys[] = $this->normalizeKey($dkw['keyword_display']);
            }
            $dbKwKeys = array_unique($dbKwKeys);

            foreach ($fileKws as $fk) {
                if (!in_array($fk, $dbKwKeys, true)) {
                    $mismatches[] = sprintf("Palavra-chave do arquivo '%s' não encontrada no banco.", $fk);
                }
            }

            if (!empty($mismatches)) {
                $issues[] = sprintf("Divergências no Documento ID %d (UT: %s, TI: %s):\n    - %s", $matchedDoc['id'], $ut ?? 'N/A', substr($title, 0, 50), implode("\n    - ", $mismatches));
            } else {
                $correctImportsCount++;
            }
        }

        $io->text(sprintf("Análise de Importação finalizada. %d documentos testados. %d com sucesso total. %d com inconsistências.", $totalWosRecords, $correctImportsCount, count($issues)));

        // 7. Simular/Rodar sincronização geográfica e institucional
        $io->section("Simulação do Sincronismo (Geográfico e Institucional)...");
        $syncReport = $this->enrichmentService->enrichProject($projectId);
        $io->success("Sincronismo executado via CLI.");

        // Análise minuciosa do sincronismo
        $syncIssues = [];
        $totalDocPaisesCount = (int)$this->conn->fetchOne('SELECT COUNT(*) FROM documento_paises dp JOIN document d ON dp.document_id = d.id WHERE d.project_id = ?', [$projectId]);
        $totalDocInstsCount = (int)$this->conn->fetchOne('SELECT COUNT(*) FROM documento_instituicoes di JOIN document d ON di.document_id = d.id WHERE d.project_id = ?', [$projectId]);

        $io->text("Total de ligações Documento-País criadas: " . $totalDocPaisesCount);
        $io->text("Total de ligações Documento-Instituição criadas: " . $totalDocInstsCount);

        // Validando ligações
        $docAffiliations = $this->conn->fetchAllAssociative('
            SELECT id, countries, institutions, title 
            FROM document 
            WHERE project_id = ? AND (countries IS NOT NULL OR institutions IS NOT NULL)', 
            [$projectId]
        );

        foreach ($docAffiliations as $docAff) {
            $docId = (int)$docAff['id'];
            $rawCountries = $docAff['countries'] ? json_decode($docAff['countries'], true) : [];
            $rawInsts = $docAff['institutions'] ? json_decode($docAff['institutions'], true) : [];

            foreach ($rawCountries as $rc) {
                $expectedCountryId = $this->getCountryIdByRawName($rc);
                if ($expectedCountryId !== null) {
                    $isLinked = $this->conn->fetchOne(
                        'SELECT 1 FROM documento_paises WHERE document_id = ? AND country_id = ? LIMIT 1',
                        [$docId, $expectedCountryId]
                    );
                    if (!$isLinked) {
                        $syncIssues[] = sprintf("Documento #%d (TI: %s) possui país '%s' no arquivo, mas a ligação no banco para o ID %d não foi criada.", $docId, substr($docAff['title'], 0, 50), $rc, $expectedCountryId);
                    }
                }
            }

            foreach ($rawInsts as $ri) {
                $expectedInstId = $this->getInstitutionIdByRawName($ri);
                if ($expectedInstId !== null) {
                    $isLinked = $this->conn->fetchOne(
                        'SELECT 1 FROM documento_instituicoes WHERE document_id = ? AND institution_id = ? LIMIT 1',
                        [$docId, $expectedInstId]
                    );
                    if (!$isLinked) {
                        $syncIssues[] = sprintf("Documento #%d (TI: %s) possui instituição '%s' no arquivo, mas a ligação no banco para o ID %d não foi criada.", $docId, substr($docAff['title'], 0, 50), $ri, $expectedInstId);
                    }
                }
            }
        }

        $io->text("Inconsistências no sincronismo identificadas: " . count($syncIssues));

        // 8. Relatórios e Sub-relatórios: Geração de outra forma e comparação
        $io->section("Auditoria dos Relatórios de Métricas...");
        $reportIssues = [];

        // A. Relatório de Autores
        $systemAuthors = $this->reportService->getAuthorsReport($projectId, 100);
        $independentAuthors = $this->generateIndependentAuthorsReport($projectId);
        $reportIssues = array_merge($reportIssues, $this->compareAuthorsReports($systemAuthors['list'], $independentAuthors));

        // B. Relatório de Fontes
        $systemSources = $this->reportService->getSourcesReport($projectId, 100);
        $independentSources = $this->generateIndependentSourcesReport($projectId);
        $reportIssues = array_merge($reportIssues, $this->compareSourcesReports($systemSources['list'], $independentSources));

        // C. Relatório de Países
        $systemCountries = $this->reportService->getCountriesReport($projectId);
        $independentCountries = $this->generateIndependentCountriesReport($projectId);
        $reportIssues = array_merge($reportIssues, $this->compareCountriesReports($systemCountries['list'] ?? $systemCountries, $independentCountries));

        // D. Relatório de Instituições
        $systemInsts = $this->reportService->getInstitutionsReport($projectId);
        $independentInsts = $this->generateIndependentInstitutionsReport($projectId);
        $reportIssues = array_merge($reportIssues, $this->compareInstitutionsReports($systemInsts['list'] ?? $systemInsts, $independentInsts));

        // E. Relatório de Palavras-Chave
        $systemKws = $this->reportService->getKeywordsReport($projectId, 150);
        $independentKws = $this->generateIndependentKeywordsReport($projectId);
        $reportIssues = array_merge($reportIssues, $this->compareKeywordsReports($systemKws['list'] ?? $systemKws, $independentKws));

        $io->text("Problemas identificados nos relatórios do sistema: " . count($reportIssues));

        // 9. Auditoria de Exportação de CSV
        $io->section("Auditoria de Exportação de CSV...");
        $csvIssues = $this->auditCsvExports($projectId, $filePaths);
        $io->text("Problemas identificados nos CSVs exportados: " . count($csvIssues));

        // 10. Escrever Relatório Markdown
        $reportPath = '/Volumes/Dados/work/bibliomap/docs/temp/relatorio_testes_pesado.md';
        $this->writeMarkdownReport($reportPath, $projectId, $totalStats, $issues, $syncIssues, $reportIssues, $csvIssues);
        $io->success("Relatório de Testes Pesado gerado com sucesso em: " . $reportPath);

        // Se houver problemas, retorna falha, caso contrário sucesso
        $totalProblems = count($issues) + count($syncIssues) + count($reportIssues) + count($csvIssues);
        if ($totalProblems > 0) {
            $io->error(sprintf("Testes finalizados com %d falhas / inconsistências. Veja o relatório para mais detalhes.", $totalProblems));
            return Command::FAILURE;
        }

        $io->success("Parabéns! Sistema 100% correto, sem discrepâncias encontradas!");
        return Command::SUCCESS;
    }

    // ── Helper Parser ────────────────────────────────────────────────────────

    private function parseWosFile(string $filePath): array
    {
        $handle = fopen($filePath, 'rb');
        $records = [];
        $record = [];
        $currentTag = null;
        $inRecord = false;
        $arrayTags = ['AU', 'AF', 'C1', 'C3', 'CR', 'WC', 'SC', 'SP', 'BE'];

        while (($raw = fgets($handle)) !== false) {
            $line = ltrim($raw, "\xEF\xBB\xBF");
            $line = rtrim($line, "\r\n");

            if (!$inRecord) {
                if (str_starts_with($line, 'PT ') || str_starts_with($line, 'PT\t')) {
                    $inRecord = true;
                    $record = [];
                    $currentTag = 'PT';
                    $record['PT'] = trim(substr($line, 3));
                }
                continue;
            }

            if ($line === 'ER' || $line === 'ER ') {
                $records[] = $record;
                $record = [];
                $currentTag = null;
                $inRecord = false;
                continue;
            }

            if (isset($line[0]) && ($line[0] === ' ' || $line[0] === "\t") && $currentTag !== null) {
                $value = trim($line);
                if ($value === '') continue;
                if (in_array($currentTag, $arrayTags, true)) {
                    $record[$currentTag][] = $value;
                } else {
                    $record[$currentTag] = ($record[$currentTag] ?? '') . ' ' . $value;
                }
                continue;
            }

            if (strlen($line) >= 2 && $line[2] === ' ') {
                $tag = substr($line, 0, 2);
                $value = trim(substr($line, 3));

                if ($tag === 'EF') {
                    break;
                }

                $currentTag = $tag;

                if (in_array($tag, $arrayTags, true)) {
                    if (!isset($record[$tag])) {
                        $record[$tag] = [];
                    }
                    if ($value !== '') {
                        $record[$tag][] = $value;
                    }
                } else {
                    $record[$tag] = $value;
                }
                continue;
            }
        }

        if (!empty($record)) {
            $records[] = $record;
        }

        fclose($handle);
        return $records;
    }

    private function deleteProjectData(int $projectId): void
    {
        $conn = $this->conn;
        $conn->executeStatement('DELETE FROM dataset_skip WHERE dataset_id IN (SELECT id FROM dataset WHERE project_id = ?)', [$projectId]);
        $conn->executeStatement('DELETE FROM document_author WHERE document_id IN (SELECT id FROM document WHERE project_id = ?)', [$projectId]);
        $conn->executeStatement('DELETE FROM document_keyword WHERE document_id IN (SELECT id FROM document WHERE project_id = ?)', [$projectId]);
        $conn->executeStatement('DELETE FROM documento_instituicoes WHERE document_id IN (SELECT id FROM document WHERE project_id = ?)', [$projectId]);
        $conn->executeStatement('DELETE FROM documento_paises WHERE document_id IN (SELECT id FROM document WHERE project_id = ?)', [$projectId]);
        $conn->executeStatement('DELETE FROM documento_estados WHERE document_id IN (SELECT id FROM document WHERE project_id = ?)', [$projectId]);
        $conn->executeStatement('DELETE FROM documento_cidades WHERE document_id IN (SELECT id FROM document WHERE project_id = ?)', [$projectId]);
        $conn->executeStatement('DELETE FROM document WHERE project_id = ?', [$projectId]);
        $conn->executeStatement('DELETE FROM dataset WHERE project_id = ?', [$projectId]);
    }

    private function normalizeKey(string $val): string
    {
        $val = html_entity_decode($val, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $val = strip_tags($val);
        $val = mb_strtolower($val, 'UTF-8');
        $val = transliterator_transliterate('NFD; [:Nonspacing Mark:] Remove; NFC', $val);
        $val = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $val);
        return trim(preg_replace('/\s+/u', ' ', $val));
    }

    private function normalizeDoi(?string $doi): ?string
    {
        if (!$doi) return null;
        $doi = trim($doi);
        $doi = preg_replace('#^https?://doi\.org/#i', '', $doi);
        return $doi !== '' ? $doi : null;
    }

    private function isUsLocationToken(string $val): bool
    {
        $val = trim($val);
        if (preg_match('/^([A-Z]{2})\s+\d{5}(-\d{4})?$/i', $val)) {
            return true;
        }
        $states = [
            'AL','AK','AZ','AR','CA','CO','CT','DE','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA','ME','MD','MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH','OK','OR','PA','RI','SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY',
            'Alabama','Alaska','Arizona','Arkansas','California','Colorado','Connecticut','Delaware','Florida','Georgia','Hawaii','Idaho','Illinois','Indiana','Iowa','Kansas','Kentucky','Louisiana','Maine','Maryland','Massachusetts','Michigan','Minnesota','Mississippi','Missouri','Montana','Nebraska','Nevada','New Hampshire','New Jersey','New Mexico','New York','North Carolina','North Dakota','Ohio','Oklahoma','Oregon','Pennsylvania','Rhode Island','South Carolina','South Dakota','Tennessee','Texas','Utah','Vermont','Virginia','Washington','West Virginia','Wisconsin','Wyoming'
        ];
        foreach ($states as $state) {
            if (strcasecmp($val, $state) === 0 || str_ends_with(strtolower($val), ' ' . strtolower($state))) {
                return true;
            }
        }
        return false;
    }

    private function getCountryIdByRawName(string $name): ?int
    {
        $norm = $this->normalizeKey($name);
        
        $id = $this->conn->fetchOne(
            'SELECT id FROM paises WHERE LOWER(official_name) = ? OR LOWER(common_name) = ? OR LOWER(sigla) = ?',
            [$norm, $norm, $norm]
        );
        if ($id) return (int)$id;

        $id = $this->conn->fetchOne(
            'SELECT country_id FROM pais_variacoes_nome WHERE normalized_name = ?',
            [$norm]
        );
        return $id !== false ? (int)$id : null;
    }

    private function getInstitutionIdByRawName(string $name): ?int
    {
        $norm = $this->normalizeKey($name);

        $id = $this->conn->fetchOne(
            'SELECT id FROM instituicoes_ensino WHERE LOWER(official_name) = ? OR LOWER(short_name) = ? OR LOWER(sigla) = ?',
            [$norm, $norm, $norm]
        );
        if ($id) return (int)$id;

        $id = $this->conn->fetchOne(
            'SELECT institution_id FROM instituicao_variacoes_nome WHERE normalized_name = ?',
            [$norm]
        );
        return $id !== false ? (int)$id : null;
    }

    // ── Independent Reports Generation ───────────────────────────────────────

    private function generateIndependentAuthorsReport(int $projectId): array
    {
        return $this->conn->fetchAllAssociative(
            'SELECT a.id, a.preferred_name AS name,
                    COUNT(DISTINCT da.document_id) AS doc_count,
                    SUM(COALESCE(d.cited_by, 0))   AS citation_count
             FROM author_identity a
             JOIN document_author da ON a.id = da.author_identity_id
             JOIN document d         ON d.id = da.document_id AND d.project_id = ?
             GROUP BY a.id, a.preferred_name
             ORDER BY doc_count DESC, citation_count DESC',
            [$projectId]
        );
    }

    private function generateIndependentSourcesReport(int $projectId): array
    {
        return $this->conn->fetchAllAssociative(
            'SELECT source_title, 
                    COUNT(*) AS doc_count,
                    SUM(COALESCE(cited_by, 0)) AS citation_count
             FROM document
             WHERE project_id = ? AND source_title IS NOT NULL AND source_title != \'\'
             GROUP BY source_title
             ORDER BY doc_count DESC, citation_count DESC',
            [$projectId]
        );
    }

    private function generateIndependentCountriesReport(int $projectId): array
    {
        return $this->conn->fetchAllAssociative(
            'SELECT p.common_name AS country,
                    COUNT(DISTINCT dp.document_id) AS doc_count,
                    SUM(COALESCE(d.cited_by, 0)) AS citation_count
             FROM paises p
             JOIN documento_paises dp ON p.id = dp.country_id
             JOIN document d ON dp.document_id = d.id AND d.project_id = ?
             GROUP BY p.id, p.common_name
             ORDER BY doc_count DESC, citation_count DESC',
            [$projectId]
        );
    }

    private function generateIndependentInstitutionsReport(int $projectId): array
    {
        return $this->conn->fetchAllAssociative(
            'SELECT i.official_name AS institution,
                    COUNT(DISTINCT di.document_id) AS doc_count,
                    SUM(COALESCE(d.cited_by, 0)) AS citation_count
             FROM instituicoes_ensino i
             JOIN documento_instituicoes di ON i.id = di.institution_id
             JOIN document d ON di.document_id = d.id AND d.project_id = ?
             GROUP BY i.id, i.official_name
             ORDER BY doc_count DESC, citation_count DESC',
            [$projectId]
        );
    }

    private function generateIndependentKeywordsReport(int $projectId): array
    {
        return $this->conn->fetchAllAssociative(
            'SELECT COALESCE(tc.preferred_label, kc.keyword_display, k.keyword_display) AS term,
                    CASE WHEN k.keyword_type = \'author_keyword\' THEN \'author\' 
                         WHEN k.keyword_type = \'indexed_keyword\' THEN \'indexed\' 
                         ELSE k.keyword_type 
                    END AS type,
                    COUNT(DISTINCT dk.document_id) AS freq
             FROM keyword k
             LEFT JOIN thesaurus_concept tc ON tc.id = k.thesaurus_concept_id
             LEFT JOIN keyword kc ON k.keyword_concept_id = kc.id
             JOIN document_keyword dk ON dk.keyword_id = k.id
             JOIN document d ON d.id = dk.document_id AND d.project_id = ?
             GROUP BY term, type
             ORDER BY freq DESC',
            [$projectId]
        );
    }

    // ── Comparisons ──────────────────────────────────────────────────────────

    private function compareAuthorsReports(array $system, array $independent): array
    {
        $issues = [];
        $indMap = [];
        foreach ($independent as $row) {
            $indMap[$row['id']] = $row;
        }

        foreach ($system as $sysRow) {
            $id = $sysRow['id'];
            if (!isset($indMap[$id])) {
                $issues[] = sprintf("Autor '%s' (ID %d) presente no relatório do sistema mas não no independente.", $sysRow['name'], $id);
                continue;
            }
            $indRow = $indMap[$id];
            if ((int)$sysRow['doc_count'] !== (int)$indRow['doc_count']) {
                $issues[] = sprintf("Autor '%s' doc_count divergente: [S] %d vs [I] %d", $sysRow['name'], $sysRow['doc_count'], $indRow['doc_count']);
            }
            if ((int)$sysRow['citation_count'] !== (int)$indRow['citation_count']) {
                $issues[] = sprintf("Autor '%s' citation_count divergente: [S] %d vs [I] %d", $sysRow['name'], $sysRow['citation_count'], $indRow['citation_count']);
            }
        }
        return $issues;
    }

    private function compareSourcesReports(array $system, array $independent): array
    {
        $issues = [];
        $indMap = [];
        foreach ($independent as $row) {
            $indMap[$this->normalizeKey($row['source_title'])] = $row;
        }

        foreach ($system as $sysRow) {
            $key = $this->normalizeKey($sysRow['source_title']);
            if (!isset($indMap[$key])) {
                $issues[] = sprintf("Fonte '%s' presente no relatório do sistema mas não no independente.", $sysRow['source_title']);
                continue;
            }
            $indRow = $indMap[$key];
            if ((int)$sysRow['doc_count'] !== (int)$indRow['doc_count']) {
                $issues[] = sprintf("Fonte '%s' doc_count divergente: [S] %d vs [I] %d", $sysRow['source_title'], $sysRow['doc_count'], $indRow['doc_count']);
            }
            if ((int)$sysRow['citation_count'] !== (int)$indRow['citation_count']) {
                $issues[] = sprintf("Fonte '%s' citation_count divergente: [S] %d vs [I] %d", $sysRow['source_title'], $sysRow['citation_count'], $indRow['citation_count']);
            }
        }
        return $issues;
    }

    private function compareCountriesReports(array $system, array $independent): array
    {
        $issues = [];
        $indMap = [];
        foreach ($independent as $row) {
            $indMap[$this->normalizeKey($row['country'])] = $row;
        }

        foreach ($system as $sysRow) {
            $key = $this->normalizeKey($sysRow['country'] ?? $sysRow['name']);
            if (!isset($indMap[$key])) {
                $issues[] = sprintf("País '%s' presente no relatório do sistema mas não no independente.", $sysRow['country'] ?? $sysRow['name']);
                continue;
            }
            $indRow = $indMap[$key];
            if ((int)$sysRow['doc_count'] !== (int)$indRow['doc_count']) {
                $issues[] = sprintf("País '%s' doc_count divergente: [S] %d vs [I] %d", $sysRow['country'] ?? $sysRow['name'], $sysRow['doc_count'], $indRow['doc_count']);
            }
        }
        return $issues;
    }

    private function compareInstitutionsReports(array $system, array $independent): array
    {
        $issues = [];
        $indMap = [];
        foreach ($independent as $row) {
            $indMap[$this->normalizeKey($row['institution'])] = $row;
        }

        foreach ($system as $sysRow) {
            $key = $this->normalizeKey($sysRow['institution'] ?? $sysRow['name']);
            if (!isset($indMap[$key])) {
                $issues[] = sprintf("Instituição '%s' presente no relatório do sistema mas não no independente.", $sysRow['institution'] ?? $sysRow['name']);
                continue;
            }
            $indRow = $indMap[$key];
            if ((int)$sysRow['doc_count'] !== (int)$indRow['doc_count']) {
                $issues[] = sprintf("Instituição '%s' doc_count divergente: [S] %d vs [I] %d", $sysRow['institution'] ?? $sysRow['name'], $sysRow['doc_count'], $indRow['doc_count']);
            }
        }
        return $issues;
    }

    private function compareKeywordsReports(array $system, array $independent): array
    {
        $issues = [];
        $indMap = [];
        foreach ($independent as $row) {
            $key = $this->normalizeKey($row['term']) . '|' . $row['type'];
            $indMap[$key] = $row;
        }

        foreach ($system as $sysRow) {
            $key = $this->normalizeKey($sysRow['term']) . '|' . $sysRow['type'];
            if (!isset($indMap[$key])) {
                $issues[] = sprintf("Palavra-chave '%s' (%s) presente no relatório do sistema mas não no independente.", $sysRow['term'], $sysRow['type']);
                continue;
            }
            $indRow = $indMap[$key];
            if ((int)($sysRow['freq'] ?? $sysRow['count']) !== (int)$indRow['freq']) {
                $issues[] = sprintf("Palavra-chave '%s' (%s) frequência divergente: [S] %d vs [I] %d", $sysRow['term'], $sysRow['type'], ($sysRow['freq'] ?? $sysRow['count']), $indRow['freq']);
            }
        }
        return $issues;
    }

    // ── CSV Exporter Auditing ────────────────────────────────────────────────

    private function auditCsvExports(int $projectId, array $filePaths): array
    {
        $issues = [];
        
        $docsReport = $this->reportService->getDocumentsReport($projectId, 500);
        $csvRows = [];
        foreach ($docsReport['list'] as $d) {
            $csvRows[] = [
                'title' => $d['title'],
                'authors' => $d['authors_str'],
                'year' => $d['year'],
                'source' => $d['source_title'],
                'cited_by' => $d['cited_by'],
                'doi' => $d['doi']
            ];
        }

        $wosRecords = [];
        foreach ($filePaths as $path) {
            $wosRecords = array_merge($wosRecords, $this->parseWosFile($path));
        }

        $wosMap = [];
        foreach ($wosRecords as $wr) {
            $titleNorm = $this->normalizeKey($wr['TI'] ?? '');
            $wosMap[$titleNorm] = $wr;
        }

        foreach ($csvRows as $csvRow) {
            $csvTitleNorm = $this->normalizeKey($csvRow['title']);
            if (!isset($wosMap[$csvTitleNorm])) {
                $issues[] = sprintf("Documento CSV '%s' não pôde ser rastreado no arquivo original.", $csvRow['title']);
                continue;
            }
            $wr = $wosMap[$csvTitleNorm];
            $fileTc = isset($wr['TC']) && ctype_digit($wr['TC']) ? (int)$wr['TC'] : 0;
            if ((int)$csvRow['cited_by'] !== $fileTc) {
                $issues[] = sprintf("Documento CSV '%s' cited_by diverge: [CSV] %d vs [File] %d", $csvRow['title'], $csvRow['cited_by'], $fileTc);
            }
            $fileDoi = $this->normalizeDoi($wr['DI'] ?? null);
            if ($csvRow['doi'] !== $fileDoi) {
                $issues[] = sprintf("Documento CSV '%s' DOI diverge: [CSV] '%s' vs [File] '%s'", $csvRow['title'], $csvRow['doi'], $fileDoi);
            }
        }

        return $issues;
    }

    // ── Report Writer ────────────────────────────────────────────────────────

    private function writeMarkdownReport(
        string $reportPath,
        int $projectId,
        array $stats,
        array $issues,
        array $syncIssues,
        array $reportIssues,
        array $csvIssues
    ): void {
        $content = "# Relatório da Rotina de Testes Pesado\n\n";
        $content .= "Gerado em: " . (new \DateTimeImmutable())->format('Y-m-d H:i:s') . "\n";
        $content .= "Projeto de Teste ID: #" . $projectId . "\n\n";

        $content .= "## 1. Estatísticas de Importação\n";
        $content .= sprintf("- Total lidos: %d\n", $stats['imported'] + $stats['skipped'] + $stats['errors']);
        $content .= sprintf("- Importados com sucesso: %d\n", $stats['imported']);
        $content .= sprintf("- Duplicados pulados: %d\n", $stats['skipped']);
        $content .= sprintf("- Erros durante a importação: %d\n\n", $stats['errors']);

        $content .= "## 2. Auditoria de Dados Importados (File vs Database)\n";
        if (empty($issues)) {
            $content .= "> [NOTE]\n> 100% dos dados importados estão idênticos aos do arquivo original!\n\n";
        } else {
            $content .= sprintf("Foram encontradas %d divergências:\n\n", count($issues));
            foreach ($issues as $issue) {
                $content .= "- " . str_replace("\n", "\n  ", $issue) . "\n";
            }
            $content .= "\n";
        }

        $content .= "## 3. Auditoria do Sincronismo (Vínculo Geográfico e Institucional)\n";
        if (empty($syncIssues)) {
            $content .= "> [NOTE]\n> Sincronismo funcionando corretamente. Todas as instituições e países foram validados.\n\n";
        } else {
            $content .= sprintf("Foram encontradas %d falhas de sincronismo:\n\n", count($syncIssues));
            foreach ($syncIssues as $sIssue) {
                $content .= "- " . $sIssue . "\n";
            }
            $content .= "\n";
        }

        $content .= "## 4. Auditoria de Relatórios de Métricas\n";
        if (empty($reportIssues)) {
            $content .= "> [NOTE]\n> Todos os relatórios gerados pelo sistema conferem com os calculados de forma independente.\n\n";
        } else {
            $content .= sprintf("Foram encontradas %d discrepâncias nos relatórios:\n\n", count($reportIssues));
            foreach ($reportIssues as $rIssue) {
                $content .= "- " . $rIssue . "\n";
            }
            $content .= "\n";
        }

        $content .= "## 5. Auditoria de Exportação de CSV\n";
        if (empty($csvIssues)) {
            $content .= "> [NOTE]\n> Todos os dados apresentados no CSV exportado conferem perfeitamente com os do arquivo original.\n\n";
        } else {
            $content .= sprintf("Foram encontradas %d discrepâncias nos CSVs:\n\n", count($csvIssues));
            foreach ($csvIssues as $cIssue) {
                $content .= "- " . $cIssue . "\n";
            }
            $content .= "\n";
        }

        file_put_contents($reportPath, $content);
    }
}
