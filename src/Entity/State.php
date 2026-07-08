<?php

namespace App\Entity;

use App\Repository\StateRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StateRepository::class)]
#[ORM\Table(name: 'estados')]
#[ORM\HasLifecycleCallbacks]
class State
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Country::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Country $country = null;

    #[ORM\ManyToOne(targetEntity: Region::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Region $region = null;

    #[ORM\Column(length: 255)]
    private string $officialName = '';

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $sigla = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $officialCode = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $status = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, StateVariation> */
    #[ORM\OneToMany(targetEntity: StateVariation::class, mappedBy: 'state', cascade: ['persist', 'remove'], orphanRemoval: true)]
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

    public function getRegion(): ?Region { return $this->region; }
    public function setRegion(?Region $region): static { $this->region = $region; return $this; }

    public function getOfficialName(): string { return $this->officialName; }
    public function setOfficialName(string $v): static { $this->officialName = $v; return $this; }

    public function getSigla(): ?string { return $this->sigla; }
    public function setSigla(?string $v): static { $this->sigla = $v; return $this; }

    public function getOfficialCode(): ?string { return $this->officialCode; }
    public function setOfficialCode(?string $v): static { $this->officialCode = $v; return $this; }

    public function isStatus(): bool { return $this->status; }
    public function setStatus(bool $v): static { $this->status = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** @return Collection<int, StateVariation> */
    public function getVariations(): Collection { return $this->variations; }

    public function addVariation(StateVariation $variation): static
    {
        if (!$this->variations->contains($variation)) {
            $this->variations->add($variation);
            $variation->setState($this);
        }
        return $this;
    }

    public function removeVariation(StateVariation $variation): static
    {
        if ($this->variations->removeElement($variation)) {
            if ($variation->getState() === $this) {
                $variation->setState(null);
            }
        }
        return $this;
    }
}
