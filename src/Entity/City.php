<?php

namespace App\Entity;

use App\Repository\CityRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CityRepository::class)]
#[ORM\Table(name: 'cidades')]
#[ORM\Index(fields: ['normalizedName'])]
#[ORM\HasLifecycleCallbacks]
class City
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Country::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Country $country = null;

    #[ORM\ManyToOne(targetEntity: State::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?State $state = null;

    #[ORM\Column(length: 255)]
    private string $officialName = '';

    #[ORM\Column(length: 255)]
    private string $normalizedName = '';

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $officialCode = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $status = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, CityVariation> */
    #[ORM\OneToMany(targetEntity: CityVariation::class, mappedBy: 'city', cascade: ['persist', 'remove'], orphanRemoval: true)]
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

    public function getCountry(): ?Country { return $this->country; }
    public function setCountry(?Country $country): static { $this->country = $country; return $this; }

    public function getState(): ?State { return $this->state; }
    public function setState(?State $state): static { $this->state = $state; return $this; }

    public function getOfficialName(): string { return $this->officialName; }
    public function setOfficialName(string $v): static { $this->officialName = $v; return $this; }

    public function getNormalizedName(): string { return $this->normalizedName; }
    public function setNormalizedName(string $v): static { $this->normalizedName = $v; return $this; }

    public function getOfficialCode(): ?string { return $this->officialCode; }
    public function setOfficialCode(?string $v): static { $this->officialCode = $v; return $this; }

    public function isStatus(): bool { return $this->status; }
    public function setStatus(bool $v): static { $this->status = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** @return Collection<int, CityVariation> */
    public function getVariations(): Collection { return $this->variations; }

    public function addVariation(CityVariation $variation): static
    {
        if (!$this->variations->contains($variation)) {
            $this->variations->add($variation);
            $variation->setCity($this);
        }
        return $this;
    }

    public function removeVariation(CityVariation $variation): static
    {
        if ($this->variations->removeElement($variation)) {
            if ($variation->getCity() === $this) {
                $variation->setCity(null);
            }
        }
        return $this;
    }
}
