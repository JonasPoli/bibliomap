<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use DateTimeImmutable;

#[ORM\Entity]
#[ORM\Table(name: 'thesaurus_match')]
class ThesaurusMatch
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'entity_type', length: 50)]
    private string $entityType = 'keyword'; // keyword, institution, place, author

    #[ORM\Column(name: 'entity_id', type: 'integer', nullable: true)]
    private ?int $entityId = null;

    #[ORM\ManyToOne(targetEntity: Keyword::class)]
    #[ORM\JoinColumn(name: 'keyword_id', referencedColumnName: 'id', onDelete: 'CASCADE', nullable: true)]
    private ?Keyword $keyword = null;

    #[ORM\Column(name: 'original_value', length: 255)]
    private string $originalValue = '';

    #[ORM\Column(name: 'normalized_value', length: 255)]
    private string $normalizedValue = '';

    #[ORM\ManyToOne(targetEntity: ThesaurusConcept::class)]
    #[ORM\JoinColumn(name: 'concept_id', referencedColumnName: 'id', onDelete: 'SET NULL', nullable: true)]
    private ?ThesaurusConcept $concept = null;

    #[ORM\Column(type: 'float', options: ['default' => 1.0])]
    private float $confidence = 1.0;

    #[ORM\Column(name: 'match_method', length: 50, nullable: true)]
    private ?string $matchMethod = null; // exact_label, exact_concept, fuzzy, manual

    #[ORM\Column(length: 30, options: ['default' => 'pending'])]
    private string $status = 'pending'; // automatic, pending, reviewed, accepted, rejected

    #[ORM\Column(name: 'created_at')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getEntityType(): string { return $this->entityType; }
    public function setEntityType(string $type): self { $this->entityType = $type; return $this; }

    public function getEntityId(): ?int { return $this->entityId; }
    public function setEntityId(?int $id): self { $this->entityId = $id; return $this; }

    public function getKeyword(): ?Keyword { return $this->keyword; }
    public function setKeyword(?Keyword $kw): self { $this->keyword = $kw; return $this; }

    public function getOriginalValue(): string { return $this->originalValue; }
    public function setOriginalValue(string $val): self { $this->originalValue = $val; return $this; }

    public function getNormalizedValue(): string { return $this->normalizedValue; }
    public function setNormalizedValue(string $val): self { $this->normalizedValue = $val; return $this; }

    public function getConcept(): ?ThesaurusConcept { return $this->concept; }
    public function setConcept(?ThesaurusConcept $c): self { $this->concept = $c; return $this; }

    public function getConfidence(): float { return $this->confidence; }
    public function setConfidence(float $conf): self { $this->confidence = $conf; return $this; }

    public function getMatchMethod(): ?string { return $this->matchMethod; }
    public function setMatchMethod(?string $m): self { $this->matchMethod = $m; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }

    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(DateTimeImmutable $dt): self { $this->createdAt = $dt; return $this; }

    public function getUpdatedAt(): DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(DateTimeImmutable $dt): self { $this->updatedAt = $dt; return $this; }
}
