<?php

namespace App\Service\Authority;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Diário só-de-inserção das ações que repontuam, apagam ou fundem autores, palavras-chave e instituições
 * (tabelas authority_merge_log e authority_merge_log_link).
 *
 * Usa a conexão do EntityManager, a mesma e a mesma transação do chamador: se o diário falhar, a exceção
 * sobe e a ação inteira é desfeita (nada de catch silencioso). logLinks() deve ser chamado ANTES do
 * comando que muda ou apaga os vínculos, porque copia o estado de hoje com INSERT ... SELECT (sem trazer
 * linhas ao PHP, seguro para milhares de fusões).
 */
class AuthorityMergeLogger
{
    public const ORIGINS = ['screen_project', 'screen_admin', 'treatment', 'enrichment', 'command'];
    /** 'undo' (fase 2) desfaz um agrupamento; a coluna action é VARCHAR(30), sem migration. */
    public const ACTIONS = ['merge', 'delete', 'discard', 'remap', 'undo'];
    public const ENTITY_TYPES = ['author', 'keyword', 'institution'];

    /** tabela de vínculo => [coluna do id da entidade, coluna do texto original ou null, coluna da posição ou null] */
    private const LINK_TABLES = [
        'document_author'        => ['author_identity_id', 'original_name', 'position'],
        'document_keyword'       => ['keyword_id', 'original_term', null],
        'documento_instituicoes' => ['institution_id', 'link_type', null],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ?Security $security = null,
        private readonly ?RequestStack $requestStack = null,
    ) {}

    /**
     * Grava o cabeçalho da ação e devolve o id. $userId e $projectId nulos: o usuário vem do Security
     * (nulo na linha de comando) e o projeto, nas telas de normalização do projeto, da rota
     * /projects/{id}/normalize. Origem 'treatment', 'enrichment' e 'command' nunca levam usuário.
     */
    public function begin(
        string $origin,
        string $action,
        string $entityType,
        int $sourceId,
        ?int $targetId,
        string $label,
        array $payload = [],
        ?int $userId = null,
        ?int $projectId = null,
    ): int {
        if (!in_array($origin, self::ORIGINS, true)) {
            throw new \InvalidArgumentException("Origem de diário inválida: {$origin}");
        }
        if (!in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException("Ação de diário inválida: {$action}");
        }
        if (!in_array($entityType, self::ENTITY_TYPES, true)) {
            throw new \InvalidArgumentException("Tipo de entidade de diário inválido: {$entityType}");
        }

        if ($userId === null && !in_array($origin, ['treatment', 'enrichment', 'command'], true)) {
            $userId = $this->currentUserId();
        }
        if ($projectId === null && $origin === 'screen_project') {
            $projectId = $this->currentProjectId();
        }

        $conn = $this->em->getConnection();
        $conn->insert('authority_merge_log', [
            'created_at'   => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'user_id'      => $userId,
            'project_id'   => $projectId,
            'origin'       => $origin,
            'action'       => $action,
            'entity_type'  => $entityType,
            'source_id'    => $sourceId,
            'target_id'    => $targetId,
            'source_label' => mb_substr($label, 0, 500),
            'payload'      => $payload === [] ? null : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        ]);

        return (int) $conn->lastInsertId();
    }

    /**
     * Copia para o diário os vínculos da entidade que vão mudar (antes da alteração).
     * $onlyLinkId limita a um vínculo (id da linha da tabela de vínculo); $onlyOriginalText, aos vínculos com esse texto.
     *
     * @return int quantidade de vínculos gravados
     */
    public function logLinks(int $logId, string $linkTable, int $entityId, ?int $onlyLinkId = null, ?string $onlyOriginalText = null): int
    {
        if (!isset(self::LINK_TABLES[$linkTable])) {
            throw new \InvalidArgumentException("Tabela de vínculo inválida para o diário: {$linkTable}");
        }
        [$entityCol, $textCol, $posCol] = self::LINK_TABLES[$linkTable];

        $sql = sprintf(
            'INSERT INTO authority_merge_log_link (log_id, document_id, link_table, old_entity_id, original_text, position)
             SELECT ?, t.document_id, ?, t.%s, %s, %s FROM %s t WHERE t.%s = ?',
            $entityCol,
            $textCol !== null ? "t.{$textCol}" : 'NULL',
            $posCol !== null ? "t.{$posCol}" : 'NULL',
            $linkTable,
            $entityCol
        );
        $params = [$logId, $linkTable, $entityId];
        if ($onlyLinkId !== null) {
            $sql .= ' AND t.id = ?';
            $params[] = $onlyLinkId;
        }
        if ($onlyOriginalText !== null && $textCol !== null) {
            // Mesma comparação (e mesma collation) do UPDATE que o chamador faz em seguida
            $sql .= " AND t.{$textCol} = ?";
            $params[] = $onlyOriginalText;
        }

        return (int) $this->em->getConnection()->executeStatement($sql, $params);
    }

    /**
     * Fusão (ou remapeamento) de uma identidade de autor: cabeçalho com o nome, as variações e os identificadores
     * externos dela, mais uma linha por vínculo de document_author. Chamar ANTES de repontuar os vínculos.
     */
    public function logAuthorMerge(string $origin, string $action, int $sourceId, ?int $targetId, ?int $userId = null, ?int $projectId = null, array $extra = []): int
    {
        $conn = $this->em->getConnection();
        $row = $conn->fetchAssociative('SELECT preferred_name, normalized_name FROM author_identity WHERE id = ?', [$sourceId]) ?: [];
        $payload = $extra + [
            'normalized_name' => $row['normalized_name'] ?? null,
            'variations'      => $conn->fetchFirstColumn('SELECT original_name FROM author_name_variant WHERE author_identity_id = ?', [$sourceId]),
            'identifiers'     => $conn->fetchAllAssociative('SELECT provider, identifier FROM author_external_identifier WHERE author_identity_id = ?', [$sourceId]),
        ];

        return $this->logMerge($origin, $action, 'author', $sourceId, $targetId, (string) ($row['preferred_name'] ?? ''), 'document_author', $payload, $userId, $projectId);
    }

    /** Idem para palavra-chave (variações e tipo no payload; vínculos de document_keyword). */
    public function logKeywordMerge(string $origin, string $action, int $sourceId, ?int $targetId, ?int $userId = null, ?int $projectId = null, array $extra = []): int
    {
        $conn = $this->em->getConnection();
        $row = $conn->fetchAssociative('SELECT keyword_original, keyword_normalized, keyword_type FROM keyword WHERE id = ?', [$sourceId]) ?: [];
        $payload = $extra + [
            'normalized' => $row['keyword_normalized'] ?? null,
            'type'       => $row['keyword_type'] ?? null,
            'variations' => $conn->fetchFirstColumn('SELECT variation_name FROM palavra_chave_variacoes_nome WHERE keyword_id = ?', [$sourceId]),
        ];

        return $this->logMerge($origin, $action, 'keyword', $sourceId, $targetId, (string) ($row['keyword_original'] ?? ''), 'document_keyword', $payload, $userId, $projectId);
    }

    /** Origem padrão quando o chamador não informa: tela de administração se há requisição web; senão, comando. */
    public function defaultOrigin(): string
    {
        return $this->requestStack?->getMainRequest() !== null ? 'screen_admin' : 'command';
    }

    /** Cabeçalho + vínculos numa chamada só (o uso comum nas fusões). */
    public function logMerge(
        string $origin,
        string $action,
        string $entityType,
        int $sourceId,
        ?int $targetId,
        string $label,
        string $linkTable,
        array $payload = [],
        ?int $userId = null,
        ?int $projectId = null,
    ): int {
        $logId = $this->begin($origin, $action, $entityType, $sourceId, $targetId, $label, $payload, $userId, $projectId);
        $this->logLinks($logId, $linkTable, $sourceId);

        return $logId;
    }

    private function currentUserId(): ?int
    {
        $user = $this->security?->getUser();

        return ($user !== null && method_exists($user, 'getId') && $user->getId() !== null) ? (int) $user->getId() : null;
    }

    private function currentProjectId(): ?int
    {
        $request = $this->requestStack?->getMainRequest();
        $route = (string) $request?->attributes->get('_route', '');
        if ($request === null || !str_starts_with($route, 'app_normalize_')) {
            return null;
        }
        $id = $request->attributes->get('id');

        return is_numeric($id) ? (int) $id : null;
    }
}
