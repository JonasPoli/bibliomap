<?php

namespace App\Service\DatabaseBackup;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Backup diário do banco (mysqldump comprimido) numa pasta fora do projeto,
 * configurada em DATABASE_BACKUP_DIR (só no .env.local do servidor).
 *
 * Um arquivo conta como backup só com o nome final bibliomap-AAAAMMDD-HHMM.sql.gz:
 * ele nasce como .part e só é renomeado depois de o mysqldump e o gzip terminarem
 * sem erro e de o gzip -t conferir o arquivo. A credencial vai num arquivo de opções
 * temporário (600), nunca na linha de comando nem na saída.
 */
class DatabaseBackupService
{
    public const MIN_FREE_BYTES = 2 * 1024 * 1024 * 1024;
    public const OPTIONS_FILE = '.bibliomap-backup.cnf';
    public const DAILY_DAYS = 7;
    public const SUNDAY_DAYS = 28;
    private const NAME_PATTERN = '/^bibliomap-(\d{8})-(\d{4})\.sql\.gz$/';
    private const PART_PATTERN = '/^bibliomap-\d{8}-\d{4}\.sql\.gz\.part$/';

    public function __construct(
        #[Autowire(env: 'DATABASE_BACKUP_DIR')]
        private readonly string $backupDir,
        #[Autowire(env: 'resolve:DATABASE_URL')]
        private readonly string $databaseUrl,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        private readonly int $minFreeBytes = self::MIN_FREE_BYTES,
        private readonly string $mysqldumpBinary = 'mysqldump',
        private readonly string $gzipBinary = 'gzip',
    ) {}

    public function isConfigured(): bool
    {
        return trim($this->backupDir) !== '';
    }

    public static function fileNameFor(\DateTimeInterface $at): string
    {
        return 'bibliomap-' . $at->format('Ymd-Hi') . '.sql.gz';
    }

    /** Data e hora do backup pelo nome, ou null se o nome não é de um backup completo. */
    public static function dateFromName(string $name): ?\DateTimeImmutable
    {
        if (!preg_match(self::NAME_PATTERN, $name, $m)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!YmdHi', $m[1] . $m[2]);

        return $date ?: null;
    }

    /**
     * Backups completos da pasta, do mais recente ao mais antigo.
     *
     * @return list<array{name: string, size: int, date: \DateTimeImmutable}>
     */
    public function listBackups(): array
    {
        if (!$this->isConfigured() || !is_dir($this->dir())) {
            return [];
        }

        $backups = [];
        foreach (scandir($this->dir()) ?: [] as $name) {
            $date = self::dateFromName($name);
            $path = $this->dir() . '/' . $name;
            if ($date === null || !is_file($path)) {
                continue;
            }
            $backups[] = ['name' => $name, 'size' => (int) filesize($path), 'date' => $date];
        }
        usort($backups, fn(array $a, array $b) => strcmp($b['name'], $a['name']));

        return $backups;
    }

    /** @return array{name: string, size: int, date: \DateTimeImmutable}|null */
    public function findLatestBackup(): ?array
    {
        return $this->listBackups()[0] ?? null;
    }

    /** Há backup completo com a data deste dia? (Arquivos .part não contam.) */
    public function hasBackupForDay(\DateTimeInterface $day): bool
    {
        foreach ($this->listBackups() as $backup) {
            if ($backup['date']->format('Ymd') === $day->format('Ymd') && $backup['size'] > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Recusa a pasta vazia, inexistente, dentro do projeto, com acesso de grupo ou de outros,
     * ou com menos espaço livre que o mínimo.
     *
     * @throws DatabaseBackupException
     */
    public function validateDirectory(): void
    {
        if (!$this->isConfigured()) {
            throw new DatabaseBackupException('DATABASE_BACKUP_DIR não configurada: defina a pasta de backups no .env.local.');
        }
        $dir = $this->dir();
        if (!str_starts_with($dir, '/')) {
            throw new DatabaseBackupException('DATABASE_BACKUP_DIR precisa ser um caminho absoluto.');
        }
        if (!is_dir($dir)) {
            throw new DatabaseBackupException('A pasta de backups não existe: crie-a fora do projeto, com permissão 700.');
        }

        $real = realpath($dir) ?: $dir;
        $project = realpath($this->projectDir) ?: $this->projectDir;
        if ($real === $project || str_starts_with($real, rtrim($project, '/') . '/')) {
            throw new DatabaseBackupException('A pasta de backups fica dentro do projeto: use uma pasta fora do webapp e da pasta pública.');
        }

        clearstatcache(true, $dir);
        $perms = fileperms($dir) & 0777;
        if (($perms & 0077) !== 0) {
            throw new DatabaseBackupException(sprintf('A pasta de backups tem permissão %o: use 700 (sem acesso de grupo nem de outros).', $perms));
        }
        if (!is_writable($dir)) {
            throw new DatabaseBackupException('A pasta de backups não aceita escrita deste usuário.');
        }

        $free = disk_free_space($dir);
        if ($free === false || $free < $this->minFreeBytes) {
            throw new DatabaseBackupException(sprintf(
                'Espaço livre insuficiente na partição da pasta de backups: %s (mínimo %s).',
                self::formatBytes((int) $free),
                self::formatBytes($this->minFreeBytes)
            ));
        }
    }

    /**
     * Nomes a apagar pela retenção: mais de 7 dias, exceto os feitos num domingo, que ficam até 28.
     * A idade vem da data no nome; nomes que não são de backup completo nunca entram.
     *
     * @param iterable<string> $names
     * @return list<string>
     */
    public static function selectExpired(iterable $names, \DateTimeInterface $today): array
    {
        $todayDate = new \DateTimeImmutable($today->format('Y-m-d'));
        $expired = [];
        foreach ($names as $name) {
            $date = self::dateFromName($name);
            if ($date === null) {
                continue;
            }
            $age = (int) $date->setTime(0, 0)->diff($todayDate)->format('%r%a');
            if ($age <= self::DAILY_DAYS) {
                continue;
            }
            $isSunday = $date->format('w') === '0';
            if ($isSunday && $age <= self::SUNDAY_DAYS) {
                continue;
            }
            $expired[] = $name;
        }
        sort($expired);

        return $expired;
    }

    /**
     * Gera o backup do momento e aplica a retenção.
     *
     * @return array{name: string, size: int, seconds: float, removed: list<string>, leftovers: list<string>}
     * @throws DatabaseBackupException
     */
    public function run(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $started = microtime(true);

        $this->validateDirectory();
        $db = self::parseDatabaseUrl($this->databaseUrl);
        $dir = $this->dir();

        $leftovers = $this->removeLeftovers();

        $name = self::fileNameFor($now);
        $final = $dir . '/' . $name;
        $part = $final . '.part';
        $optionsFile = $dir . '/' . self::OPTIONS_FILE;

        $oldUmask = umask(0077);
        try {
            $this->writeOptionsFile($optionsFile, $db);
            $this->dump($optionsFile, $db, $part);

            $check = new Process([$this->gzipBinary, '-t', $part]);
            $check->setTimeout(3600);
            $check->run();
            if (!$check->isSuccessful()) {
                throw new DatabaseBackupException('O arquivo comprimido não passou no gzip -t: backup descartado.');
            }

            if (!rename($part, $final)) {
                throw new DatabaseBackupException('Não foi possível renomear o arquivo .part para o nome final.');
            }
            chmod($final, 0600);
        } catch (\Throwable $e) {
            @unlink($part);
            throw $e instanceof DatabaseBackupException ? $e : new DatabaseBackupException($this->sanitize($e->getMessage(), $db), 0, $e);
        } finally {
            @unlink($optionsFile);
            umask($oldUmask);
        }

        clearstatcache(true, $final);
        $size = (int) filesize($final);

        // Só depois de um backup bem-sucedido
        $removed = [];
        foreach (self::selectExpired(array_column($this->listBackups(), 'name'), $now) as $old) {
            if ($old !== $name && @unlink($dir . '/' . $old)) {
                $removed[] = $old;
            }
        }

        return [
            'name' => $name,
            'size' => $size,
            'seconds' => microtime(true) - $started,
            'removed' => $removed,
            'leftovers' => $leftovers,
        ];
    }

    /**
     * @return array{host: ?string, port: ?int, user: ?string, password: ?string, dbname: string, socket: ?string}
     * @throws DatabaseBackupException
     */
    public static function parseDatabaseUrl(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme']) || !str_starts_with($parts['scheme'], 'mysql')) {
            throw new DatabaseBackupException('DATABASE_URL não é uma URL do MySQL.');
        }
        $dbname = isset($parts['path']) ? rawurldecode(ltrim($parts['path'], '/')) : '';
        if ($dbname === '') {
            throw new DatabaseBackupException('DATABASE_URL não traz o nome do banco.');
        }
        parse_str($parts['query'] ?? '', $query);

        return [
            'host' => isset($parts['host']) ? rawurldecode($parts['host']) : null,
            'port' => isset($parts['port']) ? (int) $parts['port'] : null,
            'user' => isset($parts['user']) ? rawurldecode($parts['user']) : null,
            'password' => isset($parts['pass']) ? rawurldecode($parts['pass']) : null,
            'dbname' => $dbname,
            'socket' => isset($query['unix_socket']) && is_string($query['unix_socket']) ? $query['unix_socket'] : null,
        ];
    }

    /** Conteúdo do arquivo de opções do MySQL (grupo [client]). */
    public static function optionsFileContent(array $db): string
    {
        $quote = fn(string $v) => '"' . str_replace('\\', '\\\\', $v) . '"';
        $lines = ['[client]'];
        foreach (['user' => 'user', 'password' => 'password', 'host' => 'host', 'socket' => 'socket'] as $key => $option) {
            if (($db[$key] ?? null) !== null && $db[$key] !== '') {
                $lines[] = $option . '=' . $quote((string) $db[$key]);
            }
        }
        if (!empty($db['port'])) {
            $lines[] = 'port=' . (int) $db['port'];
        }

        return implode("\n", $lines) . "\n";
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 ** 3) {
            return number_format($bytes / 1024 ** 3, 2, ',', '.') . ' GB';
        }
        if ($bytes >= 1024 ** 2) {
            return number_format($bytes / 1024 ** 2, 1, ',', '.') . ' MB';
        }

        return number_format($bytes / 1024, 1, ',', '.') . ' KB';
    }

    private function dir(): string
    {
        $dir = trim($this->backupDir);

        return $dir === '/' ? $dir : rtrim($dir, '/');
    }

    /** Sobras de uma execução interrompida: arquivo .part e arquivo de opções. */
    private function removeLeftovers(): array
    {
        $removed = [];
        foreach (scandir($this->dir()) ?: [] as $name) {
            if ($name === self::OPTIONS_FILE || preg_match(self::PART_PATTERN, $name)) {
                if (@unlink($this->dir() . '/' . $name)) {
                    $removed[] = $name;
                }
            }
        }

        return $removed;
    }

    private function writeOptionsFile(string $path, array $db): void
    {
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            throw new DatabaseBackupException('Não foi possível criar o arquivo de opções temporário na pasta de backups.');
        }
        chmod($path, 0600);
        fwrite($handle, self::optionsFileContent($db));
        fclose($handle);
    }

    /** mysqldump | gzip > .part, sem shell e sem a credencial na linha de comando. */
    private function dump(string $optionsFile, array $db, string $part): void
    {
        $out = @fopen($part, 'xb');
        if ($out === false) {
            throw new DatabaseBackupException('Não foi possível criar o arquivo .part na pasta de backups.');
        }
        chmod($part, 0600);
        $dumpErr = tmpfile();
        $gzipErr = tmpfile();

        $gzip = proc_open([$this->gzipBinary, '-c'], [0 => ['pipe', 'r'], 1 => $out, 2 => $gzipErr], $gzipPipes);
        if (!is_resource($gzip)) {
            fclose($out);
            throw new DatabaseBackupException('Não foi possível iniciar o gzip.');
        }

        // --defaults-extra-file tem de ser a primeira opção
        $dump = proc_open([
            $this->mysqldumpBinary,
            '--defaults-extra-file=' . $optionsFile,
            '--single-transaction',
            '--no-tablespaces',
            $db['dbname'],
        ], [0 => ['file', '/dev/null', 'r'], 1 => $gzipPipes[0], 2 => $dumpErr], $dumpPipes);
        // O gzip só recebe o fim da entrada quando ninguém mais segura a ponta de escrita
        fclose($gzipPipes[0]);

        $dumpCode = is_resource($dump) ? proc_close($dump) : -1;
        $gzipCode = proc_close($gzip);
        fclose($out);

        if ($dumpCode !== 0) {
            rewind($dumpErr);
            $msg = trim((string) stream_get_contents($dumpErr));
            throw new DatabaseBackupException(sprintf('mysqldump terminou com código %d: %s', $dumpCode, $this->sanitize($this->lastLines($msg), $db)));
        }
        if ($gzipCode !== 0) {
            rewind($gzipErr);
            throw new DatabaseBackupException(sprintf('gzip terminou com código %d: %s', $gzipCode, trim((string) stream_get_contents($gzipErr))));
        }
    }

    private function lastLines(string $text, int $n = 5): string
    {
        $lines = array_filter(explode("\n", $text), fn(string $l) => trim($l) !== '');

        return implode(' | ', array_slice($lines, -$n));
    }

    /** Tira usuário e senha de qualquer mensagem que vá para a saída. */
    private function sanitize(string $message, array $db): string
    {
        foreach (['password', 'user'] as $key) {
            $value = (string) ($db[$key] ?? '');
            if ($value !== '') {
                $message = str_replace($value, '***', $message);
            }
        }

        return $message;
    }
}
