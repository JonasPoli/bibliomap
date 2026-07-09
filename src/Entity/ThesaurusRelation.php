<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use DateTimeImmutable;

#[ORM\Entity]
#[ORM\Table(name: 'thesaurus_relation')]
class ThesaurusRelation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ThesaurusConcept::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ThesaurusConcept $sourceConcept = null;

    #[ORM\ManyToOne(targetEntity: ThesaurusConcept::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ThesaurusConcept $targetConcept = null;

    #[ORM\Column(name: 'relation_type', length: 50)]
    private string $relationType = 'related'; // broader, narrower, related, exact_match, close_match

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

    public function getSourceConcept(): ?ThesaurusConcept { return $this->sourceConcept; }
    public function setSourceConcept(?ThesaurusConcept $c): self { $this->sourceConcept = $c; return $this; }

    public function getTargetConcept(): ?ThesaurusConcept { return $this->targetConcept; }
    public function setTargetConcept(?ThesaurusConcept $c): self { $this->targetConcept = $c; return $this; }

    public function getRelationType(): string { return $this->relationType; }
    public function setRelationType(string $type): self { $this->relationType = $type; return $this; }

    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(DateTimeImmutable $dt): self { $this->createdAt = $dt; return $this; }

    public function getUpdatedAt(): DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(DateTimeImmutable $dt): self { $this->updatedAt = $dt; return $this; }
}
