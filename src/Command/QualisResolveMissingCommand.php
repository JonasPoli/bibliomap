<?php

namespace App\Command;

use App\Entity\QualisJournal;
use App\Service\Qualis\SucupiraScraperService;
use App\Service\Thesaurus\EntityMergeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:qualis:resolve-missing',
    description: 'Queries Crossref API for all unmatched ISSNs in documents and registers them in qualis_journal'
)]
class QualisResolveMissingCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SucupiraScraperService $scraperService
    ) {
        parent::__construct();
    }

    /** Dias até consultar de novo, na Sucupira, uma revista que ela informou não ter estrato. */
    public const RETRY_DAYS = 30;

    protected function configure(): void
    {
        $this->addOption(
            'retry-qualis-limit',
            null,
            InputOption::VALUE_REQUIRED,
            'Máximo de revistas sem estrato (e com documentos) consultadas de novo na Sucupira por execução; 0 desliga',
            100
        );
    }

    /**
     * ISSN distintos a consultar: chave sem hífen, sem espaço e em minúsculas (a mesma forma de
     * qualis_journal.normalized_issn no resto do sistema) => grafia do documento.
     * "1234-567X" e "1234567x" são o mesmo ISSN e viram uma entrada só (vale a primeira grafia).
     *
     * @param iterable<string|null> $rawIssns
     * @return array<string, string>
     */
    public static function collectIssns(iterable $rawIssns): array
    {
        $issns = [];
        foreach ($rawIssns as $raw) {
            $raw = trim((string) $raw);
            $key = EntityMergeService::normalizeIssn($raw);
            if (strlen($key) >= 7 && !isset($issns[$key])) {
                $issns[$key] = $raw;
            }
        }

        return $issns;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Crossref API Journal Resolver (Crawler)');

        $conn = $this->em->getConnection();

        // 1. Initial Link step: Match documents with already imported journals (from CAPES PDF or past crawler runs)
        $io->comment('Linking documents to already existing journals in database...');
        $sqlLink = '
            UPDATE document d
            INNER JOIN qualis_journal q 
                ON LOWER(REPLACE(REPLACE(d.issn, "-", ""), " ", "")) = q.normalized_issn
            SET d.qualis_journal_id = q.id, d.qualis = q.qualis
            WHERE d.qualis_journal_id IS NULL AND d.issn IS NOT NULL AND d.issn != ""
        ';
        
        $initialLinked = $conn->executeStatement($sqlLink);
        if ($initialLinked > 0) {
            $io->info("Successfully linked {$initialLinked} documents to existing journals in database.");
        }

        // 1b. Revistas já cadastradas que ficaram sem estrato (Sucupira fora do ar ou sem resultado na época)
        $this->retryMissingQualis((int) $input->getOption('retry-qualis-limit'), $io);

        // 2. Find distinct ISSNs that remain unresolved
        $io->comment('Searching for remaining unresolved ISSNs in documents database...');
        $rows = $conn->fetchAllAssociative('
            SELECT DISTINCT issn 
            FROM document 
            WHERE qualis_journal_id IS NULL AND issn IS NOT NULL AND issn != "" AND issn != "-"
        ');

        if (empty($rows)) {
            $io->success('All documents already have their journals resolved! Nothing to do.');
            return Command::SUCCESS;
        }

        $issns = self::collectIssns(array_column($rows, 'issn'));

        $total = count($issns);
        $io->info("Found {$total} distinct unresolved ISSNs to crawl from Crossref API.");

        $resolved = 0;
        $failed = 0;
        $skipped = 0;

        $io->progressStart($total);

        foreach ($issns as $normalizedIssn => $originalIssn) {
            // Check in db (just in case)
            $existing = $this->em->getRepository(QualisJournal::class)->findOneBy(['normalizedIssn' => $normalizedIssn]);
            if ($existing) {
                $skipped++;
                $io->progressAdvance();
                continue;
            }

            // Request Crossref API
            $url = 'https://api.crossref.org/journals/' . urlencode($originalIssn);
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_USERAGENT, 'BiblioMap/1.0 (https://bibliomap.wab.com.br; mailto:admin@wab.com.br)');
            
            $responseBody = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 && $responseBody) {
                $data = json_decode($responseBody, true);
                $title = $data['message']['title'] ?? null;

                if ($title) {
                    $journal = new QualisJournal();
                    $journal->setTitle($title);
                    $journal->setIssn($originalIssn);
                    $journal->setNormalizedIssn($normalizedIssn);
                    
                    // Fetch Qualis classification from Sucupira
                    $lookup = $this->scraperService->lookupQualis($originalIssn);
                    $journal->setQualis($lookup['qualis']);
                    // Sem resposta da Sucupira a data fica vazia e a revista entra na próxima reconsulta
                    if ($lookup['reached']) {
                        $journal->setQualisCheckedAt(new \DateTimeImmutable());
                    }

                    $this->em->persist($journal);
                    $resolved++;

                    // Save and link documents incrementally every 50 records
                    if ($resolved % 50 === 0) {
                        $this->em->flush();
                        $conn->executeStatement($sqlLink);
                    }
                } else {
                    $failed++;
                }
            } else {
                $failed++;
            }

            $io->progressAdvance();
            usleep(200000); // 200ms delay between calls
        }

        $this->em->flush();
        $conn->executeStatement($sqlLink); // Final sync of links
        $io->progressFinish();

        $io->success([
            'Finished Crossref API resolution!',
            "New journals created: {$resolved}",
            "Failed/Not found:     {$failed}",
            "Skipped:              {$skipped}"
        ]);

        return Command::SUCCESS;
    }

    /**
     * Consulta de novo o estrato das revistas sem Qualis que têm documentos: primeiro as que a
     * Sucupira nunca respondeu, depois as conferidas há mais de RETRY_DAYS dias. O estrato achado
     * vai também para os documentos da revista (document.qualis), em todos os projetos.
     */
    private function retryMissingQualis(int $limit, SymfonyStyle $io): void
    {
        if ($limit <= 0) {
            return;
        }

        $conn = $this->em->getConnection();
        $journals = $conn->fetchAllAssociative(
            'SELECT q.id, q.issn FROM qualis_journal q
             WHERE q.qualis IS NULL AND q.issn IS NOT NULL AND q.issn <> ""
               AND (q.qualis_checked_at IS NULL OR q.qualis_checked_at < ?)
               AND EXISTS (SELECT 1 FROM document d WHERE d.qualis_journal_id = q.id)
             ORDER BY q.qualis_checked_at IS NOT NULL, q.qualis_checked_at, q.id
             LIMIT ' . $limit,
            [(new \DateTimeImmutable('-' . self::RETRY_DAYS . ' days'))->format('Y-m-d H:i:s')]
        );
        if ($journals === []) {
            return;
        }

        $io->comment(sprintf('Re-checking Qualis on Sucupira for %d journal(s) without classification...', count($journals)));
        $found = 0;
        $unreachable = 0;
        foreach ($journals as $journal) {
            $lookup = $this->scraperService->lookupQualis((string) $journal['issn']);
            if (!$lookup['reached']) {
                $unreachable++;
            } else {
                $conn->executeStatement(
                    'UPDATE qualis_journal SET qualis = ?, qualis_checked_at = ? WHERE id = ?',
                    [$lookup['qualis'], date('Y-m-d H:i:s'), $journal['id']]
                );
                if ($lookup['qualis'] !== null) {
                    $found++;
                    $conn->executeStatement('UPDATE document SET qualis = ? WHERE qualis_journal_id = ?', [$lookup['qualis'], $journal['id']]);
                }
            }
            usleep(200000);
        }
        $io->info(sprintf('Qualis re-check: %d found, %d still without classification, %d without answer from Sucupira.', $found, count($journals) - $found - $unreachable, $unreachable));
    }
}
