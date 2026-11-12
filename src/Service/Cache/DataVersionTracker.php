<?php

namespace App\Service\Cache;

use Doctrine\DBAL\Driver\Connection as DriverConnection;

/**
 * Acompanha as gravações feitas por este processo nas tabelas que alimentam as análises e sobe
 * o contador de versão dos dados (tabela analytics_data_version). A versão entra na chave do
 * cache das análises (AnalyticsCache): toda gravação torna inalcançável o que foi calculado antes.
 *
 * A detecção acontece no middleware da conexão (DataVersionMiddleware), então vale para qualquer
 * gravação feita pelo sistema (ORM, DBAL, comandos), sem depender de cada ponto de escrita lembrar
 * de invalidar. O contador sobe DEPOIS que a gravação está confirmada (fora de transação), no
 * máximo uma vez a cada BUMP_INTERVAL segundos durante o processo e uma última vez no fim
 * (DataVersionSubscriber e função de encerramento).
 */
final class DataVersionTracker
{
    /** Intervalo mínimo, em segundos, entre duas subidas do contador no mesmo processo. */
    private const BUMP_INTERVAL = 2.0;

    /** Tabelas cuja gravação não muda o resultado de nenhuma análise. */
    private const IGNORED_TABLES = [
        'analytics_data_version',
        'user',
        'site_settings',
        'saved_matrix',
        'dataset_skip',
        'keyword_treatment_job',
        'keyword_treatment_log',
        'messenger_messages',
        'doctrine_migration_versions',
        'reset_password_request',
        'sessions',
    ];

    public const BUMP_SQL = 'INSERT INTO analytics_data_version (id, version, updated_at) VALUES (1, 1, NOW())
        ON DUPLICATE KEY UPDATE version = version + 1, updated_at = NOW()';

    private bool $dirty = false;
    private bool $inTransaction = false;
    private float $lastBump = 0.0;
    private ?DriverConnection $connection = null;
    private bool $shutdownRegistered = false;

    /**
     * Diz se o comando grava dados e em qual tabela.
     *
     * @return string|null null = não grava; '' = grava em tabela não identificada (tratado como
     *                     relevante); caso contrário, o nome da tabela em minúsculas
     */
    public static function writtenTable(string $sql): ?string
    {
        // Tira comentários e parênteses do começo
        $s = ltrim($sql);
        while (true) {
            if (str_starts_with($s, '/*')) {
                $end = strpos($s, '*/');
                if ($end === false) {
                    return '';
                }
                $s = ltrim(substr($s, $end + 2));
            } elseif (str_starts_with($s, '--') || str_starts_with($s, '#')) {
                $end = strpos($s, "\n");
                if ($end === false) {
                    return null;
                }
                $s = ltrim(substr($s, $end + 1));
            } elseif (str_starts_with($s, '(')) {
                $s = ltrim(substr($s, 1));
            } else {
                break;
            }
        }

        if (!preg_match('/^(INSERT|REPLACE|UPDATE|DELETE|TRUNCATE|ALTER|DROP|CREATE|RENAME|LOAD|CALL|WITH)\b/i', $s, $m)) {
            return null;
        }
        $verb = strtoupper($m[1]);
        $name = '`?([A-Za-z0-9_$]+)`?';

        switch ($verb) {
            case 'WITH':
                // CTE seguida de gravação; SELECT com CTE não grava
                return preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE)\b/i', $s) ? '' : null;
            case 'INSERT':
            case 'REPLACE':
                if (preg_match('/^(?:INSERT|REPLACE)\s+(?:(?:LOW_PRIORITY|DELAYED|HIGH_PRIORITY|IGNORE)\s+)*(?:INTO\s+)?' . $name . '/i', $s, $t)) {
                    return strtolower($t[1]);
                }
                return '';
            case 'UPDATE':
                // UPDATE com junção ou várias tabelas pode gravar em outra tabela que não a primeira
                if (preg_match('/^UPDATE\s+(?:(?:LOW_PRIORITY|IGNORE)\s+)*' . $name . '\s+SET\b/i', $s, $t)) {
                    return strtolower($t[1]);
                }
                return '';
            case 'DELETE':
                if (preg_match('/^DELETE\s+(?:(?:LOW_PRIORITY|QUICK|IGNORE)\s+)*FROM\s+' . $name . '\s*(?:WHERE\b|ORDER\b|LIMIT\b|;|$)/i', $s, $t)) {
                    return strtolower($t[1]);
                }
                return '';
            case 'TRUNCATE':
                if (preg_match('/^TRUNCATE\s+(?:TABLE\s+)?' . $name . '/i', $s, $t)) {
                    return strtolower($t[1]);
                }
                return '';
            case 'CREATE':
            case 'DROP':
                // Tabela temporária é da sessão: não muda dado nenhum
                if (preg_match('/^(?:CREATE|DROP)\s+TEMPORARY\b/i', $s)) {
                    return null;
                }
                return '';
            default:
                return '';
        }
    }

    public static function isRelevantWrite(string $sql): bool
    {
        $table = self::writtenTable($sql);

        return $table !== null && !in_array($table, self::IGNORED_TABLES, true);
    }

    public function setConnection(DriverConnection $connection): void
    {
        $this->connection = $connection;
    }

    public function transactionStarted(): void
    {
        $this->inTransaction = true;
    }

    public function transactionEnded(): void
    {
        $this->inTransaction = false;
        $this->bumpIfDue();
    }

    /** Chamado pelo middleware depois que um comando SQL terminou sem erro. */
    public function statementExecuted(string $sql): void
    {
        // Transação aberta ou fechada por SQL direto, sem passar pela API da conexão
        if (preg_match('/^\s*(START\s+TRANSACTION|BEGIN|COMMIT|ROLLBACK(?!\s+TO))\b/i', $sql, $m)) {
            $start = in_array(strtoupper(substr($m[1], 0, 5)), ['START', 'BEGIN'], true);
            $start ? $this->transactionStarted() : $this->transactionEnded();

            return;
        }

        if (!self::isRelevantWrite($sql)) {
            return;
        }

        $this->dirty = true;
        if (!$this->shutdownRegistered) {
            // Última chance quando o processo termina por erro fatal, sem passar pelos eventos
            register_shutdown_function($this->flush(...));
            $this->shutdownRegistered = true;
        }
        $this->bumpIfDue();
    }

    public function isDirty(): bool
    {
        return $this->dirty;
    }

    /** Há gravação deste processo ainda dentro de transação aberta (pode ser desfeita). */
    public function hasUncommittedWrites(): bool
    {
        return $this->dirty && $this->inTransaction;
    }

    /** Sobe o contador agora, se este processo gravou algo desde a última subida. */
    public function flush(): void
    {
        if (!$this->dirty || $this->connection === null) {
            return;
        }
        try {
            $this->connection->exec(self::BUMP_SQL);
            $this->dirty = false;
            $this->lastBump = microtime(true);
        } catch (\Throwable) {
            // Tabela ainda não criada (deploy antes da migration) ou conexão perdida: o cache
            // tem validade curta e o AnalyticsCache não grava quando não consegue ler a versão.
        }
    }

    private function bumpIfDue(): void
    {
        if (!$this->dirty || $this->inTransaction) {
            return;
        }
        if (microtime(true) - $this->lastBump >= self::BUMP_INTERVAL) {
            $this->flush();
        }
    }
}
