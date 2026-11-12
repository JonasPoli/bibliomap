<?php

namespace App\Controller;

use App\Entity\AcademicDatabase;
use App\Entity\QualisJournal;
use App\Entity\JournalVariation;
use App\Service\Import\DocumentEnrichmentService;
use App\Service\Import\StringNormalizer;
use App\Service\Qualis\QualisImporterService;
use App\Service\Thesaurus\ThesaurusFileService;
use App\Service\Thesaurus\EntityMergeService;
use Doctrine\ORM\EntityManagerInterface;
use League\Csv\Writer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/journals')]
#[IsGranted('ROLE_ADMIN')]
class AdminJournalController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ThesaurusFileService $thesaurusService,
        private readonly EntityMergeService $mergeService,
    ) {}

    #[Route('', name: 'app_admin_journals_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $search = trim($request->query->getString('search', ''));
        $normSearch = StringNormalizer::normalizeString($search, true);
        $qualis = $request->query->getString('qualis', 'all');
        $page = max(1, $request->query->getInt('page', 1));
        $limit = 100;
        $offset = ($page - 1) * $limit;

        $sort = $request->query->getString('sort', 'title');
        $direction = strtoupper($request->query->getString('direction', 'ASC'));
        if (!in_array($direction, ['ASC', 'DESC'])) {
            $direction = 'ASC';
        }

        $allowedSortFields = [
            'title' => 'q.title',
            'issn' => 'q.issn',
            'qualis' => 'q.qualis',
        ];
        $sortField = $allowedSortFields[$sort] ?? 'q.title';

        // Só a página: ids primeiro (junção das variações apenas com busca), contagem e entidades com as bases.
        $qb = $this->em->createQueryBuilder()->from(QualisJournal::class, 'q');

        if ($search !== '') {
            $qb->leftJoin('q.variations', 'v')
               ->andWhere('q.title LIKE :search OR q.issn LIKE :search OR q.qualis LIKE :search OR v.variationName LIKE :search OR v.normalizedName LIKE :normSearch')
               ->setParameter('search', '%' . $search . '%')
               ->setParameter('normSearch', '%' . $normSearch . '%');
        }

        if ($qualis !== 'all' && $qualis !== '') {
            $qb->andWhere('q.qualis = :qualis')
               ->setParameter('qualis', $qualis);
        }

        $totalResults = (int) (clone $qb)->select('COUNT(DISTINCT q.id)')->getQuery()->getSingleScalarResult();
        $totalPages = max(1, (int) ceil($totalResults / $limit));

        $idsQb = (clone $qb)->select('q.id', $sortField)
            ->orderBy($sortField, $direction)->addOrderBy('q.id', 'ASC')
            ->setFirstResult($offset)->setMaxResults($limit);
        if ($search !== '') {
            $idsQb->distinct();
        }
        $ids = array_map('intval', array_column($idsQb->getQuery()->getScalarResult(), 'id'));

        $journals = [];
        if ($ids !== []) {
            $byId = [];
            foreach ($this->em->createQueryBuilder()
                ->select('q', 'db')->from(QualisJournal::class, 'q')
                ->leftJoin('q.academicDatabases', 'db')
                ->where('q.id IN (:ids)')->setParameter('ids', $ids)
                ->getQuery()->getResult() as $entity) {
                $byId[$entity->getId()] = $entity;
            }
            foreach ($ids as $id) {
                if (isset($byId[$id])) {
                    $journals[] = $byId[$id];
                }
            }
        }

        return $this->render('admin/journals/index.html.twig', [
            'journals' => $journals,
            'search' => $search,
            'qualis' => $qualis,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalResults' => $totalResults,
            'currentSort' => $sort,
            'currentDirection' => $direction,
        ]);
    }

    #[Route('/new', name: 'app_admin_journals_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $journal = new QualisJournal();
        if ($request->isMethod('GET')) {
            $journal->setTitle($request->query->getString('title', ''));
            $journal->setIssn($request->query->getString('issn', ''));
        }

        if ($request->isMethod('POST')) {
            $token = $request->request->get('_token');
            if (!$this->isCsrfTokenValid('journal_new', $token)) {
                $this->addFlash('danger', 'Token CSRF inválido.');
                return $this->redirectToRoute('app_admin_journals_new');
            }

            $title = trim($request->request->getString('title', ''));
            $issn = trim($request->request->getString('issn', ''));
            $qualisVal = strtoupper(trim($request->request->getString('qualis', '')));

            if ($title === '' || $issn === '') {
                $this->addFlash('danger', 'Título e ISSN são campos obrigatórios.');
                return $this->render('admin/journals/new.html.twig', [
                    'journal' => $journal,
                    'databases' => $this->em->getRepository(AcademicDatabase::class)->findBy([], ['name' => 'ASC'])
                ]);
            }

            $normalizedIssn = strtolower(str_replace(['-', ' '], '', $issn));

            $existing = $this->em->getRepository(QualisJournal::class)->findOneBy(['normalizedIssn' => $normalizedIssn]);
            if ($existing) {
                $this->addFlash('danger', "Já existe um periódico cadastrado com o ISSN {$issn}.");
                return $this->render('admin/journals/new.html.twig', [
                    'journal' => $journal,
                    'databases' => $this->em->getRepository(AcademicDatabase::class)->findBy([], ['name' => 'ASC'])
                ]);
            }

            $journal->setTitle($title);
            $journal->setIssn($issn);
            $journal->setNormalizedIssn($normalizedIssn);
            $journal->setQualis($qualisVal !== '' ? $qualisVal : null);

            $selectedDbIds = $request->request->all()['academic_databases'] ?? [];
            if (is_array($selectedDbIds)) {
                foreach ($selectedDbIds as $dbId) {
                    $db = $this->em->find(AcademicDatabase::class, (int)$dbId);
                    if ($db) {
                        $journal->addAcademicDatabase($db);
                    }
                }
            }

            $this->em->persist($journal);
            $this->em->flush();

            $this->addFlash('success', 'Periódico cadastrado com sucesso!');
            return $this->redirectToRoute('app_admin_journals_index');
        }

        return $this->render('admin/journals/new.html.twig', [
            'journal' => $journal,
            'databases' => $this->em->getRepository(AcademicDatabase::class)->findBy([], ['name' => 'ASC'])
        ]);
    }

    #[Route('/{id}/sucupira', name: 'app_admin_journals_sucupira_lookup', methods: ['GET'])]
    public function sucupiraLookup(int $id, \App\Service\Qualis\SucupiraScraperService $scraper): Response
    {
        $journal = $this->em->find(QualisJournal::class, $id);
        if (!$journal) {
            throw $this->createNotFoundException('Periódico não encontrado.');
        }

        $data = $scraper->fetchDetailedRows($journal->getIssn());

        return $this->render('admin/journals/sucupira_lookup.html.twig', [
            'journal' => $journal,
            'rows' => $data['rows'] ?? [],
            'jsessionid' => $data['jsessionid'] ?? null,
            'viewstate' => $data['viewstate'] ?? null,
            'eventid' => $data['eventid'] ?? '237'
        ]);
    }

    #[Route('/{id}/edit', name: 'app_admin_journals_edit', methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request): Response
    {
        $journal = $this->em->find(QualisJournal::class, $id);
        if (!$journal) {
            throw $this->createNotFoundException('Periódico não encontrado.');
        }

        if ($request->isMethod('POST')) {
            $token = $request->request->get('_token');
            if (!$this->isCsrfTokenValid('journal_edit', $token)) {
                $this->addFlash('danger', 'Token CSRF inválido.');
                return $this->redirectToRoute('app_admin_journals_edit', ['id' => $id]);
            }

            $title = trim($request->request->getString('title', ''));
            $issn = trim($request->request->getString('issn', ''));
            $qualisVal = strtoupper(trim($request->request->getString('qualis', '')));

            if ($title === '' || $issn === '') {
                $this->addFlash('danger', 'Título e ISSN são campos obrigatórios.');
                return $this->render('admin/journals/edit.html.twig', [
                    'journal' => $journal,
                    'databases' => $this->em->getRepository(AcademicDatabase::class)->findBy([], ['name' => 'ASC']),
                    'variationsText' => $request->request->getString('variations', ''),
                ]);
            }

            $normalizedIssn = strtolower(str_replace(['-', ' '], '', $issn));

            if ($normalizedIssn !== $journal->getNormalizedIssn()) {
                $existing = $this->em->getRepository(QualisJournal::class)->findOneBy(['normalizedIssn' => $normalizedIssn]);
                if ($existing) {
                    $this->addFlash('danger', "Já existe outro periódico cadastrado com o ISSN {$issn}.");
                    return $this->render('admin/journals/edit.html.twig', [
                        'journal' => $journal,
                        'databases' => $this->em->getRepository(AcademicDatabase::class)->findBy([], ['name' => 'ASC']),
                        'variationsText' => $request->request->getString('variations', ''),
                    ]);
                }
            }

            $journal->setTitle($title);
            $journal->setIssn($issn);
            $journal->setNormalizedIssn($normalizedIssn);
            $journal->setQualis($qualisVal !== '' ? $qualisVal : null);

            $journal->getAcademicDatabases()->clear();
            $selectedDbIds = $request->request->all()['academic_databases'] ?? [];
            if (is_array($selectedDbIds)) {
                foreach ($selectedDbIds as $dbId) {
                    $db = $this->em->find(AcademicDatabase::class, (int)$dbId);
                    if ($db) {
                        $journal->addAcademicDatabase($db);
                    }
                }
            }

            // Sync variations text
            $variationsText = $request->request->getString('variations', '');
            $lines = array_filter(array_map('trim', explode("\n", $variationsText)));
            $submittedNorms = [];

            foreach ($lines as $lineName) {
                $normName = DocumentEnrichmentService::normalize($lineName);
                if ($normName === '') continue;

                $submittedNorms[$normName] = $lineName;
            }

            // Remove existing variations omitted in post
            foreach ($journal->getVariations() as $existingVar) {
                if (!isset($submittedNorms[$existingVar->getNormalizedName()])) {
                    $journal->removeVariation($existingVar);
                } else {
                    unset($submittedNorms[$existingVar->getNormalizedName()]);
                }
            }

            // Add remaining new submitted variations
            foreach ($submittedNorms as $normName => $origName) {
                $var = new JournalVariation();
                $var->setVariationName($origName);
                $var->setNormalizedName($normName);
                $var->setVariationType('alternative');
                $journal->addVariation($var);
            }

            $this->em->flush();

            // Os relatórios leem document.qualis: o estrato editado vai para os documentos da revista (todos os projetos)
            $this->em->getConnection()->executeStatement(
                'UPDATE document SET qualis = ? WHERE qualis_journal_id = ? AND NOT (qualis <=> ?)',
                [$journal->getQualis(), $journal->getId(), $journal->getQualis()]
            );

            $this->addFlash('success', 'Periódico atualizado com sucesso!');
            return $this->redirectToRoute('app_admin_journals_index');
        }

        $variationsLines = [];
        foreach ($journal->getVariations() as $v) {
            $variationsLines[] = $v->getVariationName();
        }

        return $this->render('admin/journals/edit.html.twig', [
            'journal' => $journal,
            'databases' => $this->em->getRepository(AcademicDatabase::class)->findBy([], ['name' => 'ASC']),
            'variationsText' => implode("\n", $variationsLines),
        ]);
    }

    #[Route('/export', name: 'app_admin_journals_export', methods: ['GET'])]
    public function export(): Response
    {
        $conn = $this->em->getConnection();

        $response = new StreamedResponse(function() use ($conn) {
            $handle = fopen('php://output', 'w+');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['issn', 'title', 'qualis'], ';', '"', '\\');

            $stmt = $conn->executeQuery('SELECT issn, title, qualis FROM qualis_journal ORDER BY title ASC');

            while ($row = $stmt->fetchAssociative()) {
                fputcsv($handle, [
                    $row['issn'],
                    $row['title'],
                    $row['qualis'] ?? ''
                ], ';', '"', '\\');
            }
            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="revistas_qualis.csv"');

        return $response;
    }

    #[Route('/export-thesaurus', name: 'app_admin_journals_export_thesaurus', methods: ['GET'])]
    public function exportThesaurus(Request $request): Response
    {
        $format = strtolower($request->query->get('format', 'the'));
        $filename = ($format === 'csv') ? 'thesauro_revistas.csv' : 'thesauro_revistas.the';
        $sql = 'SELECT j.title AS header, v.variation_name AS variation
                FROM qualis_journal j
                LEFT JOIN qualis_journal_variacoes_nome v ON v.journal_id = j.id
                ORDER BY j.id ASC';

        return $this->thesaurusService->streamExport($this->em->getConnection(), $sql, $format, $filename);
    }

    #[Route('/import-thesaurus', name: 'app_admin_journals_import_thesaurus', methods: ['POST'])]
    public function importThesaurus(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('import_journals_thesaurus', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_admin_journals_index');
        }

        $file = $request->files->get('thesaurus_file');
        if (!$file) {
            $this->addFlash('danger', 'Por favor, envie um arquivo .the ou .csv.');
            return $this->redirectToRoute('app_admin_journals_index');
        }

        try {
            set_time_limit(600);
            $ext = strtolower($file->getClientOriginalExtension());
            $entries = $this->thesaurusService->parseFile($file->getRealPath(), $ext);

            // Sem carregar a tabela global como entidades: mapa chave => id só com colunas escalares
            // (título de todas as revistas; grafias só as que coincidem com um cabeçalho do arquivo).
            // A ordem de preenchimento é a de antes: por id, título sobrescreve e grafia só entra se a chave estiver livre.
            $conn = $this->em->getConnection();
            $headers = ThesaurusFileService::normalizedHeaders($entries, static fn (string $h) => DocumentEnrichmentService::normalize($h));
            $variationsByJournal = [];
            foreach (array_chunk($headers, 500) as $chunk) {
                $rows = $conn->executeQuery(
                    'SELECT journal_id, normalized_name FROM qualis_journal_variacoes_nome WHERE normalized_name IN (?) ORDER BY id',
                    [$chunk],
                    [\Doctrine\DBAL\ArrayParameterType::STRING]
                )->fetchAllNumeric();
                foreach ($rows as [$jid, $nn]) {
                    $variationsByJournal[(int) $jid][] = (string) $nn;
                }
            }
            $journalsMap = [];
            foreach ($conn->executeQuery('SELECT id, title FROM qualis_journal ORDER BY id')->iterateNumeric() as [$jid, $title]) {
                $journalsMap[DocumentEnrichmentService::normalize((string) $title)] = (int) $jid;
                foreach ($variationsByJournal[(int) $jid] ?? [] as $nn) {
                    $journalsMap[$nn] ??= (int) $jid;
                }
            }
            unset($variationsByJournal);
            $issnMap = []; // revistas criadas nesta importação (ainda não gravadas)
            $journalOf = fn (int|QualisJournal|null $v): ?QualisJournal => is_int($v) ? $this->em->find(QualisJournal::class, $v) : $v;

            $addedVars = 0;
            $newJournals = 0;
            $skippedNoIssn = 0;

            foreach ($entries as $entry) {
                $headerName = trim($entry['header'] ?? '');
                if ($headerName === '') continue;

                $normHeader = DocumentEnrichmentService::normalize($headerName);
                $journal = $journalOf($journalsMap[$normHeader] ?? null);

                // Documentos só se ligam a revistas pelo ISSN: sem ISSN no cabeçalho ou nas grafias,
                // a revista criada nunca casaria (antes recebia um normalized_issn "custom_…").
                $issn = null;
                if (!$journal) {
                    foreach (array_merge([$headerName], $entry['variations']) as $text) {
                        if ($issn = ThesaurusFileService::extractIssn($text)) break;
                    }
                    if ($issn === null) {
                        $skippedNoIssn++;
                        continue;
                    }
                    $nIssn = EntityMergeService::normalizeIssn($issn);
                    $journal = $issnMap[$nIssn] ?? $this->em->getRepository(QualisJournal::class)->findOneBy(['normalizedIssn' => $nIssn]);
                }

                if (!$journal) {
                    $journal = new QualisJournal();
                    $journal->setTitle(mb_convert_case($headerName, MB_CASE_TITLE, 'UTF-8'));
                    $journal->setIssn($issn);
                    $journal->setNormalizedIssn(EntityMergeService::normalizeIssn($issn));
                    $this->em->persist($journal);
                    $issnMap[$journal->getNormalizedIssn()] = $journal;
                    $newJournals++;
                }
                $journalsMap[$normHeader] ??= $journal;

                $existingVars = [];
                foreach ($journal->getVariations() as $v) {
                    $existingVars[$v->getNormalizedName()] = true;
                }

                foreach ($entry['variations'] as $varName) {
                    $normVar = DocumentEnrichmentService::normalize($varName);
                    if ($normVar === '') continue;

                    $varName = mb_substr($varName, 0, 500, 'UTF-8');
                    $normVar = mb_substr($normVar, 0, 500, 'UTF-8');

                    if (!isset($existingVars[$normVar])) {
                        $var = new JournalVariation();
                        $var->setVariationName($varName);
                        $var->setNormalizedName($normVar);
                        $var->setVariationType('alternative');
                        $journal->addVariation($var);
                        $existingVars[$normVar] = true;
                        $addedVars++;
                    }
                }
            }

            $this->em->flush();
            $this->addFlash('success', "Importação de Tesauro concluída! Novos Periódicos: {$newJournals}, Novas Variações: {$addedVars}.");
            if ($skippedNoIssn > 0) {
                $this->addFlash('warning', "{$skippedNoIssn} cabeçalho(s) ignorado(s): revista não cadastrada e sem ISSN no arquivo (os documentos só se ligam a revistas pelo ISSN). Cadastre-as com ISSN ou inclua o ISSN como grafia.");
            }
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Erro na importação de tesauro: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_journals_index');
    }

    #[Route('/import', name: 'app_admin_journals_import', methods: ['POST'])]
    public function import(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('import_journals', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_admin_journals_index');
        }

        $file = $request->files->get('csv_file');
        if (!$file) {
            $this->addFlash('danger', 'Por favor, envie um arquivo CSV.');
            return $this->redirectToRoute('app_admin_journals_index');
        }

        try {
            set_time_limit(600);
            $conn = $this->em->getConnection();

            // Separador detectado (; , ou tab): antes o ';' era "detectado" mas nunca aplicado
            $csv = ThesaurusFileService::createCsvReader($file->getRealPath());

            $imported = 0;
            $updated = 0;

            $conn->beginTransaction();
            try {
                $batch = [];
                foreach ($csv->getRecords() as $record) {
                    $issn = trim($record['issn'] ?? $record['ISSN'] ?? '');
                    $title = trim($record['title'] ?? $record['TITLE'] ?? $record['titulo'] ?? $record['Título'] ?? '');
                    $qualis = strtoupper(trim($record['qualis'] ?? $record['QUALIS'] ?? ''));

                    if ($issn === '' || $title === '') {
                        continue;
                    }

                    $normalizedIssn = strtolower(str_replace(['-', ' '], '', $issn));

                    $batch[] = [
                        'issn' => $issn,
                        'normalized_issn' => $normalizedIssn,
                        'title' => $title,
                        'qualis' => $qualis !== '' ? $qualis : null
                    ];

                    if (count($batch) >= 1000) {
                        $stats = $this->processImportBatch($conn, $batch);
                        $imported += $stats['imported'];
                        $updated += $stats['updated'];
                        $batch = [];
                    }
                }

                if (!empty($batch)) {
                    $stats = $this->processImportBatch($conn, $batch);
                    $imported += $stats['imported'];
                    $updated += $stats['updated'];
                }

                // Os relatórios leem document.qualis: os documentos já ligados recebem o estrato importado
                $conn->executeStatement(QualisImporterService::SQL_SYNC_DOCUMENT_QUALIS);

                $conn->commit();
                $this->addFlash('success', "Importação concluída! Cadastrados: {$imported}, Atualizados: {$updated}.");
            } catch (\Throwable $e) {
                $conn->rollBack();
                throw $e;
            }

        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Erro na importação: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_journals_index');
    }

    private function processImportBatch($conn, array $batch): array
    {
        $imported = 0;
        $updated = 0;

        $issns = array_map(fn($row) => $row['normalized_issn'], $batch);
        $quoted = array_map(fn($val) => $conn->quote($val), $issns);
        
        $existing = $conn->fetchFirstColumn(
            'SELECT normalized_issn FROM qualis_journal WHERE normalized_issn IN (' . implode(',', $quoted) . ')'
        );
        $existingMap = array_flip($existing);

        foreach ($batch as $row) {
            if (isset($existingMap[$row['normalized_issn']])) {
                $conn->executeStatement(
                    'UPDATE qualis_journal SET title = ?, qualis = ?, issn = ? WHERE normalized_issn = ?',
                    [$row['title'], $row['qualis'], $row['issn'], $row['normalized_issn']]
                );
                $updated++;
            } else {
                $conn->insert('qualis_journal', [
                    'issn' => $row['issn'],
                    'normalized_issn' => $row['normalized_issn'],
                    'title' => $row['title'],
                    'qualis' => $row['qualis']
                ]);
                $imported++;
            }
        }

        return ['imported' => $imported, 'updated' => $updated];
    }

    #[Route('/merge-preview', name: 'app_admin_journals_merge_preview', methods: ['POST'])]
    public function mergePreview(Request $request): Response
    {
        $ids = array_map('intval', (array) $request->request->all('ids'));
        $ids = array_values(array_filter($ids));

        if (count($ids) < 2 || count($ids) > 5) {
            $this->addFlash('warning', 'Selecione entre 2 e 5 revistas para mesclar.');
            return $this->redirectToRoute('app_admin_journals_index');
        }

        $journals = $this->em->getRepository(QualisJournal::class)->findBy(['id' => $ids]);
        if (count($journals) < 2) {
            $this->addFlash('danger', 'Revistas selecionadas não foram encontradas.');
            return $this->redirectToRoute('app_admin_journals_index');
        }

        $allVariations = [];
        foreach ($journals as $j) {
            if ($j->getTitle()) $allVariations[] = $j->getTitle();
            foreach ($j->getVariations() as $var) {
                if ($var->getVariationName()) $allVariations[] = $var->getVariationName();
            }
        }
        $allVariations = array_values(array_unique(array_filter($allVariations)));

        return $this->render('admin/journals/merge_preview.html.twig', [
            'journals' => $journals,
            'allVariations' => $allVariations,
        ]);
    }

    #[Route('/merge-execute', name: 'app_admin_journals_merge_execute', methods: ['POST'])]
    public function mergeExecute(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('merge_journals', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_admin_journals_index');
        }

        $masterId = (int) $request->request->get('master_id');
        $sourceIds = array_map('intval', (array) $request->request->all('source_ids'));
        $fields = (array) $request->request->all('fields');

        try {
            $master = $this->mergeService->mergeJournals($masterId, $sourceIds, $fields);
            $this->addFlash('success', "Revista '{$master->getTitle()}' (#{$master->getId()}) mesclada e consolidada no Tesauro com sucesso!");
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Erro ao mesclar revistas: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_journals_index');
    }
}
