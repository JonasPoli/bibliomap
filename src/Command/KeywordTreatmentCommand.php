<?php

namespace App\Command;

use App\Service\KeywordTreatment\KeywordTreatmentOptions;
use App\Service\KeywordTreatment\KeywordTreatmentService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:keywords:treat',
    description: 'Executes the automated keyword treatment and consolidation pipeline.'
)]
class KeywordTreatmentCommand extends Command
{
    public function __construct(
        private readonly KeywordTreatmentService $service
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulates changes without writing to database.')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Applies and persists corrections in the database.')
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Maximum keywords to process', 5000)
            ->addOption('batch-size', null, InputOption::VALUE_OPTIONAL, 'Batch size for processing', 500)
            ->addOption('min-auto-score', null, InputOption::VALUE_OPTIONAL, 'Minimum fuzzy auto-match threshold', 95.0)
            ->addOption('min-review-score', null, InputOption::VALUE_OPTIONAL, 'Minimum fuzzy review threshold', 75.0)
            ->addOption('auto-create-concepts', null, InputOption::VALUE_NONE, 'Auto-create ThesaurusConcepts for unmatched keywords')
            ->addOption('no-invalids', null, InputOption::VALUE_NONE, 'Skip invalid/suspicious detection')
            ->addOption('no-exact', null, InputOption::VALUE_NONE, 'Skip exact matching')
            ->addOption('no-thesaurus', null, InputOption::VALUE_NONE, 'Skip thesaurus matching')
            ->addOption('no-fuzzy', null, InputOption::VALUE_NONE, 'Skip fuzzy matching');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $options = new KeywordTreatmentOptions(
            dryRun: !$input->getOption('execute'),
            limit: (int)$input->getOption('limit'),
            batchSize: (int)$input->getOption('batch-size'),
            minAutoScore: (float)$input->getOption('min-auto-score'),
            minReviewScore: (float)$input->getOption('min-review-score'),
            autoCreateConcepts: $input->getOption('auto-create-concepts'),
            processInvalids: !$input->getOption('no-invalids'),
            processExact: !$input->getOption('no-exact'),
            processThesaurus: !$input->getOption('no-thesaurus'),
            processFuzzy: !$input->getOption('no-fuzzy'),
        );

        $io->title(sprintf('Keyword Treatment Job — %s mode', $options->dryRun ? 'DRY-RUN' : 'EXECUTE'));

        try {
            $job = $this->service->executeJob($options, 'cli');

            $io->success('Treatment pipeline completed successfully!');
            $io->table(
                ['Metric', 'Count'],
                [
                    ['Total Processed', $job->getTotalKeywords()],
                    ['Cleaned', $job->getCleanedCount()],
                    ['Invalid', $job->getInvalidCount()],
                    ['Suspicious', $job->getSuspiciousCount()],
                    ['Exact Matched', $job->getExactMatchedCount()],
                    ['Thesaurus Matched', $job->getThesaurusMatchedCount()],
                    ['Fuzzy Auto Matched', $job->getFuzzyAutoMatchedCount()],
                    ['Fuzzy Review Required', $job->getFuzzyReviewCount()],
                    ['Concepts Created', $job->getCreatedConceptCount()],
                    ['Errors', $job->getErrorCount()],
                    ['Affected Documents', $job->getAffectedDocumentCount()],
                ]
            );

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
