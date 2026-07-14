<?php

namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:keywords:clear',
    description: 'Limpa todas as palavras-chave (keywords e document_keyword) do sistema para recomeçar do zero.',
)]
class ClearKeywordsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('BiblioMap — Limpar Palavras-Chave');

        if (!$io->confirm('Tem certeza que deseja limpar todas as palavras-chave cadastradas e seus vinculos com documentos?', false)) {
            $io->note('Operacao cancelada.');
            return Command::SUCCESS;
        }

        $conn = $this->em->getConnection();
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $conn->executeStatement('TRUNCATE TABLE document_keyword');
        $conn->executeStatement('TRUNCATE TABLE keyword');
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        $io->success('Todas as palavras-chave e associacoes foram removidas com sucesso! O dicionario esta limpo.');

        return Command::SUCCESS;
    }
}
