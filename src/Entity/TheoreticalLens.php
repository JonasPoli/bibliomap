<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'theoretical_lens')]
class TheoreticalLens
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255)]
    private ?string $category = null;

    #[ORM\Column(length: 255, name: 'research_field')]
    private ?string $researchField = 'CTS';

    #[ORM\Column(type: Types::JSON)]
    private array $terms = [];

    #[ORM\Column(type: Types::TEXT)]
    private ?string $description = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $icon = 'bi-mortarboard';

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $color = '#4f8ef7';

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private array $citationFormats = [];

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(string $category): static
    {
        $category = trim($category);
        $this->category = $category !== '' ? $category : 'Geral';
        return $this;
    }

    public function getResearchField(): ?string
    {
        return $this->researchField;
    }

    public function setResearchField(string $researchField): static
    {
        $researchField = trim($researchField);
        $this->researchField = $researchField !== '' ? $researchField : 'CTS';
        return $this;
    }

    public function getTerms(): array
    {
        return $this->terms;
    }

    public function setTerms(array $terms): static
    {
        $this->terms = array_values(array_unique(array_filter(array_map('trim', $terms))));
        return $this;
    }

    public function getCitationFormats(): array
    {
        return $this->citationFormats ?: [];
    }

    public function setCitationFormats(array $citationFormats): static
    {
        $this->citationFormats = array_values(array_unique(array_filter(array_map('trim', $citationFormats))));
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getIcon(): ?string
    {
        return $this->icon ?: 'bi-mortarboard';
    }

    public function setIcon(?string $icon): static
    {
        $this->icon = $icon ?: 'bi-mortarboard';
        return $this;
    }

    public function getColor(): ?string
    {
        return $this->color ?: '#4f8ef7';
    }

    public function setColor(?string $color): static
    {
        $this->color = $color ?: '#4f8ef7';
        return $this;
    }
}
