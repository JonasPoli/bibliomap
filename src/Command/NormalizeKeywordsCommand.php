<?php

namespace App\Command;

use App\Service\Import\TextNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:keywords:normalize-casing',
    description: 'Normalizes display casing of keywords, merges case duplicates, and cleans redundant thesaurus entries.'
)]
class NormalizeKeywordsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Applies and persists corrections in the database.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $execute = $input->getOption('execute');

        $io->title(sprintf('Keyword Casing Normalization & Thesaurus Cleanup — %s mode', $execute ? 'EXECUTE' : 'DRY-RUN'));

        $conn = $this->em->getConnection();
        $normalizer = new TextNormalizer();

        // 1. Fetch all keywords
        $io->section('1. Normalizing Casing of Existing Keywords...');
        $keywords = $conn->fetchAllAssociative('SELECT id, keyword_original, keyword_display, keyword_normalized, keyword_type, thesaurus_concept_id FROM keyword');

        $updatedCasingCount = 0;
        $casingUpdates = [];

        foreach ($keywords as $kw) {
            $currentDisplay = $kw['keyword_display'] ?: $kw['keyword_original'];
            $newDisplay = $normalizer->formatKeywordCasing($currentDisplay);

            if ($currentDisplay !== $newDisplay) {
                $updatedCasingCount++;
                $casingUpdates[$kw['id']] = $newDisplay;
            }
        }

        $io->text(sprintf('Found %d keywords requiring casing correction.', $updatedCasingCount));

        if ($execute && !empty($casingUpdates)) {
            $io->text('Applying casing corrections...');
            
            $chunks = array_chunk($casingUpdates, 1000, true);
            $totalUpdated = 0;
            
            foreach ($chunks as $chunk) {
                $conn->beginTransaction();
                try {
                    foreach ($chunk as $id => $newDisplay) {
                        $conn->executeStatement('UPDATE keyword SET keyword_display = ? WHERE id = ?', ['temp_' . $id, $id]);
                        $conn->executeStatement('UPDATE keyword SET keyword_display = ? WHERE id = ?', [$newDisplay, $id]);
                    }
                    $conn->commit();
                    $totalUpdated += count($chunk);
                    $io->text(sprintf('Updated %d/%d keywords...', $totalUpdated, count($casingUpdates)));
                    
                    unset($chunk);
                    gc_collect_cycles();
                } catch (\Throwable $e) {
                    $conn->rollBack();
                    $io->error('Failed to update casing batch: ' . $e->getMessage());
                    return Command::FAILURE;
                }
            }
            $io->success(sprintf('Updated casing for %d keywords.', $totalUpdated));
        }

        // 2. Identify and merge casing duplicates
        $io->section('2. Merging Casing Duplicates...');
        // Find duplicates based on (keyword_normalized, keyword_type)
        $duplicates = $conn->fetchAllAssociative('
            SELECT keyword_normalized, keyword_type, COUNT(*) as cnt
            FROM keyword
            GROUP BY keyword_normalized, keyword_type
            HAVING cnt > 1
        ');

        $mergedKeywordsCount = 0;
        $deletedKeywordsCount = 0;

        if (empty($duplicates)) {
            $io->text('No casing duplicate keywords found.');
        } else {
            $io->text(sprintf('Found %d sets of duplicate keywords.', count($duplicates)));

            if ($execute) {
                $conn->beginTransaction();
                try {
                    foreach ($duplicates as $dup) {
                        $normalized = $dup['keyword_normalized'];
                        $type = $dup['keyword_type'];

                        // Fetch all keywords in this duplicate group
                        $rows = $conn->fetchAllAssociative('
                            SELECT k.id, k.keyword_display, k.thesaurus_concept_id,
                                   (SELECT COUNT(*) FROM document_keyword WHERE keyword_id = k.id) AS doc_count
                            FROM keyword k
                            WHERE k.keyword_normalized = ? AND k.keyword_type = ?
                            ORDER BY thesaurus_concept_id DESC, doc_count DESC
                        ', [$normalized, $type]);

                        if (count($rows) < 2) {
                            continue;
                        }

                        // The first row is our "keep" candidate (has thesaurus mapping or more documents)
                        $keep = $rows[0];
                        $keepId = (int)$keep['id'];

                        // Remaining rows are discarded
                        for ($i = 1; $i < count($rows); $i++) {
                            $discardId = (int)$rows[$i]['id'];

                            // Check and delete document_keyword duplicate pairs first to avoid unique key constraints
                            $dupDkIds = $conn->fetchFirstColumn('
                                SELECT dk1.id
                                FROM document_keyword dk1
                                JOIN document_keyword dk2 ON dk1.document_id = dk2.document_id AND dk2.keyword_id = ?
                                WHERE dk1.keyword_id = ?
                            ', [$keepId, $discardId]);

                            if (!empty($dupDkIds)) {
                                $conn->executeStatement('DELETE FROM document_keyword WHERE id IN (' . implode(',', $dupDkIds) . ')');
                            }

                            // Update remaining document_keyword records to point to the keep candidate
                            $conn->executeStatement('UPDATE document_keyword SET keyword_id = ? WHERE keyword_id = ?', [$keepId, $discardId]);

                            // Move any variations to the kept keyword
                            $conn->executeStatement('UPDATE palavra_chave_variacoes_nome SET keyword_id = ? WHERE keyword_id = ?', [$keepId, $discardId]);

                            // Delete the duplicate keyword record (cascades delete on variations if any remain)
                            $conn->executeStatement('DELETE FROM keyword WHERE id = ?', [$discardId]);

                            $deletedKeywordsCount++;
                        }
                        $mergedKeywordsCount++;
                    }
                    $conn->commit();
                    $io->success(sprintf('Merged %d sets of duplicates, deleting %d redundant keyword records.', $mergedKeywordsCount, $deletedKeywordsCount));
                } catch (\Throwable $e) {
                    $conn->rollBack();
                    $io->error('Failed to merge duplicates: ' . $e->getMessage());
                    return Command::FAILURE;
                }
            } else {
                $io->text('[Dry-run] Would merge these duplicates.');
            }
        }

        // 3. Clean up useless/redundant Thesaurus entries
        $io->section('3. Cleaning Up Redundant Thesaurus Entries...');

        // Query to find alternative labels that normalize to the same value as their concept's preferred label
        $redundantLabels = $conn->fetchAllAssociative("
            SELECT tl.id, tl.label, tc.preferred_label, tc.id AS concept_id
            FROM thesaurus_label tl
            JOIN thesaurus_concept tc ON tl.concept_id = tc.id
            WHERE tl.type = 'alternative' AND tl.normalized_label = tc.normalized_label
        ");

        $io->text(sprintf('Found %d redundant alternative labels (normalizing to the same as preferred label).', count($redundantLabels)));

        if ($execute && !empty($redundantLabels)) {
            $io->text('Deleting redundant thesaurus labels...');
            $conn->beginTransaction();
            try {
                $labelIds = array_map(fn($r) => (int)$r['id'], $redundantLabels);
                $conn->executeStatement('DELETE FROM thesaurus_label WHERE id IN (' . implode(',', $labelIds) . ')');
                $conn->commit();
                $io->success(sprintf('Successfully deleted %d redundant alternative labels.', count($redundantLabels)));
            } catch (\Throwable $e) {
                $conn->rollBack();
                $io->error('Failed to delete redundant labels: ' . $e->getMessage());
                return Command::FAILURE;
            }
        }

        // 4. Reset & Reapply thesaurus concepts mappings (so everything is clean and consistent)
        if ($execute) {
            $io->section('4. Re-applying Thesaurus Mappings to All Keywords...');
            try {
                // Reset all keyword thesaurus mappings first
                $conn->executeStatement('UPDATE keyword SET thesaurus_concept_id = NULL');

                // Map keywords where they match a thesaurus label
                $conn->executeStatement('
                    UPDATE keyword k
                    JOIN thesaurus_label tl ON k.keyword_normalized = tl.normalized_label
                    SET k.thesaurus_concept_id = tl.concept_id
                ');

                $io->success('All thesaurus concepts re-mapped successfully!');
            } catch (\Throwable $e) {
                $io->error('Failed to re-apply thesaurus mappings: ' . $e->getMessage());
                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }
}
