<?php

namespace App\Command;

use App\Service\Qualis\QualisImporterService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(
    name: 'app:qualis:import',
    description: 'Imports the Qualis CAPES journal database from a PDF file.'
)]
class QualisImportCommand extends Command
{
    public function __construct(
        private readonly QualisImporterService $importerService,
        private readonly KernelInterface $kernel,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'pdf',
            null,
            InputOption::VALUE_REQUIRED,
            'Path to the Qualis PDF file',
            $this->kernel->getProjectDir() . '/docs/qualis/qualis-capes.pdf'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $pdfPath = $input->getOption('pdf');

        if (!file_exists($pdfPath)) {
            $io->error("The specified PDF file does not exist at: {$pdfPath}");
            return Command::FAILURE;
        }

        $io->title('Qualis CAPES PDF Importer');
        $io->info("Using PDF: {$pdfPath}");
        $io->comment('Processing PDF pages via Python (this might take up to 2-3 minutes)...');

        $tempJsonPath = $this->kernel->getProjectDir() . '/var/qualis_temp_' . uniqid() . '.json';

        try {
            $stats = $this->importerService->importFromPdf($pdfPath, $tempJsonPath);

            $io->success([
                'Qualis CAPES database successfully loaded!',
                "Total Journals Extracted: {$stats['total_extracted']}",
                "Total Journals Imported:  {$stats['total_imported']}",
                "Elapsed Time:            {$stats['time_seconds']} seconds"
            ]);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error("Import failed: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
