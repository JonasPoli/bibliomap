<?php

namespace App\Service\Cache;

use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\PruneableInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Cache dos resultados das análises (relatórios, painel, redes, mapa temático, matrizes).
 *
 * A chave leva o projeto, o nome da análise, os parâmetros e a versão dos dados
 * (analytics_data_version, mantida pelo DataVersionTracker). Qualquer gravação nas tabelas que
 * alimentam as análises sobe a versão, então um resultado antigo nunca é devolvido: ele só deixa
 * de ser alcançável e expira sozinho. Se a versão não puder ser lida (tabela ainda não criada,
 * banco fora), o resultado é calculado na hora, sem cache.
 */
class AnalyticsCache
{
    /** Validade de um resultado, em segundos (rede de segurança além da versão). */
    private const TTL = 43200;

    /** Resultado maior que isto (serializado) não é guardado. */
    private const MAX_BYTES = 4_000_000;

    /** Uma em cada N gravações no cache apaga do disco os itens vencidos. */
    private const PRUNE_ONE_IN = 50;

    public function __construct(
        #[Autowire(service: 'cache.analytics')]
        private readonly CacheItemPoolInterface $pool,
        private readonly Connection $conn,
        private readonly DataVersionTracker $tracker,
    ) {
    }

    /**
     * Devolve o resultado guardado para (projeto, análise, parâmetros) na versão atual dos dados,
     * ou calcula com $compute e guarda.
     *
     * @template T
     * @param array<string|int, mixed> $params parâmetros que mudam o resultado (escalares e arrays)
     * @param callable(): T $compute
     * @return T
     */
    public function get(int $projectId, string $name, array $params, callable $compute): mixed
    {
        $version = $this->currentVersion();
        if ($version === null) {
            return $compute();
        }

        try {
            $key = $this->key($projectId, $name, $params, $version);
            $item = $this->pool->getItem($key);
            if ($item->isHit()) {
                return $item->get();
            }
        } catch (\Throwable) {
            return $compute();
        }

        $value = $compute();

        try {
            // Se algo foi gravado durante o cálculo, o resultado pode misturar os dois estados
            if ($this->currentVersion() !== $version) {
                return $value;
            }
            if (strlen(serialize($value)) > self::MAX_BYTES) {
                return $value;
            }
            $item->set($value);
            $item->expiresAfter(self::TTL);
            $this->pool->save($item);
            if ($this->pool instanceof PruneableInterface && random_int(1, self::PRUNE_ONE_IN) === 1) {
                $this->pool->prune();
            }
        } catch (\Throwable) {
            // Falha ao guardar (disco cheio, valor não serializável) não pode derrubar a tela
        }

        return $value;
    }

    /** Versão atual dos dados, ou null quando não dá para saber (não usa cache). */
    public function currentVersion(): ?string
    {
        try {
            // Dentro de transação com gravação pendente o resultado pode ser desfeito: sem cache
            if ($this->tracker->hasUncommittedWrites()) {
                return null;
            }
            // Gravações deste mesmo processo ainda não contadas entram antes de ler a versão
            $this->tracker->flush();
            if ($this->tracker->isDirty()) {
                return null;
            }
            $version = $this->conn->fetchOne('SELECT version FROM analytics_data_version WHERE id = 1');

            // Linha ainda não criada: nada foi gravado desde a migration
            return $version === false ? '0' : (string) $version;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string|int, mixed> $params
     */
    private function key(int $projectId, string $name, array $params, string $version): string
    {
        ksort($params);
        $hash = hash('xxh128', $version . '|' . json_encode($params, JSON_THROW_ON_ERROR));

        return sprintf('p%d.%s.%s', $projectId, preg_replace('/[^A-Za-z0-9_]/', '_', $name), $hash);
    }
}
