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

        // 1. Find all distinct ISSNs from documents that don't have qualis_journal_id
        $io->comment('Searching for unmatched ISSNs in documents database...');
        
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
            // Clean up basic formatting
            $clean = str_replace([' ', '-'], '', $raw);
            if (strlen($clean) >= 7) { // ISSNs usually have 8 characters (or 7 + X)
                $issns[$clean] = $raw;
            }
        }

        $total = count($issns);
        $io->info("Found {$total} distinct unmatched ISSNs to resolve.");

        $resolved = 0;
        $failed = 0;
        $skipped = 0;

        $io->progressStart($total);

        foreach ($issns as $normalizedIssn => $originalIssn) {
            // Check if another parallel request or run already created it
            $existing = $this->em->getRepository(QualisJournal::class)->findOneBy(['normalizedIssn' => $normalizedIssn]);
            if ($existing) {
                $skipped++;
                $io->progressAdvance();
                continue;
            }

            // Call Crossref API
            $url = 'https://api.crossref.org/journals/' . urlencode($originalIssn);
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            // User-agent requested by Crossref Policy
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
                    $journal->setQualis(null); // API does not have Qualis CAPES

                    $this->em->persist($journal);
                    $resolved++;

                    // Flush every 20 records to save memory
                    if ($resolved % 20 === 0) {
                        $this->em->flush();
                    }
                } else {
                    $failed++;
                }
            } else {
                $failed++;
            }

            $io->progressAdvance();
            // Be polite to API (rate limiting prevention)
            usleep(200000); // 200ms delay between API calls
        }

        $this->em->flush();
        $io->progressFinish();

        $io->section('Updating documents associations...');

        // 2. Perform bulk update to link documents to the newly resolved journals
        $sql = '
            UPDATE document d
            INNER JOIN qualis_journal q 
                ON LOWER(REPLACE(REPLACE(d.issn, "-", ""), " ", "")) = q.normalized_issn
            SET d.qualis_journal_id = q.id, d.qualis = q.qualis
            WHERE d.qualis_journal_id IS NULL AND d.issn IS NOT NULL AND d.issn != ""
        ';
        $affected = $conn->executeStatement($sql);

        $io->success([
            'Finished Crossref API resolution!',
            "Resolved:           {$resolved} journals created",
            "Failed/Not found:   {$failed}",
            "Skipped (existing): {$skipped}",
            "Affected documents: {$affected} rows updated in database"
        ]);

        return Command::SUCCESS;
    }
}
