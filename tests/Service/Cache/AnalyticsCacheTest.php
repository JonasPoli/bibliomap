<?php

namespace App\Tests\Service\Cache;

use App\Service\Cache\AnalyticsCache;
use App\Service\Cache\DataVersionTracker;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class AnalyticsCacheTest extends TestCase
{
    /** @var list<string|false|\Throwable> versões devolvidas pelas próximas leituras */
    private array $versions = [];

    private function cache(?DataVersionTracker $tracker = null, ?ArrayAdapter $pool = null): AnalyticsCache
    {
        $conn = $this->createStub(Connection::class);
        $conn->method('fetchOne')->willReturnCallback(function () {
            $v = count($this->versions) > 1 ? array_shift($this->versions) : $this->versions[0];
            if ($v instanceof \Throwable) {
                throw $v;
            }

            return $v;
        });

        return new AnalyticsCache($pool ?? new ArrayAdapter(), $conn, $tracker ?? new DataVersionTracker());
    }

    public function testSegundaChamadaComMesmaVersaoNaoRecalcula(): void
    {
        $this->versions = ['7'];
        $cache = $this->cache();
        $calls = 0;
        $compute = function () use (&$calls) { return ['n' => ++$calls]; };

        $this->assertSame(['n' => 1], $cache->get(13, 'report.general', [], $compute));
        $this->assertSame(['n' => 1], $cache->get(13, 'report.general', [], $compute));
        $this->assertSame(1, $calls);
    }

    public function testProjetoNomeEParametrosSeparamOsResultados(): void
    {
        $this->versions = ['7'];
        $cache = $this->cache();
        $calls = 0;
        $compute = function () use (&$calls) { return ++$calls; };

        $cache->get(13, 'network.keywords', ['limit' => 100, 'type' => 'author'], $compute);
        $cache->get(13, 'network.keywords', ['type' => 'author', 'limit' => 100], $compute); // mesma chave, outra ordem
        $this->assertSame(1, $calls);
        $cache->get(13, 'network.keywords', ['limit' => 50, 'type' => 'author'], $compute);
        $cache->get(11, 'network.keywords', ['limit' => 100, 'type' => 'author'], $compute);
        $cache->get(13, 'network.coauthorship', ['limit' => 100, 'type' => 'author'], $compute);
        $this->assertSame(4, $calls);
    }

    public function testVersaoNovaRecalcula(): void
    {
        $cache = $this->cache();
        $calls = 0;
        $compute = function () use (&$calls) { return ++$calls; };

        $this->versions = ['7'];
        $this->assertSame(1, $cache->get(13, 'x', [], $compute));
        $this->versions = ['8'];
        $this->assertSame(2, $cache->get(13, 'x', [], $compute));
        $this->assertSame(2, $cache->get(13, 'x', [], $compute));
    }

    public function testNaoGuardaQuandoAVersaoMudaDuranteOCalculo(): void
    {
        $cache = $this->cache();
        $calls = 0;
        $compute = function () use (&$calls) { return ++$calls; };

        // 1ª leitura: 7 (antes do cálculo); 2ª: 8 (alguém gravou durante o cálculo)
        $this->versions = ['7', '8', '8'];
        $this->assertSame(1, $cache->get(13, 'x', [], $compute));
        // Na versão 8 não há nada guardado: calcula de novo
        $this->assertSame(2, $cache->get(13, 'x', [], $compute));
    }

    public function testSemVersaoCalculaSempre(): void
    {
        $cache = $this->cache();
        $calls = 0;
        $compute = function () use (&$calls) { return ++$calls; };

        $this->versions = [new \RuntimeException("Table 'analytics_data_version' doesn't exist")];
        $this->assertSame(1, $cache->get(13, 'x', [], $compute));
        $this->assertSame(2, $cache->get(13, 'x', [], $compute));
    }

    public function testLinhaAindaNaoCriadaUsaVersaoZero(): void
    {
        $this->versions = [false];
        $cache = $this->cache();
        $calls = 0;
        $compute = function () use (&$calls) { return ++$calls; };
        $cache->get(13, 'x', [], $compute);
        $cache->get(13, 'x', [], $compute);
        $this->assertSame(1, $calls);
    }

    public function testGravacaoPendenteEmTransacaoNaoUsaCache(): void
    {
        $driver = $this->createMock(DriverConnection::class);
        $driver->expects($this->never())->method('exec');
        $tracker = new DataVersionTracker();
        $tracker->setConnection($driver);
        $tracker->transactionStarted();
        $tracker->statementExecuted('DELETE FROM document_classification WHERE project_id = 13');

        $this->versions = ['7'];
        $pool = new ArrayAdapter();
        $cache = $this->cache($tracker, $pool);
        $calls = 0;
        $compute = function () use (&$calls) { return ++$calls; };
        $cache->get(13, 'x', [], $compute);
        $cache->get(13, 'x', [], $compute);
        $this->assertSame(2, $calls);
        $this->assertCount(0, $pool->getValues());
    }

    public function testFalhaDoCacheNaoDerrubaATela(): void
    {
        $this->versions = ['7'];
        $pool = $this->createStub(\Psr\Cache\CacheItemPoolInterface::class);
        $pool->method('getItem')->willThrowException(new \RuntimeException('disco cheio'));
        $conn = $this->createStub(Connection::class);
        $conn->method('fetchOne')->willReturn('7');
        $cache = new AnalyticsCache($pool, $conn, new DataVersionTracker());

        $this->assertSame('ok', $cache->get(13, 'x', [], fn () => 'ok'));
    }
}
