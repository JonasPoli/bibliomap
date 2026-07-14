<?php

namespace App\Controller;

use App\Entity\QualisJournal;
use Doctrine\ORM\EntityManagerInterface;
use League\Csv\Reader;
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
        private readonly EntityManagerInterface $em
    ) {}

    #[Route('', name: 'app_admin_journals_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $search = $request->query->getString('search', '');
        $qualis = $request->query->getString('qualis', 'all');
        $page = max(1, $request->query->getInt('page', 1));
        $limit = 100;
        $offset = ($page - 1) * $limit;

        $qb = $this->em->createQueryBuilder()
            ->select('q')
            ->from(QualisJournal::class, 'q');

        if ($search !== '') {
            $qb->andWhere('q.title LIKE :search OR q.issn LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }

        if ($qualis !== 'all' && $qualis !== '') {
            $qb->andWhere('q.qualis = :qualis')
               ->setParameter('qualis', $qualis);
        }

        // Clone query builder to count total results for pagination
        $countQb = clone $qb;
        $totalResults = (int)$countQb->select('COUNT(q.id)')->getQuery()->getSingleScalarResult();
        $totalPages = ceil($totalResults / $limit);

        $journals = $qb->orderBy('q.title', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $this->render('admin/journals/index.html.twig', [
            'journals' => $journals,
            'search' => $search,
            'qualis' => $qualis,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalResults' => $totalResults
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
                    'journal' => $journal
                ]);
            }

            $normalizedIssn = strtolower(str_replace(['-', ' '], '', $issn));

            // Check if already exists
            $existing = $this->em->getRepository(QualisJournal::class)->findOneBy(['normalizedIssn' => $normalizedIssn]);
            if ($existing) {
                $this->addFlash('danger', "Já existe um periódico cadastrado com o ISSN {$issn}.");
                return $this->render('admin/journals/new.html.twig', [
                    'journal' => $journal
                ]);
            }

            $journal->setTitle($title);
            $journal->setIssn($issn);
            $journal->setNormalizedIssn($normalizedIssn);
            $journal->setQualis($qualisVal !== '' ? $qualisVal : null);

            $this->em->persist($journal);
            $this->em->flush();

            $this->addFlash('success', 'Periódico cadastrado com sucesso!');
            return $this->redirectToRoute('app_admin_journals_index');
        }

        return $this->render('admin/journals/new.html.twig', [
            'journal' => $journal
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
                    'journal' => $journal
                ]);
            }

            $normalizedIssn = strtolower(str_replace(['-', ' '], '', $issn));

            // Check if ISSN changed and conflicts
            if ($normalizedIssn !== $journal->getNormalizedIssn()) {
                $existing = $this->em->getRepository(QualisJournal::class)->findOneBy(['normalizedIssn' => $normalizedIssn]);
                if ($existing) {
                    $this->addFlash('danger', "Já existe outro periódico cadastrado com o ISSN {$issn}.");
                    return $this->render('admin/journals/edit.html.twig', [
                        'journal' => $journal
                    ]);
                }
            }

            $journal->setTitle($title);
            $journal->setIssn($issn);
            $journal->setNormalizedIssn($normalizedIssn);
            $journal->setQualis($qualisVal !== '' ? $qualisVal : null);

            $this->em->flush();

            $this->addFlash('success', 'Periódico atualizado com sucesso!');
            return $this->redirectToRoute('app_admin_journals_index');
        }

        return $this->render('admin/journals/edit.html.twig', [
            'journal' => $journal
        ]);
    }

    #[Route('/export', name: 'app_admin_journals_export', methods: ['GET'])]
    public function export(): Response
    {
        $conn = $this->em->getConnection();

        $response = new StreamedResponse(function() use ($conn) {
            $handle = fopen('php://output', 'w+');
            // UTF-8 BOM for Excel
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, ['issn', 'title', 'qualis'], ';');

            $stmt = $conn->executeQuery('SELECT issn, title, qualis FROM qualis_journal ORDER BY title ASC');

            while ($row = $stmt->fetchAssociative()) {
                fputcsv($handle, [
                    $row['issn'],
                    $row['title'],
                    $row['qualis'] ?? ''
                ], ';');
            }
            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="revistas_qualis.csv"');

        return $response;
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

            $csv = Reader::createFromPath($file->getRealPath(), 'r');
            $csv->setHeaderOffset(0);
            // Try to auto-detect delimiter
            $delimiter = ';';
            $firstHeader = $csv->getHeader()[0] ?? '';
            if (str_contains($firstHeader, ',')) {
                $delimiter = ',';
                // Reload header with correct delimiter
                $csv = Reader::createFromPath($file->getRealPath(), 'r');
                $csv->setHeaderOffset(0);
            }

            $imported = 0;
            $updated = 0;

            $conn->beginTransaction();
            try {
                $batch = [];
                foreach ($csv->getRecords() as $record) {
                    // Try getting fields case-insensitive
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

    /**
     * Helper to process batches using INSERT ... ON DUPLICATE KEY UPDATE.
     */
    private function processImportBatch($conn, array $batch): array
    {
        $imported = 0;
        $updated = 0;

        // Collect normalized_issns in batch to verify existence
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
}
