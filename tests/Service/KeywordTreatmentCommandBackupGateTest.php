<?php

namespace App\Tests\Service;

use App\Command\KeywordTreatmentCommand;
use App\Entity\KeywordTreatmentJob;
use App\Service\DatabaseBackup\DatabaseBackupService;
use App\Service\KeywordTreatment\KeywordTreatmentBlockedException;
use App\Service\KeywordTreatment\KeywordTreatmentService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** app:keywords:treat --require-backup: sem backup completo de hoje, o serviço nem é chamado. */
class KeywordTreatmentCommandBackupGateTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/bm-gate-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testTodaysBackupLetsTreatmentRun(): void
    {
        $this->touch(DatabaseBackupService::fileNameFor(new \DateTimeImmutable('today 00:30')));

        [$code, $display] = $this->runCommand(calls: 1);

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringNotContainsString('Backup do banco de hoje não encontrado', $display);
    }

    public function testOnlyYesterdaysBackupBlocks(): void
    {
        $this->touch(DatabaseBackupService::fileNameFor(new \DateTimeImmutable('yesterday 00:30')));
        $this->assertBlocked();
    }

    public function testOnlyPartFileOfTodayBlocks(): void
    {
        $this->touch(DatabaseBackupService::fileNameFor(new \DateTimeImmutable('today 00:30')) . '.part');
        $this->assertBlocked();
    }

    public function testDirectoryNotConfiguredBlocks(): void
    {
        $this->assertBlocked(dir: '');
    }

    public function testWithoutOptionBackupIsNotRequired(): void
    {
        [$code] = $this->runCommand(calls: 1, requireBackup: false);
        $this->assertSame(Command::SUCCESS, $code);
    }

    public function testBlockedTreatmentIsAWarningNotAFailure(): void
    {
        $service = $this->createMock(KeywordTreatmentService::class);
        $service->expects($this->once())->method('executeJob')->willThrowException(KeywordTreatmentBlockedException::anotherRunning());
        $tester = new CommandTester(new KeywordTreatmentCommand($service, $this->backups($this->dir)));

        $code = $tester->execute(['--execute' => true]);

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('Outro tratamento de palavras-chave está em andamento.', $tester->getDisplay());
    }

    private function assertBlocked(?string $dir = null): void
    {
        [$code, $display] = $this->runCommand(calls: 0, dir: $dir);

        $this->assertSame(Command::FAILURE, $code);
        $this->assertStringContainsString('Backup do banco de hoje não encontrado: tratamento adiado.', $display);
    }

    /** @return array{int, string} */
    private function runCommand(int $calls, ?string $dir = null, bool $requireBackup = true): array
    {
        $service = $this->createMock(KeywordTreatmentService::class);
        $service->expects($this->exactly($calls))->method('executeJob')->willReturn(new KeywordTreatmentJob());

        $tester = new CommandTester(new KeywordTreatmentCommand($service, $this->backups($dir ?? $this->dir)));
        $args = ['--execute' => true];
        if ($requireBackup) {
            $args['--require-backup'] = true;
        }
        $code = $tester->execute($args);

        return [$code, preg_replace('/\s+/', ' ', $tester->getDisplay())];
    }

    private function backups(string $dir): DatabaseBackupService
    {
        return new DatabaseBackupService($dir, 'mysql://u:p@127.0.0.1/bibliomap', '/nao/e/o/projeto');
    }

    private function touch(string $name): void
    {
        file_put_contents($this->dir . '/' . $name, 'x');
    }
}
