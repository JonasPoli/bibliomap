<?php

namespace App\Command;

use App\Service\DatabaseBackup\DatabaseBackupException;
use App\Service\DatabaseBackup\DatabaseBackupService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:database:backup',
    description: 'Grava um backup completo e comprimido do banco (mysqldump) na pasta DATABASE_BACKUP_DIR e aplica a retenção (7 diários e 4 de domingo).'
)]
class DatabaseBackupCommand extends Command
{
    public function __construct(
        private readonly DatabaseBackupService $backups
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp(<<<'HELP'
A pasta vem de DATABASE_BACKUP_DIR (só no .env.local): fora do projeto, com permissão 700 e ao menos 2 GB livres.
O arquivo bibliomap-AAAAMMDD-HHMM.sql.gz (permissão 600) só aparece depois de o dump terminar e passar no gzip -t.
Depois de um backup bem-sucedido, apaga os backups com mais de 7 dias, exceto os de domingo, que ficam até 28 dias.
Usuário e senha do banco vão num arquivo de opções temporário e nunca aparecem na saída nem na linha de comando.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Backup do banco — ' . (new \DateTimeImmutable())->format('Y-m-d H:i:s T'));

        try {
            $result = $this->backups->run();
        } catch (DatabaseBackupException $e) {
            $io->error('Backup não realizado: ' . $e->getMessage());

            return Command::FAILURE;
        }

        if ($result['leftovers'] !== []) {
            $io->note('Sobras de uma execução interrompida apagadas: ' . implode(', ', $result['leftovers']));
        }

        $io->success('Backup concluído.');
        $io->table(['Item', 'Valor'], [
            ['Arquivo', $result['name']],
            ['Tamanho', DatabaseBackupService::formatBytes($result['size'])],
            ['Duração', sprintf('%.1f s', $result['seconds'])],
            ['Removidos pela retenção', $result['removed'] === [] ? 'nenhum' : implode(', ', $result['removed'])],
        ]);

        return Command::SUCCESS;
    }
}
