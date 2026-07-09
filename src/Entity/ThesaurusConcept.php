<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use DateTimeImmutable;

#[ORM\Entity]
#[ORM\Table(name: 'thesaurus_concept')]
class ThesaurusConcept
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ThesaurusScheme::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ThesaurusScheme $scheme = null;

    #[ORM\Column(name: 'preferred_label', length: 255)]
    private string $preferredLabel = '';

    #[ORM\Column(name: 'normalized_label', length: 255)]
    private string $normalizedLabel = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'external_code', length: 100, nullable: true)]
    private ?string $externalCode = null;

    #[ORM\Column(length: 20, options: ['default' => 'active'])]
    private string $status = 'active';

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

    public function getScheme(): ?ThesaurusScheme { return $this->scheme; }
    public function setScheme(?ThesaurusScheme $scheme): self { $this->scheme = $scheme; return $this; }

    public function getPreferredLabel(): string { return $this->preferredLabel; }
    public function setPreferredLabel(string $label): self { $this->preferredLabel = $label; return $this; }

    public function getNormalizedLabel(): string { return $this->normalizedLabel; }
    public function setNormalizedLabel(string $label): self { $this->normalizedLabel = $label; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $desc): self { $this->description = $desc; return $this; }

    public function getExternalCode(): ?string { return $this->externalCode; }
    public function setExternalCode(?string $code): self { $this->externalCode = $code; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }

    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(DateTimeImmutable $dt): self { $this->createdAt = $dt; return $this; }

    public function getUpdatedAt(): DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(DateTimeImmutable $dt): self { $this->updatedAt = $dt; return $this; }
}
