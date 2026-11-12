<?php

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Decisão de agrupamento de uma autoridade (autor ou palavra-chave) num escopo: global, do usuário ou do projeto.
 * A identidade efetiva de um vínculo é resolvida por AuthorityResolver: projeto, depois usuário dono do projeto,
 * depois global, depois o próprio id bruto. Sem chave estrangeira (o histórico sobrevive à exclusão das entidades).
 *
 * Autor: source_id e target_id são author_identity.id. Palavra-chave: source_id é keyword.id (o id bruto do
 * vínculo) e target_id usa o formato do id efetivo (positivo = keyword.id efetivo, negativo = -thesaurus_concept.id).
 */
#[ORM\Entity]
#[ORM\Table(name: 'authority_mapping')]
#[ORM\UniqueConstraint(name: 'uniq_am_scope_source', columns: ['entity_type', 'scope', 'scope_id', 'source_id'])]
#[ORM\Index(name: 'idx_am_source', columns: ['entity_type', 'source_id'])]
#[ORM\Index(name: 'idx_am_target', columns: ['entity_type', 'scope', 'scope_id', 'target_id'])]
class AuthorityMapping
{
    public const SCOPE_GLOBAL = 'global';
    public const SCOPE_USER = 'user';
    public const SCOPE_PROJECT = 'project';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** author | keyword */
    #[ORM\Column(name: 'entity_type', length: 20)]
    private string $entityType = '';

    /** global | user | project */
    #[ORM\Column(length: 10)]
    private string $scope = self::SCOPE_GLOBAL;

    /** 0 no global; id do usuário ou do projeto nos demais */
    #[ORM\Column(name: 'scope_id', options: ['default' => 0])]
    private int $scopeId = 0;

    #[ORM\Column(name: 'source_id')]
    private int $sourceId = 0;

    #[ORM\Column(name: 'target_id')]
    private int $targetId = 0;

    #[ORM\Column(length: 20)]
    private string $origin = '';

    #[ORM\Column(name: 'created_by', nullable: true)]
    private ?int $createdBy = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getEntityType(): string { return $this->entityType; }
    public function setEntityType(string $v): static { $this->entityType = $v; return $this; }
    public function getScope(): string { return $this->scope; }
    public function setScope(string $v): static { $this->scope = $v; return $this; }
    public function getScopeId(): int { return $this->scopeId; }
    public function setScopeId(int $v): static { $this->scopeId = $v; return $this; }
    public function getSourceId(): int { return $this->sourceId; }
    public function setSourceId(int $v): static { $this->sourceId = $v; return $this; }
    public function getTargetId(): int { return $this->targetId; }
    public function setTargetId(int $v): static { $this->targetId = $v; return $this; }
    public function getOrigin(): string { return $this->origin; }
    public function setOrigin(string $v): static { $this->origin = $v; return $this; }
    public function getCreatedBy(): ?int { return $this->createdBy; }
    public function setCreatedBy(?int $v): static { $this->createdBy = $v; return $this; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(DateTimeImmutable $v): static { $this->createdAt = $v; return $this; }
    public function getNote(): ?string { return $this->note; }
    public function setNote(?string $v): static { $this->note = $v; return $this; }
}
