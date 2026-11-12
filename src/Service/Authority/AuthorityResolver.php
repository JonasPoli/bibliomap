<?php

namespace App\Service\Authority;

use App\Service\Cache\DataVersionTracker;
use Doctrine\DBAL\Connection;

/**
 * Resolve e grava a identidade efetiva dos vínculos (document_author.effective_author_id e
 * document_keyword.effective_keyword_id). As colunas são derivadas e reconstruíveis; o dado bruto
 * (author_identity_id, keyword_id) nunca é alterado.
 *
 * Ordem de resolução, um salto por camada e alvo final (sem encadeamento):
 *   1. mapeamento do projeto   (authority_mapping.scope = 'project', scope_id = document.project_id)
 *   2. mapeamento do usuário   (scope = 'user', scope_id = dono do projeto)
 *   3. mapeamento global       (scope = 'global', scope_id = 0)
 *   4. o próprio id bruto
 * A origem do mapeamento (source_id) é o id bruto do vínculo: author_identity_id para autor e keyword.id
 * para palavra-chave. Para palavra-chave, o "id bruto" da camada 4 e o formato do alvo são os de hoje:
 * IF(k.thesaurus_concept_id IS NULL, COALESCE(k.keyword_concept_id, k.id), -k.thesaurus_concept_id)
 * (positivo = keyword.id efetivo, negativo = -thesaurus_concept.id). Com authority_mapping vazia o resultado
 * é exatamente o de antes.
 *
 * O UPDATE de cada faixa da chave primária só toca as linhas cujo valor mudaria, para não reescrever
 * milhões de linhas sem necessidade. Nada é carregado em memória: cada faixa é uma única instrução SQL.
 */
class AuthorityResolver
{
    public const ENTITY_AUTHOR = 'author';
    public const ENTITY_KEYWORD = 'keyword';
    public const ENTITIES = [self::ENTITY_AUTHOR, self::ENTITY_KEYWORD];

    public const DEFAULT_BATCH = 50000;

    /** entidade => [tabela de vínculo, alias, coluna efetiva] */
    private const LINKS = [
        self::ENTITY_AUTHOR => ['document_author', 'da', 'effective_author_id'],
        self::ENTITY_KEYWORD => ['document_keyword', 'dk', 'effective_keyword_id'],
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly ?DataVersionTracker $tracker = null,
    ) {
    }

    /** Expressão atual do id efetivo da palavra-chave (a mesma de KeywordResolverService::buildSqlCoalesceId). */
    public static function keywordRawExpression(string $kwAlias = 'k'): string
    {
        return "IF({$kwAlias}.thesaurus_concept_id IS NULL, COALESCE({$kwAlias}.keyword_concept_id, {$kwAlias}.id), -{$kwAlias}.thesaurus_concept_id)";
    }

    /** Expressão SQL do id efetivo calculado (usa os aliases de fromClause()). */
    public function effectiveExpression(string $entity): string
    {
        $this->assertEntity($entity);
        $raw = $entity === self::ENTITY_AUTHOR ? self::LINKS[$entity][1] . '.author_identity_id' : self::keywordRawExpression('k');

        return "COALESCE(mp.target_id, mu.target_id, mg.target_id, {$raw})";
    }

    /**
     * FROM ... JOINs do vínculo com documento, projeto, (palavra-chave) e as três camadas de mapeamento.
     * Aliases: da|dk (vínculo), d (documento), p (projeto), k (palavra-chave), mp, mu, mg.
     */
    public function fromClause(string $entity): string
    {
        $this->assertEntity($entity);
        [$table, $a] = self::LINKS[$entity];
        $source = $entity === self::ENTITY_AUTHOR ? "{$a}.author_identity_id" : "{$a}.keyword_id";
        $kw = $entity === self::ENTITY_KEYWORD ? " JOIN keyword k ON k.id = {$a}.keyword_id" : '';
        $q = $this->connection->quote($entity);

        return "{$table} {$a}"
            . " JOIN document d ON d.id = {$a}.document_id"
            . " JOIN bibliometric_project p ON p.id = d.project_id"
            . $kw
            . " LEFT JOIN authority_mapping mp ON mp.entity_type = {$q} AND mp.scope = 'project' AND mp.scope_id = d.project_id AND mp.source_id = {$source}"
            . " LEFT JOIN authority_mapping mu ON mu.entity_type = {$q} AND mu.scope = 'user' AND mu.scope_id = p.user_id AND mu.source_id = {$source}"
            . " LEFT JOIN authority_mapping mg ON mg.entity_type = {$q} AND mg.scope = 'global' AND mg.scope_id = 0 AND mg.source_id = {$source}";
    }

    /** UPDATE da faixa [:lo, :hi] da chave primária; só linhas cujo valor mudaria. Parâmetro opcional :project. */
    public function updateRangeSql(string $entity, bool $byProject = false): string
    {
        [, $a, $col] = self::LINKS[$entity];
        $expr = $this->effectiveExpression($entity);
        $proj = $byProject ? ' AND d.project_id = :project' : '';

        // UPDATE com junção: o MySQL avalia o WHERE antes de atribuir
        return 'UPDATE ' . $this->fromClause($entity)
            . " SET {$a}.{$col} = {$expr}"
            . " WHERE {$a}.id BETWEEN :lo AND :hi{$proj} AND {$a}.{$col} <> {$expr}";
    }

    /** Conta, na faixa, as linhas cujo valor gravado difere do calculado. */
    public function countDivergentRangeSql(string $entity, bool $byProject = false): string
    {
        [, $a, $col] = self::LINKS[$entity];
        $expr = $this->effectiveExpression($entity);
        $proj = $byProject ? ' AND d.project_id = :project' : '';

        return "SELECT COUNT(*) FROM " . $this->fromClause($entity)
            . " WHERE {$a}.id BETWEEN :lo AND :hi{$proj} AND {$a}.{$col} <> {$expr}";
    }

    /**
     * Limites [min, max] da chave primária do vínculo (do projeto, se informado); null se não há linhas.
     *
     * @return array{0:int,1:int}|null
     */
    public function idBounds(string $entity, ?int $projectId = null): ?array
    {
        $this->assertEntity($entity);
        [$table, $a] = self::LINKS[$entity];
        if ($projectId === null) {
            $row = $this->connection->fetchAssociative("SELECT MIN(id) lo, MAX(id) hi FROM {$table}");
        } else {
            $row = $this->connection->fetchAssociative(
                "SELECT MIN({$a}.id) lo, MAX({$a}.id) hi FROM {$table} {$a} JOIN document d ON d.id = {$a}.document_id WHERE d.project_id = ?",
                [$projectId]
            );
        }

        return $row && $row['lo'] !== null ? [(int) $row['lo'], (int) $row['hi']] : null;
    }

    /**
     * Percorre as faixas e chama $onRange(lo, hi, afetadas, tempoSegundos) a cada faixa.
     * Em $write = false só conta as divergências (nada é gravado). Devolve [examinadas, alteradas/divergentes].
     * "Examinadas" são as linhas existentes (do projeto, se informado) na faixa total, contadas uma vez.
     *
     * @param callable(int,int,int,float):void|null $onRange
     * @return array{examined:int,changed:int}
     */
    public function process(string $entity, bool $write, ?int $projectId = null, int $batch = self::DEFAULT_BATCH, ?callable $onRange = null): array
    {
        $this->assertEntity($entity);
        $batch = max(1, $batch);
        [$table, $a] = self::LINKS[$entity];

        if ($projectId === null) {
            $examined = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM {$table}");
        } else {
            $examined = (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM {$table} {$a} JOIN document d ON d.id = {$a}.document_id WHERE d.project_id = ?",
                [$projectId]
            );
        }
        $bounds = $this->idBounds($entity, $projectId);
        if ($bounds === null) {
            return ['examined' => 0, 'changed' => 0];
        }

        $sql = $write ? $this->updateRangeSql($entity, $projectId !== null) : $this->countDivergentRangeSql($entity, $projectId !== null);
        $changed = 0;
        $suspended = $write && $this->tracker !== null;
        if ($suspended) {
            $this->tracker->suspend();
        }
        try {
            for ($lo = $bounds[0]; $lo <= $bounds[1]; $lo += $batch) {
                $hi = min($lo + $batch - 1, $bounds[1]);
                $params = ['lo' => $lo, 'hi' => $hi];
                if ($projectId !== null) {
                    $params['project'] = $projectId;
                }
                $t = microtime(true);
                $n = $write
                    ? (int) $this->connection->executeStatement($sql, $params)
                    : (int) $this->connection->fetchOne($sql, $params);
                $changed += $n;
                if ($onRange !== null) {
                    $onRange($lo, $hi, $n, microtime(true) - $t);
                }
            }
        } finally {
            if ($suspended) {
                $this->tracker->resume();
            }
        }
        // As colunas efetivas mudaram: as análises (que passarão a lê-las) precisam de nova versão do cache
        if ($write && $changed > 0) {
            $this->tracker?->markDataChanged();
        }

        return ['examined' => $examined, 'changed' => $changed];
    }

    /**
     * Recalcula o efetivo dos vínculos que satisfazem $where (usa os aliases de fromClause(): da|dk, d, p, k),
     * só gravando onde o valor muda. Serve aos caminhos de gravação (tarefa 1.3): importação, cópia, fusões e
     * enriquecimento chamam logo depois de criar ou repontuar vínculos, no mesmo comando e na mesma transação.
     * A gravação fica sob suspend() do rastreador (não marca "autoridade suja", que dispararia o recálculo geral).
     *
     * @param list<mixed> $params parâmetros posicionais de $where
     */
    public function refreshLinks(string $entity, string $where, array $params = []): int
    {
        $this->assertEntity($entity);
        [, $a, $col] = self::LINKS[$entity];
        $expr = $this->effectiveExpression($entity);
        $sql = 'UPDATE ' . $this->fromClause($entity) . " SET {$a}.{$col} = {$expr} WHERE ({$where}) AND {$a}.{$col} <> {$expr}";

        $this->tracker?->suspend();
        try {
            $n = (int) $this->connection->executeStatement($sql, $params);
        } finally {
            $this->tracker?->resume();
        }
        if ($n > 0) {
            $this->tracker?->markDataChanged();
        }

        return $n;
    }

    /** Recalcula autores e palavras-chave dos documentos informados (em blocos). */
    public function refreshDocuments(array $documentIds): void
    {
        $documentIds = array_values(array_unique(array_map('intval', $documentIds)));
        foreach (array_chunk($documentIds, 1000) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $this->refreshLinks(self::ENTITY_AUTHOR, "da.document_id IN ({$in})", $chunk);
            $this->refreshLinks(self::ENTITY_KEYWORD, "dk.document_id IN ({$in})", $chunk);
        }
    }

    /** Recalcula os vínculos com efetivo 0 (recém-repontuados) cujo id bruto é $rawId (autor: author_identity_id; palavra: keyword_id). */
    public function refreshPendingByRaw(string $entity, int $rawId): int
    {
        $this->assertEntity($entity);
        [, $a, $col] = self::LINKS[$entity];
        $raw = $entity === self::ENTITY_AUTHOR ? "{$a}.author_identity_id" : "{$a}.keyword_id";

        return $this->refreshLinks($entity, "{$raw} = ? AND {$a}.{$col} = 0", [$rawId]);
    }

    /** Recalcula todos os vínculos de uma palavra-chave (o conceito do tesauro dela mudou). */
    public function refreshByKeyword(int $keywordId): int
    {
        return $this->refreshLinks(self::ENTITY_KEYWORD, 'dk.keyword_id = ?', [$keywordId]);
    }

    /**
     * Efetivo de um conjunto de ids brutos para os vínculos de um projeto (uma consulta por bloco de 1000 ids).
     * Serve à importação, que grava o efetivo já no INSERT dos vínculos. Origem do mapeamento = id bruto
     * (autor: author_identity.id; palavra-chave: keyword.id). Id inexistente não aparece no resultado.
     *
     * @param list<int> $rawIds
     * @return array<int,int> id bruto => id efetivo
     */
    public function effectiveMap(string $entity, int $projectId, ?int $userId, array $rawIds): array
    {
        $this->assertEntity($entity);
        $rawIds = array_values(array_unique(array_map('intval', $rawIds)));
        $p = (int) $projectId;
        $u = (int) ($userId ?? 0);
        $q = $this->connection->quote($entity);
        if ($entity === self::ENTITY_AUTHOR) {
            $table = 'author_identity';
            $raw = 's.id';
        } else {
            $table = 'keyword';
            $raw = self::keywordRawExpression('s');
        }
        $out = [];
        foreach (array_chunk($rawIds, 1000) as $chunk) {
            $in = implode(',', $chunk);
            $sql = "SELECT s.id, COALESCE(mp.target_id, mu.target_id, mg.target_id, {$raw}) AS eff FROM {$table} s"
                . " LEFT JOIN authority_mapping mp ON mp.entity_type = {$q} AND mp.scope = 'project' AND mp.scope_id = {$p} AND mp.source_id = s.id"
                . " LEFT JOIN authority_mapping mu ON mu.entity_type = {$q} AND mu.scope = 'user' AND mu.scope_id = {$u} AND mu.source_id = s.id"
                . " LEFT JOIN authority_mapping mg ON mg.entity_type = {$q} AND mg.scope = 'global' AND mg.scope_id = 0 AND mg.source_id = s.id"
                . " WHERE s.id IN ({$in})";
            foreach ($this->connection->iterateKeyValue($sql) as $id => $eff) {
                $out[(int) $id] = (int) $eff;
            }
        }

        return $out;
    }

    /** Recálculo completo e incremental (só linhas que mudam) das duas colunas. Usado quando o tesauro muda. */
    public function rebuildAll(int $batch = self::DEFAULT_BATCH): array
    {
        $out = [];
        foreach (self::ENTITIES as $entity) {
            $out[$entity] = $this->process($entity, true, null, $batch);
        }

        return $out;
    }

    private function assertEntity(string $entity): void
    {
        if (!isset(self::LINKS[$entity])) {
            throw new \InvalidArgumentException("Tipo de autoridade inválido: {$entity}");
        }
    }
}
