<?php

namespace App\Command;

use App\Entity\Keyword;
use App\Entity\ThesaurusConcept;
use App\Entity\ThesaurusLabel;
use App\Entity\ThesaurusScheme;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:keywords:migrate-keyword-concepts-to-thesaurus',
    description: 'Migrates legacy keywordConcept associations to ThesaurusConcept.'
)]
class KeywordMigrateConceptsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulate only, no writes.')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Apply changes to database.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = !$input->getOption('execute');

        $io->title(sprintf('Migrate keywordConcept → ThesaurusConcept (%s)', $dryRun ? 'DRY-RUN' : 'EXECUTE'));

        // Find or create keyword scheme
        $scheme = $this->em->getRepository(ThesaurusScheme::class)->findOneBy(['slug' => 'keyword']);
        if (!$scheme) {
            $scheme = new ThesaurusScheme();
            $scheme->setName('Tesauro de Palavras-chave');
            $scheme->setSlug('keyword');
            $scheme->setType('keyword');
            $this->em->persist($scheme);
            if (!$dryRun) $this->em->flush();
        }

        // Find all keywords that have keywordConcept but no thesaurusConcept
        $conn = $this->em->getConnection();
        $rows = $conn->fetchAllAssociative(
            'SELECT id, keyword_concept_id FROM keyword WHERE keyword_concept_id IS NOT NULL AND thesaurus_concept_id IS NULL'
        );

        $io->info(sprintf('Found %d keywords to migrate.', count($rows)));

        // Group by keywordConcept ID
        $groups = [];
        foreach ($rows as $r) {
            $groups[$r['keyword_concept_id']][] = $r['id'];
        }

        $migratedCount = 0;
        $conceptsCreated = 0;
        $labelsCreated = 0;

        foreach ($groups as $canonicalKwId => $memberIds) {
            $canonicalKw = $this->em->getRepository(Keyword::class)->find($canonicalKwId);
            if (!$canonicalKw) continue;

            $canonicalNorm = strtolower($canonicalKw->getKeywordDisplay() ?: $canonicalKw->getKeywordOriginal());

            // Find or create ThesaurusConcept
            $concept = $this->em->getRepository(ThesaurusConcept::class)->findOneBy([
                'scheme' => $scheme,
                'normalizedLabel' => $canonicalNorm
            ]);

            if (!$concept) {
                $concept = new ThesaurusConcept();
                $concept->setScheme($scheme);
                $concept->setPreferredLabel($canonicalKw->getKeywordDisplay() ?: $canonicalKw->getKeywordOriginal());
                $concept->setNormalizedLabel($canonicalNorm);
                $this->em->persist($concept);
                $conceptsCreated++;

                // Create preferred label
                $prefLabel = new ThesaurusLabel();
                $prefLabel->setConcept($concept);
                $prefLabel->setLabel($concept->getPreferredLabel());
                $prefLabel->setNormalizedLabel($canonicalNorm);
                $prefLabel->setType('preferred');
                $this->em->persist($prefLabel);
                $labelsCreated++;
            }

            // Link canonical keyword
            if (!$dryRun) {
                $canonicalKw->setThesaurusConcept($concept);
            }
            $migratedCount++;

            // Link all members and create labels for their variations
            foreach ($memberIds as $memberId) {
                $member = $this->em->getRepository(Keyword::class)->find($memberId);
                if (!$member) continue;

                if (!$dryRun) {
                    $member->setThesaurusConcept($concept);
                }
                $migratedCount++;

                // Create alternative label if different from canonical
                $memberNorm = strtolower($member->getKeywordDisplay() ?: $member->getKeywordOriginal());
                if ($memberNorm !== $canonicalNorm) {
                    $existingLabel = $this->em->getRepository(ThesaurusLabel::class)->findOneBy([
                        'concept' => $concept,
                        'normalizedLabel' => $memberNorm
                    ]);
                    if (!$existingLabel) {
                        $altLabel = new ThesaurusLabel();
                        $altLabel->setConcept($concept);
                        $altLabel->setLabel($member->getKeywordDisplay() ?: $member->getKeywordOriginal());
                        $altLabel->setNormalizedLabel($memberNorm);
                        $altLabel->setType('alternative');
                        $this->em->persist($altLabel);
                        $labelsCreated++;
                    }
                }
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->success('Migration completed!');
        $io->table(['Metric', 'Count'], [
            ['Keywords Migrated', $migratedCount],
            ['Concepts Created', $conceptsCreated],
            ['Labels Created', $labelsCreated],
        ]);

        return Command::SUCCESS;
    }
}
