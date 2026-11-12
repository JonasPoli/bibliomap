<?php

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Diário só-de-inserção das ações que repontuam, apagam ou fundem autores, palavras-chave e instituições.
 * Sem chave estrangeira: o diário sobrevive à exclusão da entidade. Gravado por AuthorityMergeLogger (SQL).
 */
#[ORM\Entity]
#[ORM\Table(name: 'authority_merge_log')]
#[ORM\Index(name: 'idx_aml_entity', columns: ['entity_type', 'source_id'])]
class AuthorityMergeLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'user_id', nullable: true)]
    private ?int $userId = null;

    #[ORM\Column(name: 'project_id', nullable: true)]
    private ?int $projectId = null;

    /** screen_project | screen_admin | treatment | enrichment | command */
    #[ORM\Column(length: 30)]
    private string $origin = '';

    /** merge | delete | discard | remap */
    #[ORM\Column(length: 30)]
    private string $action = '';

    /** author | keyword | institution */
    #[ORM\Column(name: 'entity_type', length: 30)]
    private string $entityType = '';

    #[ORM\Column(name: 'source_id')]
    private int $sourceId = 0;

    #[ORM\Column(name: 'target_id', nullable: true)]
    private ?int $targetId = null;

    #[ORM\Column(name: 'source_label', length: 500)]
    private string $sourceLabel = '';

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $payload = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function getUserId(): ?int { return $this->userId; }
    public function getProjectId(): ?int { return $this->projectId; }
    public function getOrigin(): string { return $this->origin; }
    public function getAction(): string { return $this->action; }
    public function getEntityType(): string { return $this->entityType; }
    public function getSourceId(): int { return $this->sourceId; }
    public function getTargetId(): ?int { return $this->targetId; }
    public function getSourceLabel(): string { return $this->sourceLabel; }
    public function getPayload(): ?array { return $this->payload; }
}
