<?php

namespace App\Command;

use App\Service\Import\DocumentEnrichmentService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(
    name: 'app:project:sync-geography-cli',
    description: 'Sync geography for a project via CLI',
)]
class TestProjectSyncCommand extends Command
{
    public function __construct(
        private readonly DocumentEnrichmentService $enrichmentService,
        private readonly KernelInterface $kernel
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', '1024M');
        $io = new SymfonyStyle($input, $output);
        $projectId = 5;

        $io->title("Syncing geography and institutions for project {$projectId}...");
        $report = $this->enrichmentService->enrichProject($projectId);

        $cacheFile = $this->kernel->getProjectDir() . '/var/geography_sync_cache/project_' . $projectId . '.json';
        $cacheDir = dirname($cacheFile);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0777, true);
        }
        file_put_contents($cacheFile, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $io->success("Sync completed! Cache file written to {$cacheFile}");

        $io->table(
            ['Metric', 'Value'],
            [
                ['Total Documents', $report['total_docs']],
                ['Matched Institutions', $report['matched_institutions_count']],
                ['Matched Countries', $report['matched_countries_count']],
                ['Matched Organizations', $report['matched_organizations_count']],
                ['Matched Units', $report['matched_units_count']],
                ['Unresolved Institutions', count($report['unresolved_institutions'])],
                ['Unresolved Countries', count($report['unresolved_countries'])],
            ]
        );

        return Command::SUCCESS;
    }
}
