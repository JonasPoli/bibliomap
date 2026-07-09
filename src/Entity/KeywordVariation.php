<?php

namespace App\Entity;

use App\Repository\KeywordVariationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: KeywordVariationRepository::class)]
#[ORM\Table(name: 'palavra_chave_variacoes_nome')]
#[ORM\Index(fields: ['normalizedName'])]
class KeywordVariation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Keyword::class, inversedBy: 'variations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Keyword $keyword = null;

    #[ORM\Column(length: 255)]
    private string $variationName = '';

    #[ORM\Column(length: 255)]
    private string $normalizedName = '';

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $variationType = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $status = true;

    public function getId(): ?int { return $this->id; }

    public function getKeyword(): ?Keyword { return $this->keyword; }
    public function setKeyword(?Keyword $keyword): static { $this->keyword = $keyword; return $this; }

    public function getVariationName(): string { return $this->variationName; }
    public function setVariationName(string $v): static { $this->variationName = $v; return $this; }

    public function getNormalizedName(): string { return $this->normalizedName; }
    public function setNormalizedName(string $v): static { $this->normalizedName = $v; return $this; }

    public function getVariationType(): ?string { return $this->variationType; }
    public function setVariationType(?string $v): static { $this->variationType = $v; return $this; }

    public function isStatus(): bool { return $this->status; }
    public function setStatus(bool $v): static { $this->status = $v; return $this; }
}
