<?php

namespace App\Entity;

use App\Repository\CountryVariationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CountryVariationRepository::class)]
#[ORM\Table(name: 'pais_variacoes_nome')]
#[ORM\Index(fields: ['normalizedName'])]
class CountryVariation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Country::class, inversedBy: 'variations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Country $country = null;

    #[ORM\Column(length: 500)]
    private string $variationName = '';

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $variationType = null;

    #[ORM\Column(length: 500)]
    private string $normalizedName = '';

    #[ORM\Column(options: ['default' => true])]
    private bool $status = true;

    public function getId(): ?int { return $this->id; }

    public function getCountry(): ?Country { return $this->country; }
    public function setCountry(?Country $country): static { $this->country = $country; return $this; }

    public function getVariationName(): string { return $this->variationName; }
    public function setVariationName(string $v): static { $this->variationName = $v; return $this; }

    public function getVariationType(): ?string { return $this->variationType; }
    public function setVariationType(?string $v): static { $this->variationType = $v; return $this; }

    public function getNormalizedName(): string { return $this->normalizedName; }
    public function setNormalizedName(string $v): static { $this->normalizedName = $v; return $this; }

    public function isStatus(): bool { return $this->status; }
    public function setStatus(bool $v): static { $this->status = $v; return $this; }
}
