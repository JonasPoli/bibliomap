<?php

namespace App\Command;

use App\Entity\QualisJournal;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:qualis:resolve-missing',
    description: 'Queries Crossref API for all unmatched ISSNs in documents and registers them in qualis_journal'
)]
class QualisResolveMissingCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em
    ) {
        parent::__construct();
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

        $issns = [];
        foreach ($rows as $row) {
            $raw = trim($row['issn']);
            $clean = str_replace([' ', '-'], '', $raw);
            if (strlen($clean) >= 7) {
                $issns[$clean] = $raw;
            }
        }

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
                    $journal->setQualis(null);

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
}
