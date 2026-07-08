<?php

namespace App\Entity;

use App\Repository\DocumentInstitutionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DocumentInstitutionRepository::class)]
#[ORM\Table(name: 'documento_instituicoes')]
class DocumentInstitution
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Document::class, inversedBy: 'documentInstitutions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Document $document = null;

    #[ORM\ManyToOne(targetEntity: Institution::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Institution $institution = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $linkType = null;

    public function getId(): ?int { return $this->id; }

    public function getDocument(): ?Document { return $this->document; }
    public function setDocument(?Document $document): static { $this->document = $document; return $this; }

    public function getInstitution(): ?Institution { return $this->institution; }
    public function setInstitution(?Institution $institution): static { $this->institution = $institution; return $this; }

    public function getLinkType(): ?string { return $this->linkType; }
    public function setLinkType(?string $v): static { $this->linkType = $v; return $this; }
}
