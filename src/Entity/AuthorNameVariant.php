<?php

namespace App\Entity;

use App\Repository\AuthorNameVariantRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AuthorNameVariantRepository::class)]
#[ORM\Table(name: 'author_name_variant')]
class AuthorNameVariant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AuthorIdentity::class, inversedBy: 'variations')]
    #[ORM\JoinColumn(name: 'author_identity_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?AuthorIdentity $authorIdentity = null;

    #[ORM\Column(name: 'original_name', length: 500)]
    private string $originalName = '';

    #[ORM\Column(name: 'display_name', length: 500)]
    private string $displayName = '';

    #[ORM\Column(name: 'normalized_name', length: 500)]
    private string $normalizedName = '';

    #[ORM\Column(length: 100)]
    private string $source = 'import';

    #[ORM\Column]
    private float $confidence = 1.0;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getAuthorIdentity(): ?AuthorIdentity { return $this->authorIdentity; }
    public function setAuthorIdentity(?AuthorIdentity $v): static { $this->authorIdentity = $v; return $this; }

    public function getOriginalName(): string { return $this->originalName; }
    public function setOriginalName(string $v): static { $this->originalName = $v; return $this; }

    public function getDisplayName(): string { return $this->displayName; }
    public function setDisplayName(string $v): static { $this->displayName = $v; return $this; }

    public function getNormalizedName(): string { return $this->normalizedName; }
    public function setNormalizedName(string $v): static { $this->normalizedName = $v; return $this; }

    public function getSource(): string { return $this->source; }
    public function setSource(string $v): static { $this->source = $v; return $this; }

    public function getConfidence(): float { return $this->confidence; }
    public function setConfidence(float $v): static { $this->confidence = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $v): static { $this->updatedAt = $v; return $this; }

    // Compatibility wrappers
    public function getVariationName(): string { return $this->originalName; }
    public function getVariationType(): string { return $this->source; }
}
