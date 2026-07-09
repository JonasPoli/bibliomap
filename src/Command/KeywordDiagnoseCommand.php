<?php

namespace App\Command;

use App\Service\KeywordTreatment\KeywordTreatmentService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:keywords:diagnose',
    description: 'Displays a full diagnostic report of the keyword database.'
)]
class KeywordDiagnoseCommand extends Command
{
    public function __construct(
        private readonly KeywordTreatmentService $service
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Keyword Database Diagnostic');

        $d = $this->service->getDiagnosis();

        $io->table(['Metric', 'Value'], [
            ['Total Keywords', $d['total']],
            ['Total DocumentKeyword', $d['totalDocumentKeywords']],
            ['Documents with Keywords', $d['totalDocsWithKeyword']],
            ['Without Display', $d['noDisplay']],
            ['Without Normalized', $d['noNormalized']],
            ['Dirty (needs cleanup)', $d['dirtyCount']],
            ['Invalid', $d['invalidCount']],
            ['Suspicious', $d['suspiciousCount']],
            ['Duplicates (by normalized)', $d['duplicatesCount']],
            ['With ThesaurusConcept', $d['hasThesaurusConcept']],
            ['Without ThesaurusConcept', $d['noThesaurusConcept']],
            ['Legacy keywordConcept only', $d['hasKeywordConceptLegacy']],
            ['Inactive (status=false)', $d['inactive']],
            ['Thesaurus Concepts (keyword)', $d['thesaurusConceptsCount']],
            ['Thesaurus Labels (keyword)', $d['thesaurusLabelsCount']],
            ['Pending Suggestions', $d['pendingSuggestions']],
        ]);

        $io->section('Keywords by Type');
        $typeRows = [];
        foreach ($d['types'] as $type => $count) {
            $typeRows[] = [$type, $count];
        }
        $io->table(['Type', 'Count'], $typeRows);

        return Command::SUCCESS;
    }
}
