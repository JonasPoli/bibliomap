<?php

namespace App\Service\Normalize;

use App\Service\Authority\AuthorityMappingException;
use App\Service\Authority\AuthorityMappingService;
use App\Service\Authority\AuthorityMergeLogger;
use App\Service\Authority\AuthorityResolver;
use Doctrine\ORM\EntityManagerInterface;

class NormalizationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuthorityMergeLogger $mergeLogger,
        private ?AuthorityResolver $authority = null,
        private ?AuthorityMappingService $mapping = null,
    ) {}

    /** @var list<string> */
    private array $lastRefusals = [];

    /** Resolvedor do id efetivo (sem o rastreador quando o serviço é criado à mão, em testes e scripts). */
    private function authority(): AuthorityResolver
    {
        return $this->authority ??= new AuthorityResolver($this->em->getConnection());
    }

    // ── 1. Author Normalization Heuristics ──────────────────────────────────────

    /**
     * Find similar author suggestions.
     * Uses a fast grouping heuristic (4 primeiras letras do sobrenome + inicial do prenome,
     * ver authorBlockKey) to avoid O(N^2) comparison.
     */
    public function findSimilarAuthors(int $projectId, float $minSimilarity = 0.85): array
    {
        $conn = $this->em->getConnection();

        // Autores do projeto pela identidade EFETIVA do vínculo: quem já foi agrupado (no projeto ou no global)
        // aparece uma vez, com o nome e a contagem do alvo, e o par já agrupado não volta como sugestão.
        $authors = $conn->fetchAllAssociative('
            SELECT 
                a.id, 
                a.preferred_name AS name, 
                a.normalized_name, 
                COUNT(DISTINCT da.document_id) AS doc_count
            FROM author_identity a
            JOIN document_author da ON a.id = da.effective_author_id
            JOIN document d ON da.document_id = d.id
            WHERE d.project_id = ?
            GROUP BY a.id, a.preferred_name, a.normalized_name
            HAVING doc_count > 0
            ORDER BY a.preferred_name ASC
        ', [$projectId]);

        $groups = [];
        foreach ($authors as $auth) {
            $groups[self::authorBlockKey($auth['name'])][] = $auth;
        }

        $suggestions = [];
        foreach ($groups as $key => $group) {
            $count = count($group);
            if ($count < 2) continue;

            // Cada nome é preparado uma vez (minúsculas, forma limpa, tamanho), não a cada par
            $prep = array_map(fn ($a) => self::prepareSimilarity($a['name']), $group);

            // Compare inside the small group
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $a1 = $group[$i];
                    $a2 = $group[$j];

                    // Check similarity (null: o tamanho já garante similaridade abaixo do limiar)
                    $sim = self::similarityPrepared($prep[$i], $prep[$j], $minSimilarity);
                    if ($sim !== null && $sim >= $minSimilarity) {
                        // Order so the one with more documents is preferred to keep
                        $keep = $a1['doc_count'] >= $a2['doc_count'] ? $a1 : $a2;
                        $discard = $keep['id'] === $a1['id'] ? $a2 : $a1;

                        $suggestions[] = [
                            'keep'       => $keep,
                            'discard'    => $discard,
                            'similarity' => round($sim * 100, 1),
                        ];
                    }
                }
            }
        }

        // Sort by similarity desc
        usort($suggestions, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

        return array_slice($suggestions, 0, 5000);
    }

    /**
     * Chave de bloco para comparar autores: 4 primeiras letras do sobrenome + inicial do prenome.
     * Os nomes gravados vêm como "Sobrenome, Prenome/Iniciais" (WoS, Scopus); também aceita
     * "Sobrenome JA" (iniciais no fim, sem vírgula) e "Prenome Sobrenome" (Lens).
     * Assim "Silva, J" e "Silva, JA" caem no mesmo bloco e são comparados.
     */
    public static function authorBlockKey(string $name): string
    {
        $name = trim($name);
        if (str_contains($name, ',')) {
            [$surname, $given] = array_map('trim', explode(',', $name, 2));
        } else {
            $parts = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [''];
            $last  = end($parts);
            if (count($parts) > 1 && preg_match('/^(\p{Lu}[.\-]?){1,4}$/u', $last)) {
                $given   = array_pop($parts);
                $surname = implode(' ', $parts);
            } elseif (count($parts) > 1) {
                $surname = array_pop($parts);
                $given   = $parts[0];
            } else {
                $surname = $parts[0];
                $given   = '';
            }
        }

        // Minúsculas, sem acentos e só letras/dígitos (transliterador criado uma vez: é caro)
        static $tr = null;
        $tr ??= \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC; Lower');
        $surname = preg_replace('/[^\p{L}\p{N}]+/u', '', (string) $tr->transliterate($surname));
        $given   = preg_replace('/[^\p{L}\p{N}]+/u', '', (string) $tr->transliterate($given));

        return mb_substr($surname, 0, 4) . '|' . mb_substr($given, 0, 1);
    }

    // ── Agrupar (nada é apagado nem repontuado) ───────────────────────────────

    /** Agrupador de autoridades; criado sob demanda em testes e scripts que montam o serviço à mão. */
    private function mapping(): AuthorityMappingService
    {
        return $this->mapping ??= new AuthorityMappingService($this->em, $this->authority(), $this->mergeLogger);
    }

    /**
     * Escopo e origem do agrupamento: o administrador grava no escopo global; os demais usuários, só no do projeto.
     * Sem projeto (comando, script), vale o global.
     *
     * @return array{0:string,1:int,2:string} [escopo, id do escopo, origem]
     */
    private function scopeFor(?int $projectId, bool $asAdmin): array
    {
        if ($asAdmin || $projectId === null) {
            return [AuthorityMappingService::SCOPE_GLOBAL, 0, 'admin'];
        }

        return [AuthorityMappingService::SCOPE_PROJECT, $projectId, 'project'];
    }

    /** Recusas do agrupamento (laço, já agrupada, auto-mapeamento) da última mesclagem em lote ou por CSV, em pt-BR. */
    public function lastRefusals(): array
    {
        return $this->lastRefusals;
    }

    /** Alvo final da identidade no escopo, quando é uma identidade própria (positivo); senão, o id como veio. */
    private function finalOrRaw(string $entity, int $id, string $scope, int $scopeId): int
    {
        $final = $this->mapping()->resolveFinalTarget($entity, $id, $scope, $scopeId);

        return $final > 0 ? $final : $id;
    }

    private function note(array $logExtra): ?string
    {
        return isset($logExtra['via']) ? 'via ' . $logExtra['via'] : null;
    }

    /**
     * Agrupa o autor $discardId no autor $keepId (nada é apagado, nem o id bruto dos vínculos muda).
     * Administrador: escopo global; usuário comum: escopo do projeto $projectId.
     *
     * @param array $logExtra dados extras (por exemplo ['via' => 'csv']), guardados na nota do agrupamento
     * @return bool false quando o par já estava agrupado (nada a fazer)
     * @throws AuthorityMappingException quando o agrupamento é recusado (mensagem em pt-BR)
     */
    public function mergeAuthors(int $keepId, int $discardId, ?int $projectId = null, array $logExtra = [], bool $asAdmin = false): bool
    {
        [$scope, $scopeId, $origin] = $this->scopeFor($projectId, $asAdmin);
        $r = $this->mapping()->merge(AuthorityMappingService::ENTITY_AUTHOR, $discardId, $keepId, $scope, $scopeId, $origin, null, $this->note($logExtra));

        return !$r['noop'];
    }

    /**
     * Agrupa vários autores (um par por vez, cada um numa transação do serviço). Pares repetidos na origem são
     * ignorados e os recusados (laço, já agrupado em outro destino) ficam em lastRefusals(); o encadeamento
     * (C em B, B em A) é resolvido pelo serviço, que aponta C direto para o alvo final.
     *
     * @return int quantidade de pares agrupados
     */
    public function mergeAuthorsBatch(array $pairs, ?int $projectId = null, bool $asAdmin = false): int
    {
        return $this->mergeBatch(AuthorityMappingService::ENTITY_AUTHOR, $pairs, $projectId, $asAdmin);
    }

    private function mergeBatch(string $entity, array $pairs, ?int $projectId, bool $asAdmin): int
    {
        [$scope, $scopeId, $origin] = $this->scopeFor($projectId, $asAdmin);
        $this->lastRefusals = [];
        $merged = 0;
        $seen = [];

        foreach ($pairs as $pair) {
            $keepId    = (int) ($pair['keepId'] ?? 0);
            $discardId = (int) ($pair['discardId'] ?? 0);
            if ($keepId <= 0 || $discardId <= 0 || $keepId === $discardId || isset($seen[$discardId])) {
                continue;
            }
            $seen[$discardId] = true;

            try {
                $r = $this->mapping()->merge($entity, $discardId, $keepId, $scope, $scopeId, $origin, null, 'via batch');
                if (!$r['noop']) {
                    $merged++;
                }
            } catch (AuthorityMappingException $e) {
                $this->lastRefusals[] = $e->getMessage();
            }
        }

        return $merged;
    }


    // ── Agrupamentos do projeto (lista, desfazer, globais em uso) ───────────────

    /** Agrupamentos do projeto (escopo 'project') de autores ou palavras-chave, para a aba "Agrupamentos do projeto". */
    public function projectGroupings(string $entity, int $projectId, int $page = 1, int $perPage = 100): array
    {
        return $this->mapping()->list($entity, AuthorityMappingService::SCOPE_PROJECT, $projectId, $page, $perPage);
    }

    /**
     * Desfaz um agrupamento do PROJETO. A linha precisa ser do escopo 'project' deste projeto: agrupamento de outro
     * projeto, de usuário ou global é recusado (o global se desfaz nas telas de administração).
     *
     * @throws AuthorityMappingException quando a linha não existe, é de outro escopo ou já foi desfeita
     */
    public function undoProjectGrouping(int $projectId, int $mappingId): array
    {
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT scope, scope_id FROM authority_mapping WHERE id = ?',
            [$mappingId]
        );
        if (!$row) {
            throw new AuthorityMappingException('O agrupamento já foi desfeito ou não existe.');
        }
        if ($row['scope'] !== AuthorityMappingService::SCOPE_PROJECT || (int) $row['scope_id'] !== $projectId) {
            throw new AuthorityMappingException('Este agrupamento não pertence a este projeto: só dá para desfazer os do próprio projeto.');
        }

        return $this->mapping()->undoMappingId($mappingId, null, 'project');
    }

    /**
     * Agrupamentos GLOBAIS que afetam documentos do projeto (somente leitura), com a contagem de documentos do
     * projeto em que a identidade agrupada aparece. Palavra-chave inclui os gravados no conceito agrupador
     * (keyword_concept_id). Os mais usados primeiro, no máximo $limit linhas.
     *
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public function globalGroupingsInUse(string $entity, int $projectId, int $limit = 200): array
    {
        $conn = $this->em->getConnection();
        $limit = max(1, $limit);

        if ($entity === AuthorityMappingService::ENTITY_AUTHOR) {
            $sql = "SELECT m.source_id, m.target_id, s.preferred_name AS source_label, t.preferred_name AS target_label,
                           COUNT(DISTINCT da.document_id) AS documents
                    FROM authority_mapping m
                    JOIN document_author da ON da.author_identity_id = m.source_id
                    JOIN document d ON d.id = da.document_id AND d.project_id = ?
                    LEFT JOIN author_identity s ON s.id = m.source_id
                    LEFT JOIN author_identity t ON t.id = m.target_id
                    WHERE m.entity_type = 'author' AND m.scope = 'global' AND m.scope_id = 0
                    GROUP BY m.id, m.source_id, m.target_id, s.preferred_name, t.preferred_name";
            $params = [$projectId];
        } else {
            $label = 'COALESCE(%s.keyword_display, %s.keyword_original)';
            $sl = sprintf($label, 's', 's');
            $tl = sprintf($label, 't', 't');
            $sql = "SELECT u.source_id, u.target_id, u.source_label, u.target_label, u.documents FROM (
                        SELECT m.source_id, m.target_id, {$sl} AS source_label, {$tl} AS target_label,
                               COUNT(DISTINCT dk.document_id) AS documents
                        FROM authority_mapping m
                        JOIN document_keyword dk ON dk.keyword_id = m.source_id
                        JOIN document d ON d.id = dk.document_id AND d.project_id = ?
                        LEFT JOIN keyword s ON s.id = m.source_id
                        LEFT JOIN keyword t ON t.id = m.target_id
                        WHERE m.entity_type = 'keyword' AND m.scope = 'global' AND m.scope_id = 0
                        GROUP BY m.id, m.source_id, m.target_id, s.keyword_display, s.keyword_original, t.keyword_display, t.keyword_original
                        UNION ALL
                        SELECT s.id AS source_id, s.keyword_concept_id AS target_id, {$sl} AS source_label, {$tl} AS target_label,
                               COUNT(DISTINCT dk.document_id) AS documents
                        FROM keyword s
                        JOIN document_keyword dk ON dk.keyword_id = s.id
                        JOIN document d ON d.id = dk.document_id AND d.project_id = ?
                        LEFT JOIN keyword t ON t.id = s.keyword_concept_id
                        WHERE s.keyword_concept_id IS NOT NULL AND s.keyword_concept_id <> s.id AND s.thesaurus_concept_id IS NULL
                        GROUP BY s.id, s.keyword_concept_id, s.keyword_display, s.keyword_original, t.keyword_display, t.keyword_original
                    ) u";
            $params = [$projectId, $projectId];
        }

        $rows = $conn->fetchAllAssociative("SELECT * FROM ({$sql}) x ORDER BY documents DESC, source_label ASC, source_id ASC", $params);

        return [
            'total' => count($rows),
            'items' => array_map(fn ($r) => [
                'source_id'    => (int) $r['source_id'],
                'source_label' => (string) ($r['source_label'] ?? ''),
                'target_id'    => (int) $r['target_id'],
                'target_label' => (string) ($r['target_label'] ?? ('#' . (int) $r['target_id'])),
                'documents'    => (int) $r['documents'],
            ], array_slice($rows, 0, $limit)),
        ];
    }

    // ── 2. Keyword Normalization Heuristics ─────────────────────────────────────

    /**
     * Find similar keywords.
     */
    public function findSimilarKeywords(int $projectId, string $type = 'author', float $minSimilarity = 0.85): array
    {
        $conn = $this->em->getConnection();

        $mappedType = $type === 'author' ? 'author_keyword' : ($type === 'indexed' ? 'indexed_keyword' : $type);

        // Palavras-chave do projeto pela identidade EFETIVA do vínculo (só do tipo escolhido e fora do tesauro:
        // o efetivo negativo é conceito de tesauro e não casa com nenhuma keyword)
        $keywords = $conn->fetchAllAssociative('
            SELECT
                k.id,
                k.keyword_display AS term,
                COUNT(DISTINCT dk.document_id) AS doc_count
            FROM keyword k
            JOIN document_keyword dk ON k.id = dk.effective_keyword_id
            JOIN document d ON dk.document_id = d.id
            WHERE d.project_id = ? AND k.keyword_type = ? AND k.thesaurus_concept_id IS NULL
            GROUP BY k.id, k.keyword_display
            HAVING doc_count > 0
            ORDER BY k.keyword_display ASC
        ', [$projectId, $mappedType]);

        // Group keywords by first 4 letters for efficient O(n) grouping
        $groups = [];
        foreach ($keywords as $kw) {
            $term = strtolower(trim($kw['term']));
            $key  = substr($term, 0, 4);
            $groups[$key][] = $kw;
        }

        $suggestions = [];
        foreach ($groups as $key => $group) {
            $count = count($group);
            if ($count < 2) continue;

            // Cada termo é preparado uma vez (minúsculas, forma limpa, tamanho), não a cada par
            $prep = array_map(fn ($k) => self::prepareSimilarity($k['term']), $group);

            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $k1 = $group[$i];
                    $k2 = $group[$j];

                    $t1 = $prep[$i]['lower'];
                    $t2 = $prep[$j]['lower'];
                    $isPluralMatch = ($t1 . 's' === $t2) || ($t2 . 's' === $t1)
                                  || ($t1 . 'es' === $t2) || ($t2 . 'es' === $t1);

                    // Par singular/plural mostra a similaridade real: nunca é descartado pelo tamanho
                    $sim = self::similarityPrepared($prep[$i], $prep[$j], $isPluralMatch ? 0.0 : $minSimilarity);

                    if ($sim !== null && ($sim >= $minSimilarity || $isPluralMatch)) {
                        $keep    = $k1['doc_count'] >= $k2['doc_count'] ? $k1 : $k2;
                        $discard = $keep['id'] === $k1['id'] ? $k2 : $k1;

                        // 100% fica reservado a termos idênticos após normalização: o par
                        // singular/plural mostra a similaridade real e não entra em "Mesclar 100%".
                        $suggestions[] = [
                            'keep'       => $keep,
                            'discard'    => $discard,
                            'similarity' => round($sim * 100, 1),
                            'match_type' => $isPluralMatch ? 'plural' : 'same_type',
                        ];
                    }
                }
            }
        }

        usort($suggestions, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

        return array_slice($suggestions, 0, 5000);
    }

    /**
     * Find keywords that exist as BOTH author-type AND indexed-type with the same (or very similar) term.
     * @param string $currentType Which type is currently selected — returned as the 'keep' candidate.
     */
    public function findCrossTypeKeywords(int $projectId, string $currentType = 'author'): array
    {
        $conn      = $this->em->getConnection();
        $otherType = $currentType === 'author' ? 'indexed' : 'author';

        $mappedCurrentType = $currentType === 'author' ? 'author_keyword' : ($currentType === 'indexed' ? 'indexed_keyword' : $currentType);
        $mappedOtherType   = $otherType === 'author' ? 'author_keyword' : ($otherType === 'indexed' ? 'indexed_keyword' : $otherType);

        $current = $conn->fetchAllAssociative(
            'SELECT k.id, k.keyword_display AS term,
                    CASE WHEN k.keyword_type = \'author_keyword\' THEN \'author\' WHEN k.keyword_type = \'indexed_keyword\' THEN \'indexed\' ELSE k.keyword_type END AS type,
                    COUNT(DISTINCT dk.document_id) AS doc_count
             FROM keyword k
             JOIN document_keyword dk ON k.id = dk.effective_keyword_id
             JOIN document d ON dk.document_id = d.id
             WHERE d.project_id = ? AND k.keyword_type = ? AND k.thesaurus_concept_id IS NULL
             GROUP BY k.id, k.keyword_display, k.keyword_type
             HAVING doc_count > 0
             ORDER BY doc_count DESC',
            [$projectId, $mappedCurrentType]
        );

        $other = $conn->fetchAllAssociative(
            'SELECT k.id, k.keyword_display AS term,
                    CASE WHEN k.keyword_type = \'author_keyword\' THEN \'author\' WHEN k.keyword_type = \'indexed_keyword\' THEN \'indexed\' ELSE k.keyword_type END AS type,
                    COUNT(DISTINCT dk.document_id) AS doc_count
             FROM keyword k
             JOIN document_keyword dk ON k.id = dk.effective_keyword_id
             JOIN document d ON dk.document_id = d.id
             WHERE d.project_id = ? AND k.keyword_type = ? AND k.thesaurus_concept_id IS NULL
             GROUP BY k.id, k.keyword_display, k.keyword_type
             HAVING doc_count > 0',
            [$projectId, $mappedOtherType]
        );

        // Index other-type by normalized term
        $otherByTerm = [];
        foreach ($other as $kw) {
            $normalized = strtolower(trim($kw['term']));
            $otherByTerm[$normalized][] = $kw;
        }

        $suggestions = [];
        foreach ($current as $kw) {
            $normalized = strtolower(trim($kw['term']));
            if (isset($otherByTerm[$normalized])) {
                foreach ($otherByTerm[$normalized] as $match) {
                    $keep    = $kw['doc_count'] >= $match['doc_count'] ? $kw : $match;
                    $discard = $keep['id'] === $kw['id'] ? $match : $kw;

                    $suggestions[] = [
                        'keep'       => $keep,
                        'discard'    => $discard,
                        'similarity' => 100.0,
                        'match_type' => 'cross_type',
                        'cross_note' => 'Mesmo termo em tipos diferentes (Autor vs Indexada)',
                    ];
                }
            }
        }

        return $suggestions;
    }

    /**
     * Return ALL keywords for a project (for the full list view).
     */
    public function getAllKeywords(int $projectId, string $type = '', string $search = '', int $limit = 500): array
    {
        $conn = $this->em->getConnection();

        $where  = 'd.project_id = ?';
        $params = [$projectId];

        if ($type !== '') {
            $mappedType = $type === 'author' ? 'author_keyword' : ($type === 'indexed' ? 'indexed_keyword' : $type);
            $where   .= ' AND k.keyword_type = ?';
            $params[] = $mappedType;
        }
        if ($search !== '') {
            $where   .= ' AND k.keyword_display LIKE ?';
            $params[] = '%' . $search . '%';
        }

        return $conn->fetchAllAssociative(
            "SELECT k.id, k.keyword_display AS term,
                    CASE WHEN k.keyword_type = 'author_keyword' THEN 'author' WHEN k.keyword_type = 'indexed_keyword' THEN 'indexed' ELSE k.keyword_type END AS type,
                    COUNT(DISTINCT dk.document_id) AS doc_count
             FROM keyword k
             JOIN document_keyword dk ON k.id = IF(dk.effective_keyword_id > 0, dk.effective_keyword_id, dk.keyword_id)
             JOIN document d ON dk.document_id = d.id
             WHERE {$where}
             GROUP BY k.id, k.keyword_display, k.keyword_type
             HAVING doc_count > 0
             ORDER BY doc_count DESC
             LIMIT {$limit}",
            $params
        );
    }

    /**
     * Agrupa a palavra-chave $discardId na palavra-chave $keepId (nada é apagado, nem o id bruto dos vínculos muda).
     * Administrador: escopo global; usuário comum: escopo do projeto $projectId.
     *
     * @return bool false quando o par já estava agrupado (nada a fazer)
     * @throws AuthorityMappingException quando o agrupamento é recusado (mensagem em pt-BR)
     */
    public function mergeKeywords(int $keepId, int $discardId, ?int $projectId = null, array $logExtra = [], bool $asAdmin = false): bool
    {
        [$scope, $scopeId, $origin] = $this->scopeFor($projectId, $asAdmin);
        $r = $this->mapping()->merge(AuthorityMappingService::ENTITY_KEYWORD, $discardId, $keepId, $scope, $scopeId, $origin, null, $this->note($logExtra));

        return !$r['noop'];
    }

    /** Agrupa várias palavras-chave (um par por vez); ver mergeAuthorsBatch. */
    public function mergeKeywordsBatch(array $pairs, ?int $projectId = null, bool $asAdmin = false): int
    {
        return $this->mergeBatch(AuthorityMappingService::ENTITY_KEYWORD, $pairs, $projectId, $asAdmin);
    }

    // ── 3. Document Deduplication Heuristics ───────────────────────────────────

    /**
     * Find potential duplicate documents.
     */
    public function findPotentialDuplicates(int $projectId, float $minSimilarity = 0.85): array
    {
        $conn = $this->em->getConnection();

        $docs = $conn->fetchAllAssociative('
            SELECT 
                id, 
                title, 
                year, 
                source_title,
                doi, 
                cited_by
            FROM document
            WHERE project_id = ? AND year IS NOT NULL AND title IS NOT NULL
            ORDER BY year DESC, title ASC
        ', [$projectId]);

        // Group by year
        $groups = [];
        foreach ($docs as $doc) {
            $groups[$doc['year']][] = $doc;
        }

        $suggestions = [];
        foreach ($groups as $year => $group) {
            $count = count($group);
            if ($count < 2) continue;

            // Tamanho do título e forma preparada calculados uma vez por documento, não por par
            $lens = array_map(fn ($d) => strlen($d['title']), $group);
            $prep = [];

            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $d1 = $group[$i];
                    $d2 = $group[$j];

                    $len1 = $lens[$i];
                    $len2 = $lens[$j];
                    if (abs($len1 - $len2) > max($len1, $len2) * (1 - $minSimilarity)) {
                        continue;
                    }

                    $prep[$i] ??= self::prepareSimilarity($d1['title']);
                    $prep[$j] ??= self::prepareSimilarity($d2['title']);
                    $sim = self::similarityPrepared($prep[$i], $prep[$j], $minSimilarity);
                    if ($sim !== null && $sim >= $minSimilarity) {
                        $keep = ($d1['doi'] && !$d2['doi']) || ($d1['cited_by'] >= $d2['cited_by']) ? $d1 : $d2;
                        $discard = $keep['id'] === $d1['id'] ? $d2 : $d1;

                        $suggestions[] = [
                            'keep'       => $keep,
                            'discard'    => $discard,
                            'similarity' => round($sim * 100, 1),
                        ];
                    }
                }
            }
        }

        usort($suggestions, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

        return array_slice($suggestions, 0, 100);
    }

    /**
     * Execute document merge.
     */
    public function mergeDocuments(int $keepId, int $discardId): void
    {
        $conn = $this->em->getConnection();
        $conn->beginTransaction();

        try {
            // 1. Load both documents to merge properties
            $keepDoc = $conn->fetchAssociative('SELECT * FROM document WHERE id = ?', [$keepId]);
            $discardDoc = $conn->fetchAssociative('SELECT * FROM document WHERE id = ?', [$discardId]);

            if (!$keepDoc || !$discardDoc) {
                throw new \InvalidArgumentException('Documento não encontrado.');
            }

            // 2. Merge columns (take most complete fields)
            $updates = [];
            foreach ($keepDoc as $col => $val) {
                if ($col === 'id' || $col === 'created_at') continue;
                if ($val === null && $discardDoc[$col] !== null) {
                    $updates[$col] = $discardDoc[$col];
                }
            }

            // Cited by = MAX citation count
            $keepCitations = (int)($keepDoc['cited_by'] ?? 0);
            $discardCitations = (int)($discardDoc['cited_by'] ?? 0);
            if ($discardCitations > $keepCitations) {
                $updates['cited_by'] = $discardCitations;
            }

            if (!empty($updates)) {
                // Colunas entre crases: `references` é palavra reservada do MySQL
                [$sql, $params] = self::buildUpdateStatement('document', $updates, 'id', $keepId);
                $conn->executeStatement($sql, $params);
            }

            // 3. Move document authors
            $discardAuthors = $conn->fetchAllAssociative(
                'SELECT author_identity_id, position, original_name, effective_author_id FROM document_author WHERE document_id = ?',
                [$discardId]
            );
            foreach ($discardAuthors as $da) {
                $exists = $conn->fetchOne(
                    'SELECT id FROM document_author WHERE document_id = ? AND author_identity_id = ?',
                    [$keepId, $da['author_identity_id']]
                );
                if (!$exists) {
                    $conn->insert('document_author', [
                        'document_id'        => $keepId,
                        'author_identity_id' => $da['author_identity_id'],
                        'position'           => $da['position'],
                        'original_name'      => $da['original_name'],
                        'effective_author_id' => (int) $da['effective_author_id'],
                    ]);
                }
            }
            $conn->executeStatement('DELETE FROM document_author WHERE document_id = ?', [$discardId]);

            // 4. Move document keywords
            $discardKws = $conn->fetchAllAssociative(
                'SELECT keyword_id, original_term, effective_keyword_id FROM document_keyword WHERE document_id = ?',
                [$discardId]
            );
            foreach ($discardKws as $dk) {
                $exists = $conn->fetchOne(
                    'SELECT id FROM document_keyword WHERE document_id = ? AND keyword_id = ?',
                    [$keepId, $dk['keyword_id']]
                );
                if (!$exists) {
                    $conn->insert('document_keyword', [
                        'document_id'   => $keepId,
                        'keyword_id'    => $dk['keyword_id'],
                        'original_term' => $dk['original_term'], // NOT NULL: sem ele o merge falhava
                        'effective_keyword_id' => (int) $dk['effective_keyword_id'],
                    ]);
                }
            }
            $conn->executeStatement('DELETE FROM document_keyword WHERE document_id = ?', [$discardId]);
            // O efetivo foi copiado do descartado; recalcula o do mantido (cobre projeto de dono ou escopo diferente)
            $this->authority()->refreshDocuments([$keepId]);

            // 4b. Vínculos geográficos e institucionais: sem isto o CASCADE do DELETE abaixo os apagaria
            foreach (['documento_paises' => 'country_id', 'documento_estados' => 'state_id', 'documento_cidades' => 'city_id'] as $table => $col) {
                $conn->executeStatement(
                    "INSERT IGNORE INTO {$table} (document_id, {$col}) SELECT ?, {$col} FROM {$table} WHERE document_id = ?",
                    [$keepId, $discardId]
                );
            }
            $conn->executeStatement(
                'INSERT INTO documento_instituicoes (document_id, institution_id, link_type)
                 SELECT ?, di.institution_id, di.link_type FROM documento_instituicoes di
                 WHERE di.document_id = ?
                   AND NOT EXISTS (SELECT 1 FROM documento_instituicoes k WHERE k.document_id = ? AND k.institution_id = di.institution_id)',
                [$keepId, $discardId, $keepId]
            );
            // Classificação temática: o documento mantido herda os grupos do descartado
            $conn->executeStatement(
                'UPDATE IGNORE document_classification SET document_id = ? WHERE document_id = ?',
                [$keepId, $discardId]
            );

            // 5. Delete discarded document
            $conn->executeStatement('DELETE FROM document WHERE id = ?', [$discardId]);

            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    /**
     * UPDATE com os nomes de coluna entre crases (DBAL::update não os protege).
     *
     * @return array{0: string, 1: array}  SQL e parâmetros
     */
    public static function buildUpdateStatement(string $table, array $updates, string $keyColumn, int|string $keyValue): array
    {
        $sets = [];
        $params = [];
        foreach ($updates as $col => $val) {
            $sets[] = '`' . str_replace('`', '``', (string) $col) . '` = ?';
            $params[] = $val;
        }
        $params[] = $keyValue;

        return [
            sprintf('UPDATE `%s` SET %s WHERE `%s` = ?', $table, implode(', ', $sets), $keyColumn),
            $params,
        ];
    }

    // ── Helper: String Similarity Heuristic ───────────────────────────────────

    private function calculateSimilarity(string $s1, string $s2): float
    {
        return self::similarityPrepared(self::prepareSimilarity($s1), self::prepareSimilarity($s2), 0.0);
    }

    /** Parte de cada texto que não depende do outro: minúsculas, forma só com a-z0-9 e espaço, tamanho em bytes e histograma de caracteres. */
    public static function prepareSimilarity(string $s): array
    {
        $lower = strtolower(trim($s));
        $len = strlen($lower);

        // Histograma por grupos de bytes (28 grupos: a-z, "dígito ou espaço" e o resto). Só para textos que
        // vão a Levenshtein (até 255 bytes); serve ao limite inferior de similarityPrepared.
        $hist = null;
        if ($len > 0 && $len <= 255) {
            static $map = null;
            if ($map === null) {
                $map = array_fill(0, 256, 27);
                foreach (range('a', 'z') as $i => $ch) {
                    $map[ord($ch)] = $i;
                }
                for ($d = 0; $d < 10; $d++) {
                    $map[ord((string) $d)] = 26;
                }
                $map[32] = 26;
            }
            $hist = array_fill(0, 28, 0);
            for ($k = 0; $k < $len; $k++) {
                $hist[$map[ord($lower[$k])]]++;
            }
        }

        return [
            'lower' => $lower,
            'clean' => preg_replace('/[^a-z0-9 ]/', '', $lower),
            'len'   => $len,
            'hist'  => $hist,
        ];
    }

    /**
     * Similaridade (0 a 1) entre dois textos preparados. Mesma regra de sempre: iguais = 1,0;
     * iguais sem pontuação = 0,98; textos longos (> 255) por similar_text; os demais por Levenshtein.
     *
     * Com $threshold > 0, devolve null quando já se sabe que a similaridade fica abaixo do limiar,
     * sem calcular Levenshtein. Dois limites inferiores da distância, ambos exatos:
     *  - a diferença de tamanho (cada inserção ou remoção muda o tamanho em 1);
     *  - metade da diferença entre os histogramas de caracteres (uma inserção ou remoção muda o
     *    histograma em 1 e uma troca em 2).
     * Com $threshold = 0 nunca devolve null.
     */
    public static function similarityPrepared(array $p1, array $p2, float $threshold): ?float
    {
        if ($p1['lower'] === $p2['lower']) return 1.0;
        if ($p1['clean'] === $p2['clean']) return 0.98;

        $maxLen = max($p1['len'], $p2['len']);

        if ($maxLen === 0) return 1.0;

        if ($maxLen > 255) {
            similar_text($p1['lower'], $p2['lower'], $percent);
            return $percent / 100;
        }

        if ($threshold > 0) {
            // Máximo de edições que ainda deixa a similaridade no limiar. A margem de 1e-9 evita que o
            // arredondamento decimal (ex.: 1 - 0,85 = 0,15000000000000002) descarte um par que chega.
            $allowed = (int) floor($maxLen * (1 - $threshold) + 1e-9);
            if (abs($p1['len'] - $p2['len']) > $allowed) {
                return null;
            }
            if ($p1['hist'] !== null && $p2['hist'] !== null) {
                $limit = 2 * $allowed;
                $sum = 0;
                $h1 = $p1['hist'];
                $h2 = $p2['hist'];
                for ($k = 0; $k < 28; $k++) {
                    $x = $h1[$k] - $h2[$k];
                    $sum += $x < 0 ? -$x : $x;
                    if ($sum > $limit) {
                        return null;
                    }
                }
            }
        }

        $lev = levenshtein($p1['lower'], $p2['lower']);
        return 1 - ($lev / $maxLen);
    }

    // ── Export & Import Normalization Rules ────────────────────────────────────

    /**
     * Desfaz o apóstrofo que a exportação põe antes de células que começam com = + - @
     * (proteção contra injeção de fórmula no Excel), para o CSV exportado poder ser reimportado.
     */
    public static function unescapeCsvCell(mixed $value): string
    {
        $value = (string) $value;
        if (preg_match("/^'[=+\\-@\t\r]/", $value)) {
            return substr($value, 1);
        }

        return $value;
    }

    public function exportAuthorNormalization(int $projectId): array
    {
        $conn = $this->em->getConnection();

        return $conn->fetchAllAssociative('
            SELECT DISTINCT
                ai.preferred_name AS preferred_name,
                anv.original_name AS variant_name,
                anv.source AS source,
                anv.created_at AS created_at
            FROM author_identity ai
            JOIN author_name_variant anv ON ai.id = anv.author_identity_id
            JOIN document_author da ON ai.id = da.author_identity_id
            JOIN document d ON da.document_id = d.id
            WHERE d.project_id = ? AND anv.original_name != ai.preferred_name
            ORDER BY ai.preferred_name ASC, anv.original_name ASC
        ', [$projectId]);
    }

    /**
     * Importa o CSV de normalização de autores: cada par vira um agrupamento (administrador: global; usuário
     * comum: do projeto), sem apagar nem repontuar nada. Pares recusados ficam em lastRefusals().
     */
    public function importAuthorNormalization(int $projectId, array $records, bool $asAdmin = false): int
    {
        $conn = $this->em->getConnection();
        $appliedCount = 0;
        $this->lastRefusals = [];
        [$scope, $scopeId] = $this->scopeFor($projectId, $asAdmin);

        $authors = $conn->fetchAllAssociative('
            SELECT ai.id, ai.preferred_name, ai.normalized_name
            FROM author_identity ai
            JOIN document_author da ON ai.id = da.author_identity_id
            JOIN document d ON da.document_id = d.id
            WHERE d.project_id = ?
            GROUP BY ai.id, ai.preferred_name, ai.normalized_name
        ', [$projectId]);

        $authorMap = [];
        foreach ($authors as $a) {
            $authorMap[\App\Service\Import\DocumentEnrichmentService::normalize($a['preferred_name'])] = (int)$a['id'];
        }

        $variations = $conn->fetchAllAssociative('
            SELECT DISTINCT anv.author_identity_id, anv.normalized_name
            FROM author_name_variant anv
            JOIN document_author da ON anv.author_identity_id = da.author_identity_id
            JOIN document d ON da.document_id = d.id
            WHERE d.project_id = ?
        ', [$projectId]);

        foreach ($variations as $v) {
            $authorMap[$v['normalized_name']] = (int)$v['author_identity_id'];
        }

        foreach ($records as $record) {
            $record = array_map([self::class, 'unescapeCsvCell'], $record);
            $preferredName = trim($record['preferred_name'] ?? $record['keep_name'] ?? $record['preferred'] ?? $record['keep'] ?? '');
            $variantName   = trim($record['variant_name'] ?? $record['discard_name'] ?? $record['variant'] ?? $record['discard'] ?? '');

            if ($preferredName === '' || $variantName === '' || strcasecmp($preferredName, $variantName) === 0) {
                continue;
            }

            $normPreferred = \App\Service\Import\DocumentEnrichmentService::normalize($preferredName);
            $normVariant   = \App\Service\Import\DocumentEnrichmentService::normalize($variantName);

            $keepId = $authorMap[$normPreferred] ?? null;
            if (!$keepId) {
                $keepId = $conn->fetchOne('SELECT id FROM author_identity WHERE normalized_name = ?', [$normPreferred]);
                if (!$keepId) {
                    $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
                    $conn->insert('author_identity', [
                        'preferred_name' => $preferredName,
                        'normalized_name' => $normPreferred,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $keepId = (int)$conn->lastInsertId();
                } else {
                    $keepId = (int)$keepId;
                }
                $authorMap[$normPreferred] = $keepId;
            }

            $discardId = $authorMap[$normVariant] ?? null;
            // Compara as identidades EFETIVAS: quem já foi agrupado conta como o alvo dele
            $finalKeep    = $keepId ? $this->finalOrRaw(AuthorityMappingService::ENTITY_AUTHOR, $keepId, $scope, $scopeId) : 0;
            $finalDiscard = $discardId ? $this->finalOrRaw(AuthorityMappingService::ENTITY_AUTHOR, $discardId, $scope, $scopeId) : 0;
            if ($discardId && $finalDiscard !== $finalKeep) {
                try {
                    if ($this->mergeAuthors($finalKeep, $finalDiscard, $projectId, ['via' => 'csv'], $asAdmin)) {
                        $appliedCount++;
                    }
                } catch (AuthorityMappingException $e) {
                    $this->lastRefusals[] = $e->getMessage();
                    continue;
                }
                // O descartado foi agrupado: os nomes dele passam a apontar para o mantido
                foreach ($authorMap as $mapKey => $mapId) {
                    if ($mapId === $discardId || $mapId === $finalDiscard) {
                        $authorMap[$mapKey] = $finalKeep;
                    }
                }
            } elseif (!$asAdmin) {
                // Cadastrar variação de nome altera a identidade para todos os projetos: só o administrador
                if (!$discardId) {
                    $this->lastRefusals[] = sprintf('"%s" não aparece neste projeto; só o administrador cadastra variações de nome.', $variantName);
                }
            } else {
                $existsVar = $conn->fetchOne(
                    'SELECT id FROM author_name_variant WHERE author_identity_id = ? AND normalized_name = ?',
                    [$keepId, $normVariant]
                );
                if (!$existsVar) {
                    $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
                    $conn->insert('author_name_variant', [
                        'author_identity_id' => $keepId,
                        'original_name'      => $variantName,
                        'display_name'       => $variantName,
                        'normalized_name'    => $normVariant,
                        'source'             => 'alternative',
                        'confidence'         => 1.0,
                        'created_at'         => $now,
                        'updated_at'         => $now,
                    ]);
                    $appliedCount++;
                }
            }
        }

        return $appliedCount;
    }

    public function exportKeywordNormalization(int $projectId): array
    {
        $conn = $this->em->getConnection();

        return $conn->fetchAllAssociative('
            SELECT DISTINCT
                k.keyword_display AS preferred_keyword,
                kv.variation_name AS variant_keyword,
                k.keyword_type AS keyword_type,
                kv.status AS status
            FROM keyword k
            JOIN palavra_chave_variacoes_nome kv ON k.id = kv.keyword_id
            JOIN document_keyword dk ON k.id = dk.keyword_id
            JOIN document d ON dk.document_id = d.id
            WHERE d.project_id = ? AND kv.variation_name COLLATE utf8mb4_unicode_ci != k.keyword_display COLLATE utf8mb4_unicode_ci
            ORDER BY k.keyword_display ASC, kv.variation_name ASC
        ', [$projectId]);
    }

    /**
     * Importa o CSV de normalização de palavras-chave: cada par vira um agrupamento (administrador: global;
     * usuário comum: do projeto), sem apagar nem repontuar nada. Pares recusados ficam em lastRefusals().
     */
    public function importKeywordNormalization(int $projectId, array $records, bool $asAdmin = false): int
    {
        $conn = $this->em->getConnection();
        $appliedCount = 0;
        $this->lastRefusals = [];
        [$scope, $scopeId] = $this->scopeFor($projectId, $asAdmin);
        $textNormalizer = new \App\Service\Import\TextNormalizer();

        $keywords = $conn->fetchAllAssociative('
            SELECT k.id, k.keyword_display, k.keyword_original, k.keyword_type
            FROM keyword k
            JOIN document_keyword dk ON k.id = dk.keyword_id
            JOIN document d ON dk.document_id = d.id
            WHERE d.project_id = ?
            GROUP BY k.id, k.keyword_display, k.keyword_original, k.keyword_type
        ', [$projectId]);

        $keywordMap = [];
        foreach ($keywords as $k) {
            $norm = \App\Service\Import\DocumentEnrichmentService::normalize($k['keyword_display']);
            $type = $k['keyword_type'];
            $keywordMap[$type . '_' . $norm] = (int)$k['id'];
            $keywordMap['all_' . $norm] = (int)$k['id'];
        }

        $variations = $conn->fetchAllAssociative('
            SELECT DISTINCT kv.keyword_id, kv.normalized_name, k.keyword_type
            FROM palavra_chave_variacoes_nome kv
            JOIN keyword k ON kv.keyword_id = k.id
            JOIN document_keyword dk ON k.id = dk.keyword_id
            JOIN document d ON dk.document_id = d.id
            WHERE d.project_id = ?
        ', [$projectId]);

        foreach ($variations as $v) {
            $keywordMap[$v['keyword_type'] . '_' . $v['normalized_name']] = (int)$v['keyword_id'];
            $keywordMap['all_' . $v['normalized_name']] = (int)$v['keyword_id'];
        }

        foreach ($records as $record) {
            $record = array_map([self::class, 'unescapeCsvCell'], $record);
            $preferredTerm = trim($record['preferred_keyword'] ?? $record['keep_term'] ?? $record['preferred'] ?? $record['keep'] ?? '');
            $variantTerm   = trim($record['variant_keyword'] ?? $record['discard_term'] ?? $record['variant'] ?? $record['discard'] ?? '');
            $kwType        = trim($record['keyword_type'] ?? $record['type'] ?? 'author_keyword');

            if ($kwType === 'author') $kwType = 'author_keyword';
            if ($kwType === 'indexed') $kwType = 'indexed_keyword';

            if ($preferredTerm === '' || $variantTerm === '' || strcasecmp($preferredTerm, $variantTerm) === 0) {
                continue;
            }

            $normPreferred = \App\Service\Import\DocumentEnrichmentService::normalize($preferredTerm);
            $normVariant   = \App\Service\Import\DocumentEnrichmentService::normalize($variantTerm);

            $keepId = $keywordMap[$kwType . '_' . $normPreferred] ?? $keywordMap['all_' . $normPreferred] ?? null;

            if (!$keepId) {
                $keepId = $conn->fetchOne('SELECT id FROM keyword WHERE keyword_type = ? AND keyword_original = ?', [$kwType, $preferredTerm]);
                if (!$keepId) {
                    // Mesmo tratamento da importação: exibição limpa, forma normalizada e conceito próprio
                    $res = $textNormalizer->normalizeKeyword($preferredTerm);
                    if (!$res['valid']) {
                        continue;
                    }
                    $normKey = \App\Service\Import\DocumentImportService::cut($res['normalized'], 255);
                    $keepId = $conn->fetchOne('SELECT id FROM keyword WHERE keyword_type = ? AND keyword_normalized = ?', [$kwType, $normKey]);
                    if (!$keepId) {
                        $conn->insert('keyword', [
                            'keyword_original'   => \App\Service\Import\DocumentImportService::cut($preferredTerm, 255),
                            'keyword_display'    => \App\Service\Import\DocumentImportService::cut($textNormalizer->cleanDisplayValue($preferredTerm), 255),
                            'keyword_normalized' => $normKey,
                            'keyword_type'       => $kwType,
                            'status'             => $res['needs_review'] ? 0 : 1,
                            'review_reasons'     => !empty($res['review_reasons']) ? implode(',', $res['review_reasons']) : null,
                        ]);
                        $keepId = (int)$conn->lastInsertId();
                        $conn->executeStatement('UPDATE keyword SET keyword_concept_id = ? WHERE id = ?', [$keepId, $keepId]);
                    }
                }
                $keepId = (int)$keepId;
                $keywordMap[$kwType . '_' . $normPreferred] = $keepId;
                $keywordMap['all_' . $normPreferred] = $keepId;
            }

            $discardId = $keywordMap[$kwType . '_' . $normVariant] ?? $keywordMap['all_' . $normVariant] ?? null;

            $finalKeep    = $this->finalOrRaw(AuthorityMappingService::ENTITY_KEYWORD, (int) $keepId, $scope, $scopeId);
            $finalDiscard = $discardId ? $this->finalOrRaw(AuthorityMappingService::ENTITY_KEYWORD, (int) $discardId, $scope, $scopeId) : 0;
            if ($discardId && $finalDiscard !== $finalKeep) {
                try {
                    if ($this->mergeKeywords($finalKeep, $finalDiscard, $projectId, ['via' => 'csv'], $asAdmin)) {
                        $appliedCount++;
                    }
                } catch (AuthorityMappingException $e) {
                    $this->lastRefusals[] = $e->getMessage();
                    continue;
                }
                // O descartado foi agrupado: as chaves dele passam a apontar para o mantido
                foreach ($keywordMap as $mapKey => $mapId) {
                    if ($mapId === $discardId || $mapId === $finalDiscard) {
                        $keywordMap[$mapKey] = $finalKeep;
                    }
                }
            } elseif (!$asAdmin) {
                // Cadastrar variação altera a palavra-chave para todos os projetos: só o administrador
                if (!$discardId) {
                    $this->lastRefusals[] = sprintf('"%s" não aparece neste projeto; só o administrador cadastra variações de palavra-chave.', $variantTerm);
                }
            } else {
                $existsVar = $conn->fetchOne(
                    'SELECT id FROM palavra_chave_variacoes_nome WHERE keyword_id = ? AND normalized_name = ?',
                    [$keepId, $normVariant]
                );
                if (!$existsVar) {
                    $conn->insert('palavra_chave_variacoes_nome', [
                        'keyword_id'      => $keepId,
                        'variation_name'  => $variantTerm,
                        'normalized_name' => $normVariant,
                        'variation_type'  => 'alternative',
                        'status'          => 1
                    ]);
                    $appliedCount++;
                }
            }
        }

        return $appliedCount;
    }
}
