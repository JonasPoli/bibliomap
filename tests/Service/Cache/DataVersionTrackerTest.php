<?php

namespace App\Tests\Service\Cache;

use App\Service\Cache\DataVersionTracker;
use Doctrine\DBAL\Driver\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DataVersionTrackerTest extends TestCase
{
    public static function sqlProvider(): array
    {
        return [
            'select' => ['SELECT * FROM document WHERE id = 1', null],
            'select com CTE' => ['WITH t AS (SELECT 1) SELECT * FROM t', null],
            'set de sessão' => ['SET SESSION group_concat_max_len = 1048576', null],
            'trava' => ["SELECT GET_LOCK('bibliomap_import_dataset_1', 0)", null],
            'insert' => ['INSERT INTO document (title) VALUES (?)', 'document'],
            'insert ignore com crase' => ['INSERT IGNORE INTO `document_keyword` (a) VALUES (1)', 'document_keyword'],
            'insert select' => ['insert into documento_paises (document_id, country_id) select 1, 2', 'documento_paises'],
            'replace' => ['REPLACE INTO keyword (id) VALUES (1)', 'keyword'],
            'update simples' => ['UPDATE keyword SET status = 0 WHERE id = ?', 'keyword'],
            'update com junção' => ['UPDATE document d JOIN qualis_journal q ON q.id = d.qualis_journal_id SET d.qualis = q.qualis', ''],
            'delete simples' => ['DELETE FROM document WHERE dataset_id = ?', 'document'],
            'delete com alias' => ['DELETE dk FROM document_keyword dk JOIN document d ON d.id = dk.document_id WHERE d.project_id = 1', ''],
            'truncate' => ['TRUNCATE TABLE keyword', 'keyword'],
            'cte com update' => ['WITH x AS (SELECT 1) UPDATE keyword SET status = 1', ''],
            'ddl' => ['ALTER TABLE document ADD x INT', ''],
            'tabela temporária' => ['CREATE TEMPORARY TABLE tmp_ids (id INT)', null],
            'comentário antes' => ["/* lote */ INSERT INTO keyword (id) VALUES (1)", 'keyword'],
            'espaços e quebra' => ["\n   UPDATE\n keyword SET x = 1", 'keyword'],
        ];
    }

    #[DataProvider('sqlProvider')]
    public function testWrittenTable(string $sql, ?string $expected): void
    {
        $this->assertSame($expected, DataVersionTracker::writtenTable($sql));
    }

    public function testGravacaoEmTabelaIgnoradaNaoContaComoRelevante(): void
    {
        $this->assertFalse(DataVersionTracker::isRelevantWrite('UPDATE user SET last_login_at = NOW() WHERE id = 1'));
        $this->assertFalse(DataVersionTracker::isRelevantWrite('INSERT INTO keyword_treatment_log (a) VALUES (1)'));
        $this->assertFalse(DataVersionTracker::isRelevantWrite(DataVersionTracker::BUMP_SQL));
        $this->assertTrue(DataVersionTracker::isRelevantWrite('UPDATE keyword SET status = 0'));
        $this->assertTrue(DataVersionTracker::isRelevantWrite('UPDATE a JOIN b ON 1 SET b.x = 1'));
    }

    public function testSobeAVersaoDepoisDeGravacaoForaDeTransacao(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())->method('exec')->with(DataVersionTracker::BUMP_SQL)->willReturn(1);

        $tracker = new DataVersionTracker();
        $tracker->setConnection($conn);
        $tracker->statementExecuted('SELECT 1');
        $this->assertFalse($tracker->isDirty());

        $tracker->statementExecuted('INSERT INTO document (title) VALUES (?)');
        $this->assertFalse($tracker->isDirty(), 'a primeira gravação fora de transação sobe a versão na hora');
    }

    public function testDentroDeTransacaoSoSobeDepoisDoCommit(): void
    {
        $bumps = 0;
        $conn = $this->createStub(Connection::class);
        $conn->method('exec')->willReturnCallback(function () use (&$bumps) { return ++$bumps; });

        $tracker = new DataVersionTracker();
        $tracker->setConnection($conn);
        $tracker->transactionStarted();
        $tracker->statementExecuted('UPDATE keyword SET status = 0 WHERE id = 1');
        $this->assertSame(0, $bumps);
        $this->assertTrue($tracker->hasUncommittedWrites());

        $tracker->transactionEnded();
        $this->assertSame(1, $bumps);
        $this->assertFalse($tracker->isDirty());
    }

    public function testGravacoesSeguidasSobemNoMaximoUmaVezPorIntervaloEOFlushFecha(): void
    {
        $bumps = 0;
        $conn = $this->createStub(Connection::class);
        $conn->method('exec')->willReturnCallback(function () use (&$bumps) { return ++$bumps; });

        $tracker = new DataVersionTracker();
        $tracker->setConnection($conn);
        for ($i = 0; $i < 20; $i++) {
            $tracker->statementExecuted('INSERT INTO document_keyword (a) VALUES (1)');
        }
        $this->assertSame(1, $bumps, 'dentro do intervalo, só a primeira gravação sobe a versão');
        $this->assertTrue($tracker->isDirty());

        $tracker->flush();
        $this->assertSame(2, $bumps, 'o fim do processo sobe a versão das gravações restantes');
        $tracker->flush();
        $this->assertSame(2, $bumps, 'sem gravação nova, não sobe de novo');
    }

    public function testTransacaoPorSqlDireto(): void
    {
        $bumps = 0;
        $conn = $this->createStub(Connection::class);
        $conn->method('exec')->willReturnCallback(function () use (&$bumps) { return ++$bumps; });

        $tracker = new DataVersionTracker();
        $tracker->setConnection($conn);
        $tracker->statementExecuted('START TRANSACTION');
        $tracker->statementExecuted('DELETE FROM document WHERE id = 1');
        $tracker->statementExecuted('ROLLBACK TO SAVEPOINT s1');
        $this->assertSame(0, $bumps);
        $tracker->statementExecuted('COMMIT');
        $this->assertSame(1, $bumps);
    }

    public function testFalhaAoSubirAVersaoNaoPropaga(): void
    {
        $conn = $this->createStub(Connection::class);
        $conn->method('exec')->willThrowException(new \RuntimeException('tabela não existe'));

        $tracker = new DataVersionTracker();
        $tracker->setConnection($conn);
        $tracker->statementExecuted('INSERT INTO document (title) VALUES (?)');
        $this->assertTrue($tracker->isDirty());
    }
}
