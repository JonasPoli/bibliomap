<?php

namespace App\Entity;

use App\Repository\AuthorRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AuthorRepository::class)]
#[ORM\Index(fields: ['normalizedName'])]
class Author
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $normalizedName = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $surname = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $initials = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $orcid = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $v): static { $this->name = $v; return $this; }
    public function getNormalizedName(): ?string { return $this->normalizedName; }
    public function setNormalizedName(?string $v): static { $this->normalizedName = $v; return $this; }
    public function getSurname(): ?string { return $this->surname; }
    public function setSurname(?string $v): static { $this->surname = $v; return $this; }
    public function getInitials(): ?string { return $this->initials; }
    public function setInitials(?string $v): static { $this->initials = $v; return $this; }
    public function getOrcid(): ?string { return $this->orcid; }
    public function setOrcid(?string $v): static { $this->orcid = $v; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
