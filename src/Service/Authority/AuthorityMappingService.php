<?php

namespace App\Service\Authority;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Agrupa autores e palavras-chave SEM apagar nada (change tesauro-em-camadas, fase 2): mesclar B em A grava o
 * agrupamento e recalcula a identidade efetiva dos vínculos. A identidade B, as variações dela e o id bruto
 * dos vínculos (author_identity_id, keyword_id) continuam como estão.
 *
 * ONDE O AGRUPAMENTO É GRAVADO
 *  - autor (qualquer escopo) e palavra-chave de escopo project/user: uma linha de authority_mapping
 *    (UNIQUE entity_type, scope, scope_id, source_id). Alvo no formato do id efetivo (palavra-chave: positivo =
 *    keyword.id, negativo = -thesaurus_concept.id).
 *  - palavra-chave GLOBAL: o mecanismo que o sistema já lê, keyword.keyword_concept_id (conceito agrupador,
 *    sempre o alvo final): B.keyword_concept_id = A e quem apontava para B passa a apontar para A. Isso só vale
 *    quando B não tem conceito de tesauro e o efetivo de A é positivo (a convenção do efetivo é
 *    IF(thesaurus_concept_id IS NULL, COALESCE(keyword_concept_id, id), -thesaurus_concept_id)). Quando B tem
 *    conceito do tesauro, ou A está num conceito (efetivo negativo), o keyword_concept_id não consegue
 *    representar o destino: nesse caso o agrupamento global vira uma linha de authority_mapping (que vence o
 *    tesauro na resolução), e os dependentes de B (keyword_concept_id = B) ganham uma linha cada, com a nota
 *    "via #B", que o desfazer apaga junto.
 *
 * REGRAS
 *  - Alvo final: ao gravar B→A, A vira o efetivo de A na mesma resolução do AuthorityResolver (projeto, usuário,
 *    global); todo agrupamento do mesmo escopo que tinha B como alvo passa a ter o alvo de A (sem encadeamento).
 *  - Recusa (AuthorityMappingException, mensagem em pt-BR): auto-mapeamento, laço (A já está agrupada em B) e B já
 *    agrupada em outro destino (desfaça antes). B já agrupada no mesmo destino é um no-op ('noop' => true).
 *  - Tudo numa transação (a do chamador, se houver, ou uma própria); qualquer falha desfaz tudo, inclusive o diário.
 *  - O diário (AuthorityMergeLogger) recebe ação 'merge' ou 'undo' (a coluna action é VARCHAR(30), sem migration),
 *    com o usuário quando houver. O rastreador de dados (DataVersionTracker) marca as gravações em keyword e
 *    authority_mapping sozinho; o recálculo do efetivo é feito aqui, no mesmo comando, só nos vínculos afetados.
 *  - Limite conhecido do desfazer: se C→B foi reapontado para C→A quando B foi agrupado em A, desfazer B→A não
 *    devolve C a B (C continua em A); o diário guarda 'retargeted' para quem precisar reabrir à mão.
 */
class AuthorityMappingService
{
    public const ENTITY_AUTHOR = AuthorityResolver::ENTITY_AUTHOR;
    public const ENTITY_KEYWORD = AuthorityResolver::ENTITY_KEYWORD;

    public const SCOPE_GLOBAL = 'global';
    public const SCOPE_USER = 'user';
    public const SCOPE_PROJECT = 'project';

    /** Origem do agrupamento (authority_mapping.origin) => origem no diário (authority_merge_log.origin). */
    public const ORIGINS = [
        'admin'     => 'screen_admin',
        'project'   => 'screen_project',
        'treatment' => 'treatment',
        'variation' => 'enrichment',
        'command'   => 'command',
    ];

    public const PER_PAGE = 100;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuthorityResolver $resolver,
        private readonly AuthorityMergeLogger $mergeLogger,
        private readonly ?Security $security = null,
    ) {
    }

    // ------------------------------------------------------------------ gravar

    /**
     * Agrupa a identidade $sourceId na identidade $targetId.
     *
     * @param string      $entityType 'author' | 'keyword'
     * @param string      $scope      'global' (scopeId 0), 'user' ou 'project' (scopeId = id do usuário ou do projeto)
     * @param string      $origin     'admin' | 'project' | 'treatment' | 'variation' | 'command'
     * @param int|null    $userId     autoria (nulo: o usuário logado nas origens admin/project; ninguém nas demais)
     * @return array{noop:bool,mechanism:string,source_id:int,target_id:int,mapping_id:?int,dependents:list<int>,retargeted:list<int>,affected_links:int}
     *         mechanism: 'mapping' (linha em authority_mapping) ou 'concept' (keyword.keyword_concept_id);
     *         target_id é o alvo FINAL (pode diferir de $targetId se A já estava agrupada); affected_links = vínculos cujo efetivo mudou
     * @throws AuthorityMappingException
     */
    public function merge(
        string $entityType,
        int $sourceId,
        int $targetId,
        string $scope,
        int $scopeId,
        string $origin,
        ?int $userId = null,
        ?string $note = null,
    ): array {
        $this->assertEntity($entityType);
        $scopeId = $this->assertScope($scope, $scopeId);
        if (!isset(self::ORIGINS[$origin])) {
            throw new \InvalidArgumentException("Origem de agrupamento inválida: {$origin}");
        }
        if ($sourceId === $targetId) {
            throw new AuthorityMappingException(sprintf('Não dá para agrupar %s nele mesmo.', $this->label($entityType, $sourceId, true)));
        }
        $userId ??= $this->defaultUserId($origin);

        return $this->conn()->transactional(function () use ($entityType, $sourceId, $targetId, $scope, $scopeId, $origin, $userId, $note): array {
            $this->assertExists($entityType, $sourceId);
            $this->assertExists($entityType, $targetId);
            if ($entityType === self::ENTITY_KEYWORD && $scope === self::SCOPE_GLOBAL) {
                return $this->mergeKeywordGlobal($sourceId, $targetId, $origin, $userId, $note);
            }

            return $this->mergeByMapping($entityType, $sourceId, $targetId, $scope, $scopeId, $origin, $userId, $note);
        });
    }

    /**
     * Desfaz o agrupamento da identidade $sourceId no escopo: apaga a linha de authority_mapping ou, para palavra-chave
     * global gravada em keyword_concept_id, devolve o conceito à própria palavra e reabre os dependentes que ela tinha.
     *
     * @return array{mechanism:string,source_id:int,target_id:int,affected_links:int,reopened:list<int>}
     * @throws AuthorityMappingException quando não há agrupamento a desfazer
     */
    public function undo(string $entityType, string $scope, int $scopeId, int $sourceId, ?int $userId = null, string $origin = 'admin'): array
    {
        $this->assertEntity($entityType);
        $scopeId = $this->assertScope($scope, $scopeId);
        if (!isset(self::ORIGINS[$origin])) {
            throw new \InvalidArgumentException("Origem de agrupamento inválida: {$origin}");
        }
        $userId ??= $this->defaultUserId($origin);

        return $this->conn()->transactional(function () use ($entityType, $scope, $scopeId, $sourceId, $userId, $origin): array {
            $row = $this->mappingRow($entityType, $scope, $scopeId, $sourceId);
            if ($row !== null) {
                return $this->undoMapping($row, $userId, $origin);
            }
            if ($entityType === self::ENTITY_KEYWORD && $scope === self::SCOPE_GLOBAL) {
                return $this->undoKeywordConcept($sourceId, $userId, $origin);
            }
            throw new AuthorityMappingException(sprintf('%s não tem agrupamento a desfazer neste escopo.', $this->label($entityType, $sourceId, true)));
        });
    }

    /** Desfaz pelo id da linha de authority_mapping. @throws AuthorityMappingException se a linha não existe */
    public function undoMappingId(int $mappingId, ?int $userId = null, string $origin = 'admin'): array
    {
        $row = $this->conn()->fetchAssociative('SELECT entity_type, scope, scope_id, source_id FROM authority_mapping WHERE id = ?', [$mappingId]);
        if (!$row) {
            throw new AuthorityMappingException('O agrupamento já foi desfeito ou não existe.');
        }

        return $this->undo($row['entity_type'], $row['scope'], (int) $row['scope_id'], (int) $row['source_id'], $userId, $origin);
    }

    /**
     * Alvo final de $targetId no escopo: a mesma resolução do AuthorityResolver (projeto, usuário dono, global, bruto),
     * um salto por camada. Palavra-chave: formato do efetivo (positivo = keyword.id, negativo = -thesaurus_concept.id).
     */
    public function resolveFinalTarget(string $entityType, int $targetId, string $scope, int $scopeId): int
    {
        $this->assertEntity($entityType);
        $scopeId = $this->assertScope($scope, $scopeId);

        return $this->effectiveIn($entityType, $targetId, $this->layers($scope, $scopeId));
    }

    // ------------------------------------------------------------------ ler

    /**
     * Agrupamentos de um escopo, com nome de origem e de destino, quem e quando e a contagem de documentos da
     * origem (uma consulta por tabela, sem N+1). Palavra-chave global inclui os agrupamentos gravados em
     * keyword_concept_id (kind 'concept', sem mapping_id; quem e quando vêm do diário, quando existe).
     *
     * @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public function list(string $entityType, string $scope, int $scopeId, int $page = 1, int $perPage = self::PER_PAGE): array
    {
        $this->assertEntity($entityType);
        $scopeId = $this->assertScope($scope, $scopeId);
        $perPage = max(1, $perPage);
        $conn = $this->conn();
        $isAuthor = $entityType === self::ENTITY_AUTHOR;
        $withConcept = !$isAuthor && $scope === self::SCOPE_GLOBAL;

        $srcLabel = $isAuthor ? 's.preferred_name' : 'COALESCE(s.keyword_display, s.keyword_original)';
        $srcTable = $isAuthor ? 'author_identity' : 'keyword';
        $q = $conn->quote($entityType);
        $mapSql = "SELECT 'mapping' AS kind, m.id AS mapping_id, m.source_id, m.target_id, {$srcLabel} AS source_label,
                          m.origin, m.created_by AS user_id, m.created_at, m.note
                   FROM authority_mapping m
                   LEFT JOIN {$srcTable} s ON s.id = m.source_id
                   WHERE m.entity_type = {$q} AND m.scope = " . $conn->quote($scope) . " AND m.scope_id = {$scopeId}";
        $conceptSql = "SELECT 'concept' AS kind, NULL AS mapping_id, k.id AS source_id, k.keyword_concept_id AS target_id,
                              COALESCE(k.keyword_display, k.keyword_original) AS source_label,
                              NULL AS origin, NULL AS user_id, NULL AS created_at, NULL AS note
                       FROM keyword k
                       WHERE k.keyword_concept_id IS NOT NULL AND k.keyword_concept_id <> k.id AND k.thesaurus_concept_id IS NULL";

        $union = $withConcept ? "({$mapSql}) UNION ALL ({$conceptSql})" : $mapSql;
        $total = (int) $conn->fetchOne('SELECT COUNT(*) FROM (' . $union . ') u');
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        $order = $withConcept ? 'ORDER BY source_label ASC, source_id ASC' : 'ORDER BY created_at DESC, mapping_id DESC';
        $rows = $conn->fetchAllAssociative(
            'SELECT * FROM (' . $union . ') u ' . $order . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage)
        );

        $sourceIds = array_map(fn($r) => (int) $r['source_id'], $rows);
        $targetIds = array_map(fn($r) => (int) $r['target_id'], $rows);

        // Rótulos de destino, quem fez (do diário, para o conceito agrupador) e documentos da origem: uma consulta cada
        $targetLabels = $this->labels($entityType, $targetIds);
        $journal = [];
        if ($withConcept) {
            $conceptIds = array_map(fn($r) => (int) $r['source_id'], array_filter($rows, fn($r) => $r['kind'] === 'concept'));
            if ($conceptIds !== []) {
                $logs = $conn->executeQuery(
                    "SELECT source_id, user_id, created_at, origin FROM authority_merge_log
                     WHERE entity_type = 'keyword' AND action = 'merge' AND source_id IN (?) ORDER BY id",
                    [$conceptIds],
                    [ArrayParameterType::INTEGER]
                )->fetchAllAssociative();
                foreach ($logs as $l) {
                    $journal[(int) $l['source_id']] = $l;
                }
            }
        }
        $userIds = [];
        foreach ($rows as $r) {
            $uid = $r['user_id'] ?? ($journal[(int) $r['source_id']]['user_id'] ?? null);
            if ($uid !== null) {
                $userIds[(int) $uid] = true;
            }
        }
        $userNames = [];
        if ($userIds !== []) {
            foreach ($conn->executeQuery('SELECT id, COALESCE(NULLIF(name, \'\'), email) FROM `user` WHERE id IN (?)', [array_keys($userIds)], [ArrayParameterType::INTEGER])->fetchAllNumeric() as [$id, $n]) {
                $userNames[(int) $id] = (string) $n;
            }
        }
        $docs = $this->documentCounts($entityType, $sourceIds);

        $items = [];
        foreach ($rows as $r) {
            $sid = (int) $r['source_id'];
            $j = $journal[$sid] ?? null;
            $uid = $r['user_id'] ?? ($j['user_id'] ?? null);
            $items[] = [
                'kind'         => $r['kind'],
                'mapping_id'   => $r['mapping_id'] !== null ? (int) $r['mapping_id'] : null,
                'source_id'    => $sid,
                'source_label' => (string) ($r['source_label'] ?? ''),
                'target_id'    => (int) $r['target_id'],
                'target_label' => $targetLabels[(int) $r['target_id']] ?? ('#' . (int) $r['target_id']),
                'origin'       => $r['origin'] ?? (isset($j['origin']) ? (array_flip(self::ORIGINS)[$j['origin']] ?? $j['origin']) : null),
                'user_id'      => $uid !== null ? (int) $uid : null,
                'user_name'    => $uid !== null ? ($userNames[(int) $uid] ?? null) : null,
                'created_at'   => $r['created_at'] ?? ($j['created_at'] ?? null),
                'note'         => $r['note'],
                'documents'    => $docs[$sid] ?? 0,
            ];
        }

        return ['items' => $items, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $perPage];
    }

    /**
     * Para as telas de lista: em quem cada identidade está agrupada no escopo global (mapeamento ou, para
     * palavra-chave, keyword_concept_id). Só os ids agrupados aparecem. Uma consulta por tabela.
     *
     * @param list<int> $ids
     * @return array<int,array{target_id:int,target_label:string}>
     */
    public function globalGroupings(string $entityType, array $ids): array
    {
        $this->assertEntity($entityType);
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $conn = $this->conn();
        $targets = [];
        foreach ($conn->executeQuery(
            'SELECT source_id, target_id FROM authority_mapping WHERE entity_type = ? AND scope = \'global\' AND scope_id = 0 AND source_id IN (?)',
            [$entityType, $ids],
            [ParameterType::STRING, ArrayParameterType::INTEGER]
        )->fetchAllNumeric() as [$s, $t]) {
            $targets[(int) $s] = (int) $t;
        }
        if ($entityType === self::ENTITY_KEYWORD) {
            foreach ($conn->executeQuery(
                'SELECT id, keyword_concept_id FROM keyword WHERE id IN (?) AND keyword_concept_id IS NOT NULL AND keyword_concept_id <> id AND thesaurus_concept_id IS NULL',
                [$ids],
                [ArrayParameterType::INTEGER]
            )->fetchAllNumeric() as [$s, $t]) {
                $targets += [(int) $s => (int) $t];
            }
        }
        $labels = $this->labels($entityType, array_values($targets));
        $out = [];
        foreach ($targets as $s => $t) {
            $out[$s] = ['target_id' => $t, 'target_label' => $labels[$t] ?? ('#' . $t)];
        }

        return $out;
    }

    // ------------------------------------------------------------------ internos: gravar

    private function mergeByMapping(string $entity, int $b, int $a, string $scope, int $scopeId, string $origin, ?int $userId, ?string $note): array
    {
        $conn = $this->conn();
        $layers = $this->layers($scope, $scopeId);
        $finalA = $this->effectiveIn($entity, $a, $layers);
        if ($finalA === $b) {
            throw new AuthorityMappingException(sprintf(
                '%s já está agrupada em %s; agrupar o inverso formaria um laço. Desfaça o agrupamento existente antes.',
                $this->label($entity, $a, true),
                $this->label($entity, $b)
            ));
        }
        $existing = $this->mappingRow($entity, $scope, $scopeId, $b);
        if ($existing !== null) {
            if ((int) $existing['target_id'] === $finalA) {
                return $this->noop('mapping', $b, $finalA, (int) $existing['id']);
            }
            throw new AuthorityMappingException(sprintf(
                '%s já está agrupada em %s. Desfaça esse agrupamento antes de agrupá-la em outro destino.',
                $this->label($entity, $b, true),
                $this->label($entity, (int) $existing['target_id'])
            ));
        }
        if ($this->effectiveIn($entity, $b, $layers) === $finalA) {
            return $this->noop('mapping', $b, $finalA, null);
        }

        // Quem tinha B como alvo passa a ter o alvo final (sem encadeamento)
        $retargeted = array_map('intval', $conn->fetchFirstColumn(
            'SELECT source_id FROM authority_mapping WHERE entity_type = ? AND scope = ? AND scope_id = ? AND target_id = ?',
            [$entity, $scope, $scopeId, $b]
        ));

        $this->journal($entity, 'merge', $origin, $b, $finalA, $userId, $scope, $scopeId, [
            'mechanism' => 'mapping', 'scope' => $scope, 'scope_id' => $scopeId, 'requested_target' => $a, 'retargeted' => $retargeted, 'note' => $note,
        ]);
        if ($retargeted !== []) {
            $conn->executeStatement(
                'UPDATE authority_mapping SET target_id = ? WHERE entity_type = ? AND scope = ? AND scope_id = ? AND target_id = ?',
                [$finalA, $entity, $scope, $scopeId, $b]
            );
        }
        $mappingId = $this->insertRow($entity, $scope, $scopeId, $b, $finalA, $origin, $userId, $note);
        $affected = $this->refresh($entity, array_merge([$b], $retargeted), $scope, $scopeId);

        return [
            'noop' => false, 'mechanism' => 'mapping', 'source_id' => $b, 'target_id' => $finalA, 'mapping_id' => $mappingId,
            'dependents' => [], 'retargeted' => $retargeted, 'affected_links' => $affected,
        ];
    }

    private function mergeKeywordGlobal(int $b, int $a, string $origin, ?int $userId, ?string $note): array
    {
        $conn = $this->conn();
        $layers = [[self::SCOPE_GLOBAL, 0]];
        $kb = $conn->fetchAssociative('SELECT keyword_concept_id AS kc, thesaurus_concept_id AS tc FROM keyword WHERE id = ?', [$b]);
        $effA = $this->effectiveIn(self::ENTITY_KEYWORD, $a, $layers);
        $effB = $this->effectiveIn(self::ENTITY_KEYWORD, $b, $layers);
        $row = $this->mappingRow(self::ENTITY_KEYWORD, self::SCOPE_GLOBAL, 0, $b);

        if ($effA === $b) {
            throw new AuthorityMappingException(sprintf(
                '%s já está agrupada em %s; agrupar o inverso formaria um laço. Desfaça o agrupamento existente antes.',
                $this->label(self::ENTITY_KEYWORD, $a, true),
                $this->label(self::ENTITY_KEYWORD, $b)
            ));
        }
        if ($effB === $effA) {
            return $this->noop($row !== null ? 'mapping' : 'concept', $b, $effA, $row['id'] ?? null);
        }
        // B já agrupada (conceito agrupador ou mapeamento): só um conceito de tesauro puro pode ser sobreposto
        if ($effB !== $b && ($row !== null || $kb['tc'] === null)) {
            throw new AuthorityMappingException(sprintf(
                '%s já está agrupada em %s. Desfaça esse agrupamento antes de agrupá-la em outro destino.',
                $this->label(self::ENTITY_KEYWORD, $b, true),
                $this->label(self::ENTITY_KEYWORD, $effB)
            ));
        }

        $dependents = $conn->fetchAllAssociative('SELECT id, thesaurus_concept_id AS tc FROM keyword WHERE keyword_concept_id = ? AND id <> ?', [$b, $b]);
        $retargeted = array_map('intval', $conn->fetchFirstColumn(
            'SELECT source_id FROM authority_mapping WHERE entity_type = \'keyword\' AND scope = \'global\' AND scope_id = 0 AND target_id = ?',
            [$b]
        ));
        $depIds = array_map(fn($d) => (int) $d['id'], $dependents);
        $useConcept = $kb['tc'] === null && $effA > 0;

        $this->journal(self::ENTITY_KEYWORD, 'merge', $origin, $b, $effA, $userId, self::SCOPE_GLOBAL, 0, [
            'mechanism' => $useConcept ? 'concept' : 'mapping', 'requested_target' => $a, 'previous_concept' => $kb['kc'] !== null ? (int) $kb['kc'] : null,
            'dependents' => $depIds, 'retargeted' => $retargeted, 'note' => $note,
        ]);

        $mappingId = null;
        $mappedDeps = [];
        if ($useConcept) {
            $conn->executeStatement('UPDATE keyword SET keyword_concept_id = ? WHERE id = ?', [$effA, $b]);
            if ($depIds !== []) {
                $conn->executeStatement('UPDATE keyword SET keyword_concept_id = ? WHERE keyword_concept_id = ? AND id <> ?', [$effA, $b, $b]);
            }
        } else {
            $mappingId = $this->insertRow(self::ENTITY_KEYWORD, self::SCOPE_GLOBAL, 0, $b, $effA, $origin, $userId, $note);
            foreach ($dependents as $d) {
                if ($d['tc'] === null && $this->mappingRow(self::ENTITY_KEYWORD, self::SCOPE_GLOBAL, 0, (int) $d['id']) === null) {
                    $this->insertRow(self::ENTITY_KEYWORD, self::SCOPE_GLOBAL, 0, (int) $d['id'], $effA, $origin, $userId, 'via #' . $b);
                    $mappedDeps[] = (int) $d['id'];
                }
            }
        }
        if ($retargeted !== []) {
            $conn->executeStatement(
                'UPDATE authority_mapping SET target_id = ? WHERE entity_type = \'keyword\' AND scope = \'global\' AND scope_id = 0 AND target_id = ?',
                [$effA, $b]
            );
        }
        $affected = $this->refresh(self::ENTITY_KEYWORD, array_merge([$b], $depIds, $retargeted), self::SCOPE_GLOBAL, 0);

        return [
            'noop' => false, 'mechanism' => $useConcept ? 'concept' : 'mapping', 'source_id' => $b, 'target_id' => $effA, 'mapping_id' => $mappingId,
            'dependents' => $depIds, 'retargeted' => $retargeted, 'affected_links' => $affected,
        ];
    }

    /** @param array<string,mixed> $row linha de authority_mapping */
    private function undoMapping(array $row, ?int $userId, string $origin): array
    {
        $conn = $this->conn();
        $entity = $row['entity_type'];
        $scope = $row['scope'];
        $scopeId = (int) $row['scope_id'];
        $b = (int) $row['source_id'];
        $target = (int) $row['target_id'];

        $this->journal($entity, 'undo', $origin, $b, $target, $userId, $scope, $scopeId, ['mechanism' => 'mapping', 'mapping_id' => (int) $row['id']]);
        $sources = [$b];
        if ($entity === self::ENTITY_KEYWORD && $scope === self::SCOPE_GLOBAL) {
            // Dependentes gravados junto ("via #B") reabrem com a palavra
            $deps = array_map('intval', $conn->fetchFirstColumn(
                "SELECT source_id FROM authority_mapping WHERE entity_type = 'keyword' AND scope = 'global' AND scope_id = 0 AND note = ?",
                ['via #' . $b]
            ));
            if ($deps !== []) {
                $conn->executeStatement("DELETE FROM authority_mapping WHERE entity_type = 'keyword' AND scope = 'global' AND scope_id = 0 AND note = ?", ['via #' . $b]);
                $sources = array_merge($sources, $deps);
            }
        }
        $conn->executeStatement('DELETE FROM authority_mapping WHERE id = ?', [(int) $row['id']]);
        $affected = $this->refresh($entity, $sources, $scope, $scopeId);

        return ['mechanism' => 'mapping', 'source_id' => $b, 'target_id' => $target, 'affected_links' => $affected, 'reopened' => array_slice($sources, 1)];
    }

    private function undoKeywordConcept(int $b, ?int $userId, string $origin): array
    {
        $conn = $this->conn();
        $kb = $conn->fetchAssociative('SELECT keyword_concept_id AS kc, thesaurus_concept_id AS tc FROM keyword WHERE id = ?', [$b]);
        if (!$kb || $kb['tc'] !== null || $kb['kc'] === null || (int) $kb['kc'] === $b) {
            throw new AuthorityMappingException(sprintf('%s não tem agrupamento global a desfazer.', $this->label(self::ENTITY_KEYWORD, $b, true)));
        }
        $prev = (int) $kb['kc'];

        // Dependentes que a palavra tinha quando foi agrupada: vêm do diário (a última mesclagem dela)
        $deps = [];
        $payload = $conn->fetchOne("SELECT payload FROM authority_merge_log WHERE entity_type = 'keyword' AND action = 'merge' AND source_id = ? ORDER BY id DESC LIMIT 1", [$b]);
        if ($payload) {
            $data = json_decode((string) $payload, true) ?: [];
            if (($data['mechanism'] ?? null) === 'concept') {
                $deps = array_map('intval', $data['dependents'] ?? []);
            }
        }
        $this->journal(self::ENTITY_KEYWORD, 'undo', $origin, $b, $prev, $userId, self::SCOPE_GLOBAL, 0, ['mechanism' => 'concept', 'dependents' => $deps]);

        $conn->executeStatement('UPDATE keyword SET keyword_concept_id = id WHERE id = ?', [$b]);
        $reopened = [];
        foreach (array_chunk($deps, 1000) as $chunk) {
            // Só os que ainda apontam para o destino anterior (os que já foram agrupados em outro lugar ficam)
            $ids = array_map('intval', $conn->fetchFirstColumn(
                'SELECT id FROM keyword WHERE id IN (?) AND keyword_concept_id = ?',
                [$chunk, $prev],
                [ArrayParameterType::INTEGER, ParameterType::INTEGER]
            ));
            if ($ids !== []) {
                $conn->executeStatement('UPDATE keyword SET keyword_concept_id = ? WHERE id IN (' . implode(',', $ids) . ')', [$b]);
                $reopened = array_merge($reopened, $ids);
            }
        }
        $affected = $this->refresh(self::ENTITY_KEYWORD, array_merge([$b], $reopened), self::SCOPE_GLOBAL, 0);

        return ['mechanism' => 'concept', 'source_id' => $b, 'target_id' => $prev, 'affected_links' => $affected, 'reopened' => $reopened];
    }

    private function noop(string $mechanism, int $b, int $target, ?int $mappingId): array
    {
        return [
            'noop' => true, 'mechanism' => $mechanism, 'source_id' => $b, 'target_id' => $target, 'mapping_id' => $mappingId,
            'dependents' => [], 'retargeted' => [], 'affected_links' => 0,
        ];
    }

    private function insertRow(string $entity, string $scope, int $scopeId, int $source, int $target, string $origin, ?int $userId, ?string $note): int
    {
        $conn = $this->conn();
        $conn->insert('authority_mapping', [
            'entity_type' => $entity,
            'scope'       => $scope,
            'scope_id'    => $scopeId,
            'source_id'   => $source,
            'target_id'   => $target,
            'origin'      => $origin,
            'created_by'  => $userId,
            'created_at'  => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'note'        => $note !== null ? mb_substr($note, 0, 255) : null,
        ]);

        return (int) $conn->lastInsertId();
    }

    /** Diário na mesma transação: cabeçalho + vínculos da origem (estado de antes da gravação). */
    private function journal(string $entity, string $action, string $origin, int $source, int $target, ?int $userId, string $scope, int $scopeId, array $payload): void
    {
        $projectId = $scope === self::SCOPE_PROJECT ? $scopeId : null;
        $logOrigin = self::ORIGINS[$origin];
        if ($entity === self::ENTITY_AUTHOR) {
            $this->mergeLogger->logAuthorMerge($logOrigin, $action, $source, $target, $userId, $projectId, $payload);
        } else {
            $this->mergeLogger->logKeywordMerge($logOrigin, $action, $source, $target, $userId, $projectId, $payload);
        }
    }

    /**
     * Recalcula o efetivo dos vínculos cuja origem bruta está em $rawIds (no escopo: projeto ou usuário dono, se for o caso).
     * Só grava onde o valor muda; devolve quantos vínculos mudaram.
     *
     * @param list<int> $rawIds
     */
    private function refresh(string $entity, array $rawIds, string $scope, int $scopeId): int
    {
        $a = $entity === self::ENTITY_AUTHOR ? 'da' : 'dk';
        $col = $entity === self::ENTITY_AUTHOR ? 'author_identity_id' : 'keyword_id';
        $extra = match ($scope) {
            self::SCOPE_PROJECT => ' AND d.project_id = ' . $scopeId,
            self::SCOPE_USER => ' AND p.user_id = ' . $scopeId,
            default => '',
        };
        $n = 0;
        foreach (array_chunk(array_values(array_unique(array_map('intval', $rawIds))), 1000) as $chunk) {
            $n += $this->resolver->refreshLinks($entity, "{$a}.{$col} IN (" . implode(',', $chunk) . "){$extra}");
        }

        return $n;
    }

    // ------------------------------------------------------------------ internos: resolver e ler

    /** @return list<array{0:string,1:int}> camadas em ordem de prioridade para o escopo */
    private function layers(string $scope, int $scopeId): array
    {
        if ($scope === self::SCOPE_GLOBAL) {
            return [[self::SCOPE_GLOBAL, 0]];
        }
        if ($scope === self::SCOPE_USER) {
            return [[self::SCOPE_USER, $scopeId], [self::SCOPE_GLOBAL, 0]];
        }
        $layers = [[self::SCOPE_PROJECT, $scopeId]];
        $owner = $this->conn()->fetchOne('SELECT user_id FROM bibliometric_project WHERE id = ?', [$scopeId]);
        if ($owner !== false && $owner !== null) {
            $layers[] = [self::SCOPE_USER, (int) $owner];
        }
        $layers[] = [self::SCOPE_GLOBAL, 0];

        return $layers;
    }

    /** Efetivo de $id nas camadas: a primeira linha de mapeamento que existir, senão o bruto. */
    private function effectiveIn(string $entity, int $id, array $layers): int
    {
        foreach ($layers as [$scope, $scopeId]) {
            $t = $this->conn()->fetchOne(
                'SELECT target_id FROM authority_mapping WHERE entity_type = ? AND scope = ? AND scope_id = ? AND source_id = ?',
                [$entity, $scope, $scopeId, $id]
            );
            if ($t !== false) {
                return (int) $t;
            }
        }
        if ($entity === self::ENTITY_AUTHOR) {
            return $id;
        }
        $raw = $this->conn()->fetchOne('SELECT ' . AuthorityResolver::keywordRawExpression('k') . ' FROM keyword k WHERE k.id = ?', [$id]);

        return $raw === false ? $id : (int) $raw;
    }

    private function mappingRow(string $entity, string $scope, int $scopeId, int $source): ?array
    {
        $row = $this->conn()->fetchAssociative(
            'SELECT * FROM authority_mapping WHERE entity_type = ? AND scope = ? AND scope_id = ? AND source_id = ?',
            [$entity, $scope, $scopeId, $source]
        );

        return $row ?: null;
    }

    private function assertExists(string $entity, int $id): void
    {
        $table = $entity === self::ENTITY_AUTHOR ? 'author_identity' : 'keyword';
        if ($this->conn()->fetchOne("SELECT id FROM {$table} WHERE id = ?", [$id]) === false) {
            throw new AuthorityMappingException(sprintf('%s #%d não foi encontrada.', $entity === self::ENTITY_AUTHOR ? 'O autor' : 'A palavra-chave', $id));
        }
    }

    /** Nome da identidade para mensagens; $capital: início de frase ("O autor «X»" / "A palavra-chave «X»"). */
    private function label(string $entity, int $id, bool $subject = false): string
    {
        $name = $this->labels($entity, [$id])[$id] ?? ('#' . $id);
        $noun = $entity === self::ENTITY_AUTHOR ? 'autor' : 'palavra-chave';
        $article = $entity === self::ENTITY_AUTHOR ? 'o' : 'a';
        $text = sprintf('%s «%s»', $noun, $name);

        return $subject ? ucfirst($article) . ' ' . $text : $article . ' ' . $text;
    }

    /**
     * @param list<int> $ids ids de identidade (palavra-chave: negativo = -thesaurus_concept.id)
     * @return array<int,string>
     */
    private function labels(string $entityType, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $out = [];
        if ($ids === []) {
            return $out;
        }
        $conn = $this->conn();
        if ($entityType === self::ENTITY_AUTHOR) {
            foreach ($conn->executeQuery('SELECT id, preferred_name FROM author_identity WHERE id IN (?)', [$ids], [ArrayParameterType::INTEGER])->fetchAllNumeric() as [$i, $n]) {
                $out[(int) $i] = (string) $n;
            }

            return $out;
        }
        $pos = array_values(array_filter($ids, fn($i) => $i > 0));
        $neg = array_values(array_map(fn($i) => -$i, array_filter($ids, fn($i) => $i < 0)));
        if ($pos !== []) {
            foreach ($conn->executeQuery('SELECT id, COALESCE(keyword_display, keyword_original) FROM keyword WHERE id IN (?)', [$pos], [ArrayParameterType::INTEGER])->fetchAllNumeric() as [$i, $n]) {
                $out[(int) $i] = (string) $n;
            }
        }
        if ($neg !== []) {
            foreach ($conn->executeQuery('SELECT id, preferred_label FROM thesaurus_concept WHERE id IN (?)', [$neg], [ArrayParameterType::INTEGER])->fetchAllNumeric() as [$i, $n]) {
                $out[-(int) $i] = 'Conceito do tesauro: ' . $n;
            }
        }

        return $out;
    }

    /** @param list<int> $ids @return array<int,int> documentos distintos de cada origem (uma consulta) */
    private function documentCounts(string $entityType, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        [$table, $col] = $entityType === self::ENTITY_AUTHOR ? ['document_author', 'author_identity_id'] : ['document_keyword', 'keyword_id'];
        $out = [];
        foreach ($this->conn()->executeQuery(
            "SELECT {$col}, COUNT(DISTINCT document_id) FROM {$table} WHERE {$col} IN (?) GROUP BY {$col}",
            [$ids],
            [ArrayParameterType::INTEGER]
        )->fetchAllNumeric() as [$i, $n]) {
            $out[(int) $i] = (int) $n;
        }

        return $out;
    }

    private function defaultUserId(string $origin): ?int
    {
        if (!in_array($origin, ['admin', 'project'], true)) {
            return null;
        }
        $user = $this->security?->getUser();

        return ($user !== null && method_exists($user, 'getId') && $user->getId() !== null) ? (int) $user->getId() : null;
    }

    private function assertEntity(string $entityType): void
    {
        if (!in_array($entityType, AuthorityResolver::ENTITIES, true)) {
            throw new \InvalidArgumentException("Tipo de autoridade inválido: {$entityType}");
        }
    }

    /** Valida o escopo e devolve o scopeId normalizado (0 no global). */
    private function assertScope(string $scope, int $scopeId): int
    {
        if ($scope === self::SCOPE_GLOBAL) {
            return 0;
        }
        if (!in_array($scope, [self::SCOPE_USER, self::SCOPE_PROJECT], true) || $scopeId <= 0) {
            throw new \InvalidArgumentException("Escopo inválido: {$scope}/{$scopeId}");
        }

        return $scopeId;
    }

    private function conn(): Connection
    {
        return $this->em->getConnection();
    }
}
