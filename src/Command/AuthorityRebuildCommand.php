<?php

namespace App\Command;

use App\Service\Authority\AuthorityResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:authority:rebuild',
    description: 'Recalcula (ou confere com --check) o id efetivo de autores e palavras-chave dos vínculos',
)]
class AuthorityRebuildCommand extends Command
{
    public function __construct(private readonly AuthorityResolver $resolver)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'Só os documentos deste projeto (padrão: todos)')
            ->addOption('entity', null, InputOption::VALUE_REQUIRED, 'author, keyword ou all', 'all')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Tamanho da faixa da chave primária', (string) AuthorityResolver::DEFAULT_BATCH)
            ->addOption('check', null, InputOption::VALUE_NONE, 'Só conta divergências entre o gravado e o calculado (não grava); saída 1 se houver')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Conta o que seria alterado sem gravar (como --check, mas sai com 0)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $entity = (string) $input->getOption('entity');
        $entities = $entity === 'all' ? AuthorityResolver::ENTITIES : [$entity];
        foreach ($entities as $e) {
            if (!in_array($e, AuthorityResolver::ENTITIES, true)) {
                $io->error('--entity deve ser author, keyword ou all.');

                return Command::INVALID;
            }
        }
        $project = $input->getOption('project');
        if ($project !== null && (!ctype_digit((string) $project) || (int) $project < 1)) {
            $io->error('--project deve ser um id de projeto.');

            return Command::INVALID;
        }
        $project = $project === null ? null : (int) $project;
        $batch = (int) $input->getOption('batch');
        if ($batch < 1) {
            $io->error('--batch deve ser maior que zero.');

            return Command::INVALID;
        }
        $check = (bool) $input->getOption('check');
        $dry = (bool) $input->getOption('dry-run');
        $write = !$check && !$dry;
        $label = $check ? 'divergentes' : ($dry ? 'a alterar (simulação)' : 'alteradas');

        $totalChanged = 0;
        $t0 = microtime(true);
        foreach ($entities as $e) {
            $io->section(sprintf('%s%s', $e === 'author' ? 'Autores (document_author)' : 'Palavras-chave (document_keyword)', $project ? " do projeto {$project}" : ''));
            $r = $this->resolver->process($e, $write, $project, $batch, function (int $lo, int $hi, int $n, float $secs) use ($io, $label): void {
                $io->writeln(sprintf('  ids %d a %d: %d %s (%.1f s)', $lo, $hi, $n, $label, $secs), OutputInterface::VERBOSITY_VERBOSE);
                if (!$io->isVerbose()) {
                    $io->write('.');
                }
            });
            $io->newLine();
            $io->writeln(sprintf('  examinadas: %s | %s: %s', number_format($r['examined'], 0, ',', '.'), $label, number_format($r['changed'], 0, ',', '.')));
            $totalChanged += $r['changed'];
        }
        $io->writeln(sprintf('Tempo total: %.1f s', microtime(true) - $t0));

        if ($check) {
            if ($totalChanged > 0) {
                $io->warning("{$totalChanged} divergência(s). Rode app:authority:rebuild para corrigir.");

                return Command::FAILURE;
            }
            $io->success('0 divergências.');
        } elseif ($dry) {
            $io->note('Simulação: nada foi gravado.');
        } else {
            $io->success('Recálculo concluído.');
        }

        return Command::SUCCESS;
    }
}
