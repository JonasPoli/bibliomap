<?php

namespace App\Doctrine;

use App\Service\Cache\DataVersionTracker;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * Avisa o DataVersionTracker de cada comando SQL executado e de cada transação, para que a
 * versão dos dados suba quando o sistema grava nas tabelas que alimentam as análises.
 */
#[AsMiddleware]
final class DataVersionMiddleware implements Middleware
{
    public function __construct(private readonly DataVersionTracker $tracker)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        $tracker = $this->tracker;

        return new class($driver, $tracker) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly DataVersionTracker $tracker)
            {
                parent::__construct($driver);
            }

            public function connect(#[\SensitiveParameter] array $params): Connection
            {
                $inner = parent::connect($params);
                // O contador sobe pela conexão interna, sem passar de novo por este middleware
                $this->tracker->setConnection($inner);
                $tracker = $this->tracker;

                return new class($inner, $tracker) extends AbstractConnectionMiddleware {
                    public function __construct(Connection $connection, private readonly DataVersionTracker $tracker)
                    {
                        parent::__construct($connection);
                    }

                    public function prepare(string $sql): Statement
                    {
                        $tracker = $this->tracker;

                        return new class(parent::prepare($sql), $tracker, $sql) extends AbstractStatementMiddleware {
                            public function __construct(
                                Statement $statement,
                                private readonly DataVersionTracker $tracker,
                                private readonly string $sql,
                            ) {
                                parent::__construct($statement);
                            }

                            public function execute(): Result
                            {
                                $result = parent::execute();
                                $this->tracker->statementExecuted($this->sql);

                                return $result;
                            }
                        };
                    }

                    public function query(string $sql): Result
                    {
                        $result = parent::query($sql);
                        $this->tracker->statementExecuted($sql);

                        return $result;
                    }

                    public function exec(string $sql): int|string
                    {
                        $result = parent::exec($sql);
                        $this->tracker->statementExecuted($sql);

                        return $result;
                    }

                    public function beginTransaction(): void
                    {
                        parent::beginTransaction();
                        $this->tracker->transactionStarted();
                    }

                    public function commit(): void
                    {
                        parent::commit();
                        $this->tracker->transactionEnded();
                    }

                    public function rollBack(): void
                    {
                        parent::rollBack();
                        $this->tracker->transactionEnded();
                    }
                };
            }
        };
    }
}
