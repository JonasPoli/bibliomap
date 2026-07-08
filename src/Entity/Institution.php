<?php

namespace App\Entity;

use App\Repository\InstitutionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InstitutionRepository::class)]
#[ORM\Table(name: 'instituicoes_ensino')]
#[ORM\HasLifecycleCallbacks]
class Institution
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $officialName = '';

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $shortName = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $sigla = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $institutionType = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $natureza = null;

    #[ORM\ManyToOne(targetEntity: Country::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Country $country = null;

    #[ORM\ManyToOne(targetEntity: State::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?State $state = null;

    #[ORM\ManyToOne(targetEntity: City::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?City $city = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $officialWebsite = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $institutionalEmail = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $status = true;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $updatedBy = null;

    /** @var Collection<int, InstitutionVariation> */
    #[ORM\OneToMany(targetEntity: InstitutionVariation::class, mappedBy: 'institution', cascade: ['persist', 'remove'], orphanRemoval: true)]
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

    public function getShortName(): ?string { return $this->shortName; }
    public function setShortName(?string $v): static { $this->shortName = $v; return $this; }

    public function getSigla(): ?string { return $this->sigla; }
    public function setSigla(?string $v): static { $this->sigla = $v; return $this; }

    public function getInstitutionType(): ?string { return $this->institutionType; }
    public function setInstitutionType(?string $v): static { $this->institutionType = $v; return $this; }

    public function getNatureza(): ?string { return $this->natureza; }
    public function setNatureza(?string $v): static { $this->natureza = $v; return $this; }

    public function getCountry(): ?Country { return $this->country; }
    public function setCountry(?Country $country): static { $this->country = $country; return $this; }

    public function getState(): ?State { return $this->state; }
    public function setState(?State $state): static { $this->state = $state; return $this; }

    public function getCity(): ?City { return $this->city; }
    public function setCity(?City $city): static { $this->city = $city; return $this; }

    public function getOfficialWebsite(): ?string { return $this->officialWebsite; }
    public function setOfficialWebsite(?string $v): static { $this->officialWebsite = $v; return $this; }

    public function getInstitutionalEmail(): ?string { return $this->institutionalEmail; }
    public function setInstitutionalEmail(?string $v): static { $this->institutionalEmail = $v; return $this; }

    public function isStatus(): bool { return $this->status; }
    public function setStatus(bool $v): static { $this->status = $v; return $this; }

    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $v): static { $this->notes = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function setCreatedBy(?User $user): static { $this->createdBy = $user; return $this; }

    public function getUpdatedBy(): ?User { return $this->updatedBy; }
    public function setUpdatedBy(?User $user): static { $this->updatedBy = $user; return $this; }

    /** @return Collection<int, InstitutionVariation> */
    public function getVariations(): Collection { return $this->variations; }

    public function addVariation(InstitutionVariation $variation): static
    {
        if (!$this->variations->contains($variation)) {
            $this->variations->add($variation);
            $variation->setInstitution($this);
        }
        return $this;
    }

    public function removeVariation(InstitutionVariation $variation): static
    {
        if ($this->variations->removeElement($variation)) {
            if ($variation->getInstitution() === $this) {
                $variation->setInstitution(null);
            }
        }
        return $this;
    }
}
