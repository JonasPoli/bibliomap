<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'instituicao_unidades')]
class InstitutionUnit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $originalVariationName = '';

    #[ORM\Column(length: 255)]
    private string $canonicalName = '';

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $type = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $confidence = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $observation = null;

    #[ORM\ManyToOne(targetEntity: Institution::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Institution $parentInstitution = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOriginalVariationName(): string
    {
        return $this->originalVariationName;
    }

    public function setOriginalVariationName(string $originalVariationName): self
    {
        $this->originalVariationName = $originalVariationName;
        return $this;
    }

    public function getCanonicalName(): string
    {
        return $this->canonicalName;
    }

    public function setCanonicalName(string $canonicalName): self
    {
        $this->canonicalName = $canonicalName;
        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function getConfidence(): ?string
    {
        return $this->confidence;
    }

    public function setConfidence(?string $confidence): self
    {
        $this->confidence = $confidence;
        return $this;
    }

    public function getObservation(): ?string
    {
        return $this->observation;
    }

    public function setObservation(?string $observation): self
    {
        $this->observation = $observation;
        return $this;
    }

    public function getParentInstitution(): ?Institution
    {
        return $this->parentInstitution;
    }

    public function setParentInstitution(?Institution $parentInstitution): self
    {
        $this->parentInstitution = $parentInstitution;
        return $this;
    }
}
