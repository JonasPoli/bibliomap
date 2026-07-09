<?php

namespace App\Entity;

use App\Repository\AuthorIdentityRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AuthorIdentityRepository::class)]
#[ORM\Table(name: 'author_identity')]
class AuthorIdentity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'preferred_name', length: 255)]
    private string $preferredName = '';

    #[ORM\Column(name: 'normalized_name', length: 255)]
    private string $normalizedName = '';

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, AuthorNameVariant> */
    #[ORM\OneToMany(targetEntity: AuthorNameVariant::class, mappedBy: 'authorIdentity', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $variations;

    /** @var Collection<int, AuthorExternalIdentifier> */
    #[ORM\OneToMany(targetEntity: AuthorExternalIdentifier::class, mappedBy: 'authorIdentity', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $identifiers;

    #[ORM\Column(options: ['default' => false])]
    private bool $status = false;

    #[ORM\Column(name: 'review_reasons', length: 255, nullable: true)]
    private ?string $reviewReasons = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->variations = new ArrayCollection();
        $this->identifiers = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getPreferredName(): string { return $this->preferredName; }
    public function setPreferredName(string $v): static { $this->preferredName = $v; return $this; }

    public function getNormalizedName(): string { return $this->normalizedName; }
    public function setNormalizedName(string $v): static { $this->normalizedName = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $v): static { $this->updatedAt = $v; return $this; }

    public function isStatus(): bool { return $this->status; }
    public function setStatus(bool $v): static { $this->status = $v; return $this; }

    public function getReviewReasons(): ?string { return $this->reviewReasons; }
    public function setReviewReasons(?string $v): static { $this->reviewReasons = $v; return $this; }

    // Compatibility wrappers for existing code
    public function getName(): string { return $this->preferredName; }
    public function setName(string $v): static { $this->preferredName = $v; return $this; }
    public function getSurname(): ?string { return null; }
    public function getInitials(): ?string { return null; }
    public function getOrcid(): ?string
    {
        foreach ($this->identifiers as $ident) {
            if ($ident->getProvider() === 'orcid') {
                return $ident->getIdentifier();
            }
        }
        return null;
    }

    /** @return Collection<int, AuthorNameVariant> */
    public function getVariations(): Collection { return $this->variations; }

    public function addVariation(AuthorNameVariant $variation): static
    {
        if (!$this->variations->contains($variation)) {
            $this->variations->add($variation);
            $variation->setAuthorIdentity($this);
        }
        return $this;
    }

    public function removeVariation(AuthorNameVariant $variation): static
    {
        if ($this->variations->removeElement($variation)) {
            if ($variation->getAuthorIdentity() === $this) {
                $variation->setAuthorIdentity(null);
            }
        }
        return $this;
    }

    /** @return Collection<int, AuthorExternalIdentifier> */
    public function getIdentifiers(): Collection { return $this->identifiers; }

    public function addIdentifier(AuthorExternalIdentifier $identifier): static
    {
        if (!$this->identifiers->contains($identifier)) {
            $this->identifiers->add($identifier);
            $identifier->setAuthorIdentity($this);
        }
        return $this;
    }

    public function removeIdentifier(AuthorExternalIdentifier $identifier): static
    {
        if ($this->identifiers->removeElement($identifier)) {
            if ($identifier->getAuthorIdentity() === $this) {
                $identifier->setAuthorIdentity(null);
            }
        }
        return $this;
    }
}
