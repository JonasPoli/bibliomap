<?php

namespace App\Tests\Service\Authority;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Dotenv\Dotenv;

/**
 * Base dos testes que usam o MySQL local "bibliomap" (o .env.local do desenvolvedor). Cada teste roda dentro de
 * uma transação que é desfeita no fim: nenhum dado existente muda e o diário volta à contagem de antes.
 * Sem o banco local (ou sem a migration Version20261112160000), o teste é pulado.
 */
abstract class LocalDbTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;
    protected Connection $conn;
    private int $logCountBefore = 0;

    protected function setUp(): void
    {
        $file = dirname(__DIR__, 3) . '/.env.local';
        if (!is_file($file)) {
            $this->markTestSkipped('Sem .env.local: teste do banco local pulado.');
        }
        $vars = (new Dotenv())->parse((string) file_get_contents($file));
        $url = $vars['DATABASE_URL'] ?? '';
        if (!str_starts_with($url, 'mysql')) {
            $this->markTestSkipped('DATABASE_URL local não é MySQL.');
        }
        putenv('DATABASE_URL=' . $url);
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = $url;

        self::bootKernel(['environment' => 'dev', 'debug' => false]);
        $this->em = self::$kernel->getContainer()->get('doctrine.orm.entity_manager');
        $this->conn = $this->em->getConnection();
        try {
            $this->logCountBefore = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM authority_merge_log');
            $this->conn->fetchOne('SELECT COUNT(*) FROM document_author LIMIT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('Banco local indisponível ou sem a migration do diário: ' . $e->getMessage());
        }
        $this->conn->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->conn)) {
            while ($this->conn->isTransactionActive()) {
                $this->conn->rollBack();
            }
            // Depois do rollback não sobra nada no diário
            $this->assertSame($this->logCountBefore, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM authority_merge_log'));
            $this->em->clear();
        }
        parent::tearDown();
    }

    /** @return list<int> */
    protected function pickIds(string $table, string $col, int $min, int $max, int $n, array $exclude = []): array
    {
        $ex = $exclude ? ' AND ' . $col . ' NOT IN (' . implode(',', array_map('intval', $exclude)) . ')' : '';

        return array_map('intval', $this->conn->fetchFirstColumn(
            "SELECT {$col} FROM {$table} WHERE 1=1 {$ex} GROUP BY {$col} HAVING COUNT(*) BETWEEN {$min} AND {$max} ORDER BY {$col} LIMIT {$n}"
        ));
    }

    /** Linhas do diário (cabeçalho) da entidade. */
    protected function logsFor(string $type, int $sourceId): array
    {
        return $this->conn->fetchAllAssociative(
            'SELECT * FROM authority_merge_log WHERE entity_type = ? AND source_id = ? ORDER BY id',
            [$type, $sourceId]
        );
    }

    protected function linksOf(int $logId): array
    {
        return $this->conn->fetchAllAssociative(
            'SELECT document_id, link_table, old_entity_id, original_text, position FROM authority_merge_log_link WHERE log_id = ? ORDER BY document_id, original_text',
            [$logId]
        );
    }

    protected function authorSnapshot(int $id): array
    {
        return $this->conn->fetchAllAssociative(
            'SELECT document_id, "document_author" AS link_table, author_identity_id AS old_entity_id, original_name AS original_text, `position` FROM document_author WHERE author_identity_id = ? ORDER BY document_id, original_name',
            [$id]
        );
    }

    protected function keywordSnapshot(int $id): array
    {
        return $this->conn->fetchAllAssociative(
            'SELECT document_id, "document_keyword" AS link_table, keyword_id AS old_entity_id, original_term AS original_text, NULL AS `position` FROM document_keyword WHERE keyword_id = ? ORDER BY document_id, original_term',
            [$id]
        );
    }

    protected function normalizeRows(array $rows): array
    {
        return array_map(fn($r) => [
            (int) $r['document_id'], $r['link_table'], (int) $r['old_entity_id'], $r['original_text'],
            $r['position'] === null ? null : (int) $r['position'],
        ], $rows);
    }
}
