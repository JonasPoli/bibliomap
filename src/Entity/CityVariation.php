<?php

namespace App\Entity;

use App\Repository\CityVariationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CityVariationRepository::class)]
#[ORM\Table(name: 'cidade_variacoes_nome')]
#[ORM\Index(fields: ['normalizedName'])]
class CityVariation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: City::class, inversedBy: 'variations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?City $city = null;

    #[ORM\Column(length: 255)]
    private string $variationName = '';

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $variationType = null;

    #[ORM\Column(length: 255)]
    private string $normalizedName = '';

    #[ORM\Column(options: ['default' => true])]
    private bool $status = true;

    public function getId(): ?int { return $this->id; }

    public function getCity(): ?City { return $this->city; }
    public function setCity(?City $city): static { $this->city = $city; return $this; }

    public function getVariationName(): string { return $this->variationName; }
    public function setVariationName(string $v): static { $this->variationName = $v; return $this; }

    public function getVariationType(): ?string { return $this->variationType; }
    public function setVariationType(?string $v): static { $this->variationType = $v; return $this; }

    public function getNormalizedName(): string { return $this->normalizedName; }
    public function setNormalizedName(string $v): static { $this->normalizedName = $v; return $this; }

    public function isStatus(): bool { return $this->status; }
    public function setStatus(bool $v): static { $this->status = $v; return $this; }
}
