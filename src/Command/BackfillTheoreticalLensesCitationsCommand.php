<?php

namespace App\Command;

use App\Entity\TheoreticalLens;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:backfill:theoretical-lenses-citations',
    description: 'Backfill citation formats for all theoretical lenses to ensure 10+ formats each',
)]
class BackfillTheoreticalLensesCitationsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Backfilling citation formats for all theoretical lenses');

        $conn = $this->em->getConnection();
        $io->text('Fetching all lenses from database...');

        $lenses = $conn->fetchAllAssociative('SELECT id, name FROM theoretical_lens');
        $total = count($lenses);

        $io->text(sprintf('Found %d lenses. Starting update...', $total));

        $updated = 0;
        $batchSize = 200;
        $conn->beginTransaction();

        try {
            foreach ($lenses as $lens) {
                $id = (int)$lens['id'];
                $name = $lens['name'];

                try {
                    $formats = TheoreticalLens::generateDefaultCitationFormats($name);
                    $formatsJson = json_encode($formats, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    $sanitizedName = @iconv('UTF-8', 'UTF-8//IGNORE', $name);
                    if ($sanitizedName === false || $sanitizedName === '') {
                        $sanitizedName = mb_convert_encoding($name, 'UTF-8', 'ISO-8859-1');
                    }
                    $io->warning(sprintf('Sanitized name for ID %d: from "%s" to "%s"', $id, bin2hex($name), $sanitizedName));
                    try {
                        $formats = TheoreticalLens::generateDefaultCitationFormats($sanitizedName);
                        $formatsJson = json_encode($formats, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                    } catch (\JsonException $e2) {
                        $io->error(sprintf(
                            'Second json_encode failed on ID %d. Sanitized: "%s". Exception: %s in %s:%d. Formats: %s',
                            $id,
                            $sanitizedName,
                            $e2->getMessage(),
                            $e2->getFile(),
                            $e2->getLine(),
                            print_r($formats, true)
                        ));
                        throw $e2;
                    }
                }

                try {
                    $conn->executeStatement(
                        'UPDATE theoretical_lens SET citation_formats = ? WHERE id = ?',
                        [$formatsJson, $id]
                    );
                } catch (\Throwable $dbEx) {
                    $io->error(sprintf(
                        'Failed on lens ID %d ("%s") with formats JSON: %s. Error: %s',
                        $id,
                        $name,
                        $formatsJson,
                        $dbEx->getMessage()
                    ));
                    throw $dbEx;
                }

                $updated++;

                if ($updated % $batchSize === 0) {
                    $conn->commit();
                    $io->text(sprintf('Updated %d/%d lenses...', $updated, $total));
                    $conn->beginTransaction();
                }
            }

            if ($conn->isTransactionActive()) {
                $conn->commit();
            }

            $io->success(sprintf('Successfully updated citation formats for all %d theoretical lenses!', $updated));
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            $io->error(sprintf('Error during backfill: %s', $e->getMessage()));
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
