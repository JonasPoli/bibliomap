<?php

namespace App\Tests\Service;

use App\Service\DatabaseBackup\DatabaseBackupException;
use App\Service\DatabaseBackup\DatabaseBackupService;
use PHPUnit\Framework\TestCase;

/** Regras do backup que não dependem do banco: nome, backup do dia, retenção, pasta e dump simulado. */
class DatabaseBackupServiceTest extends TestCase
{
    private string $root;
    private string $dir;
    private const URL = 'mysql://app%40user:p%5Cass%22w0rd@127.0.0.1:3307/bibliomap?serverVersion=8.0&charset=utf8mb4';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/bm-backup-test-' . bin2hex(random_bytes(4));
        $this->dir = $this->root . '/backups';
        mkdir($this->dir, 0700, true);
        chmod($this->dir, 0700);
        mkdir($this->root . '/project', 0755);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testFileNameCarriesDateAndTime(): void
    {
        $at = new \DateTimeImmutable('2026-11-10 00:30:59');
        $this->assertSame('bibliomap-20261110-0030.sql.gz', DatabaseBackupService::fileNameFor($at));
        $this->assertEquals(new \DateTimeImmutable('2026-11-10 00:30'), DatabaseBackupService::dateFromName('bibliomap-20261110-0030.sql.gz'));
        $this->assertNull(DatabaseBackupService::dateFromName('bibliomap-20261110-0030.sql.gz.part'));
        $this->assertNull(DatabaseBackupService::dateFromName('bibliomap-20261025-antes-deploy-cron.sql.gz'));
    }

    public function testCompleteBackupOfTheDay(): void
    {
        $today = new \DateTimeImmutable('2026-11-10 00:45');
        $service = $this->service();

        $this->touch('bibliomap-20261109-0030.sql.gz');
        $this->touch('bibliomap-20261110-0030.sql.gz.part');
        $this->touch('bibliomap-20261110-antes-deploy-cron.sql.gz');
        $this->assertFalse($service->hasBackupForDay($today), 'só o de ontem, um .part de hoje e um dump manual: não conta');

        $this->touch('bibliomap-20261110-0012.sql.gz', '');
        $this->assertFalse($service->hasBackupForDay($today), 'arquivo vazio não conta');

        $this->touch('bibliomap-20261110-0030.sql.gz');
        $this->assertTrue($service->hasBackupForDay($today));
        $this->assertSame('bibliomap-20261110-0030.sql.gz', $service->findLatestBackup()['name']);
    }

    public function testNotConfiguredHasNoBackup(): void
    {
        $service = $this->service(dir: '');
        $this->assertFalse($service->isConfigured());
        $this->assertFalse($service->hasBackupForDay(new \DateTimeImmutable()));
        $this->assertNull($service->findLatestBackup());
    }

    public function testRetentionKeepsSevenDaysAndSundaysUpTo28(): void
    {
        $today = new \DateTimeImmutable('2026-11-11 00:30'); // quarta-feira
        $names = [
            'bibliomap-20261111-0030.sql.gz', // hoje
            'bibliomap-20261104-0030.sql.gz', // 7 dias: fica
            'bibliomap-20261103-0030.sql.gz', // 8 dias, terça-feira: sai
            'bibliomap-20261101-0030.sql.gz', // 10 dias, domingo: fica
            'bibliomap-20261025-0030.sql.gz', // 17 dias, domingo: fica
            'bibliomap-20261014-0030.sql.gz', // 28 dias, quarta-feira: sai
            'bibliomap-20261011-0030.sql.gz', // 31 dias, domingo: sai
            'bibliomap-20261025-antes-deploy-cron.sql.gz', // dump manual: nunca
            'bibliomap-20261003-0030.sql.gz.part', // sobra: não é backup
        ];

        $this->assertSame(
            ['bibliomap-20261011-0030.sql.gz', 'bibliomap-20261014-0030.sql.gz', 'bibliomap-20261103-0030.sql.gz'],
            DatabaseBackupService::selectExpired($names, $today)
        );
    }

    public function testDirectoryValidation(): void
    {
        $this->assertRefused($this->service(dir: ''), 'não configurada');
        $this->assertRefused($this->service(dir: 'relativa/backups'), 'caminho absoluto');
        $this->assertRefused($this->service(dir: $this->root . '/nao-existe'), 'não existe');

        mkdir($this->root . '/project/var/backups', 0700, true);
        $this->assertRefused($this->service(dir: $this->root . '/project/var/backups'), 'dentro do projeto');

        chmod($this->dir, 0755);
        $this->assertRefused($this->service(), 'permissão 755');
        chmod($this->dir, 0750);
        $this->assertRefused($this->service(), 'permissão 750');

        chmod($this->dir, 0700);
        $this->assertRefused($this->service(minFree: PHP_INT_MAX), 'Espaço livre insuficiente');

        $this->service()->validateDirectory();
        $this->addToAssertionCount(1);
    }

    public function testDatabaseUrlAndOptionsFile(): void
    {
        $db = DatabaseBackupService::parseDatabaseUrl(self::URL);
        $this->assertSame('app@user', $db['user']);
        $this->assertSame('p\\ass"w0rd', $db['password']);
        $this->assertSame('127.0.0.1', $db['host']);
        $this->assertSame(3307, $db['port']);
        $this->assertSame('bibliomap', $db['dbname']);

        $content = DatabaseBackupService::optionsFileContent($db);
        $this->assertStringStartsWith("[client]\n", $content);
        $this->assertStringContainsString('password="p\\\\ass"w0rd"', $content);
        $this->assertStringContainsString("port=3307\n", $content);

        $this->expectException(DatabaseBackupException::class);
        DatabaseBackupService::parseDatabaseUrl('sqlite:///var/data.db');
    }

    public function testRunWritesCheckedFile600AndAppliesRetention(): void
    {
        $fake = $this->fakeMysqldump(<<<'SH'
            case "$1" in --defaults-extra-file=*) ;; *) echo "primeira opção errada: $1" >&2; exit 9 ;; esac
            cnf="${1#--defaults-extra-file=}"
            [ "$(stat -f %Lp "$cnf" 2>/dev/null || stat -c %a "$cnf")" = "600" ] || { echo "cnf sem 600" >&2; exit 8; }
            grep -q '^password=' "$cnf" || exit 7
            echo "-- MySQL dump"; echo "CREATE TABLE t (id int);"
            SH);
        $this->touch('bibliomap-20261001-0030.sql.gz'); // 40 dias: sai
        $this->touch('bibliomap-20261109-0030.sql.gz'); // ontem: fica
        $this->touch('bibliomap-20261110-0001.sql.gz.part'); // sobra de execução interrompida
        $this->touch(DatabaseBackupService::OPTIONS_FILE);

        $result = $this->service(mysqldump: $fake)->run(new \DateTimeImmutable('2026-11-10 00:30'));

        $final = $this->dir . '/bibliomap-20261110-0030.sql.gz';
        $this->assertSame('bibliomap-20261110-0030.sql.gz', $result['name']);
        $this->assertFileExists($final);
        $this->assertSame(0600, fileperms($final) & 0777);
        $this->assertStringContainsString('CREATE TABLE t', gzdecode(file_get_contents($final)));
        $this->assertSame(['bibliomap-20261001-0030.sql.gz'], $result['removed']);
        $this->assertContains('bibliomap-20261110-0001.sql.gz.part', $result['leftovers']);
        $this->assertSame(
            ['bibliomap-20261109-0030.sql.gz', 'bibliomap-20261110-0030.sql.gz'],
            array_values(array_diff(scandir($this->dir), ['.', '..']))
        );
    }

    public function testFailedDumpKeepsOldBackupsAndHidesCredential(): void
    {
        $fake = $this->fakeMysqldump(<<<'SH'
            echo "mysqldump: Got error: 1045: Access denied for user 'app@user'@'localhost' (using password: YES)" >&2
            exit 2
            SH);
        $this->touch('bibliomap-20261001-0030.sql.gz');

        try {
            $this->service(mysqldump: $fake)->run(new \DateTimeImmutable('2026-11-10 00:30'));
            $this->fail('Esperava DatabaseBackupException');
        } catch (DatabaseBackupException $e) {
            $this->assertStringContainsString('código 2', $e->getMessage());
            $this->assertStringNotContainsString('app@user', $e->getMessage());
        }

        $this->assertSame(['bibliomap-20261001-0030.sql.gz'], array_values(array_diff(scandir($this->dir), ['.', '..'])));
    }

    private function assertRefused(DatabaseBackupService $service, string $expected): void
    {
        try {
            $service->validateDirectory();
            $this->fail("Esperava recusa: $expected");
        } catch (DatabaseBackupException $e) {
            $this->assertStringContainsString($expected, $e->getMessage());
        }
    }

    private function service(?string $dir = null, int $minFree = 1, string $mysqldump = 'mysqldump'): DatabaseBackupService
    {
        return new DatabaseBackupService($dir ?? $this->dir, self::URL, $this->root . '/project', $minFree, $mysqldump);
    }

    private function touch(string $name, string $content = 'x'): void
    {
        file_put_contents($this->dir . '/' . $name, $content);
    }

    private function fakeMysqldump(string $body): string
    {
        $path = $this->root . '/fake-mysqldump';
        file_put_contents($path, "#!/bin/sh\n" . $body . "\n");
        chmod($path, 0700);

        return $path;
    }
}
