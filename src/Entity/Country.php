<?php

namespace App\Entity;

use App\Repository\CountryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CountryRepository::class)]
#[ORM\Table(name: 'paises')]
#[ORM\HasLifecycleCallbacks]
class Country
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $officialName = '';

    #[ORM\Column(length: 100)]
    private string $commonName = '';

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $sigla = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $isoCode = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $continente = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $nationality = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $status = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, CountryVariation> */
    #[ORM\OneToMany(targetEntity: CountryVariation::class, mappedBy: 'country', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $variations;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->variations = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getOfficialName(): string { return $this->officialName; }
    public function setOfficialName(string $v): static { $this->officialName = $v; return $this; }

    public function getCommonName(): string { return $this->commonName; }
    public function setCommonName(string $v): static { $this->commonName = $v; return $this; }

    public function getSigla(): ?string { return $this->sigla; }
    public function setSigla(?string $v): static { $this->sigla = $v; return $this; }

    public function getIsoCode(): ?string { return $this->isoCode; }
    public function setIsoCode(?string $v): static { $this->isoCode = $v; return $this; }

    public function getContinente(): ?string { return $this->continente; }
    public function setContinente(?string $v): static { $this->continente = $v; return $this; }

    public function getNationality(): ?string { return $this->nationality; }
    public function setNationality(?string $v): static { $this->nationality = $v; return $this; }

    public function isStatus(): bool { return $this->status; }
    public function setStatus(bool $v): static { $this->status = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** @return Collection<int, CountryVariation> */
    public function getVariations(): Collection { return $this->variations; }

    public function addVariation(CountryVariation $variation): static
    {
        if (!$this->variations->contains($variation)) {
            $this->variations->add($variation);
            $variation->setCountry($this);
        }
        return $this;
    }

    public function removeVariation(CountryVariation $variation): static
    {
        if ($this->variations->removeElement($variation)) {
            if ($variation->getCountry() === $this) {
                $variation->setCountry(null);
            }
        }
        return $this;
    }
}
