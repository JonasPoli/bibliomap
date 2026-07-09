<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use DateTimeImmutable;

#[ORM\Entity]
#[ORM\Table(name: 'thesaurus_label')]
#[ORM\Index(fields: ['normalizedLabel'])]
class ThesaurusLabel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ThesaurusConcept::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ThesaurusConcept $concept = null;

    #[ORM\Column(length: 255)]
    private string $label = '';

    #[ORM\Column(name: 'normalized_label', length: 255)]
    private string $normalizedLabel = '';

    #[ORM\Column(length: 10, options: ['default' => 'en'])]
    private string $language = 'en';

    #[ORM\Column(length: 30, options: ['default' => 'alternative'])]
    private string $type = 'alternative'; // preferred, alternative, hidden

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $source = null;

    #[ORM\Column(name: 'created_at')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getConcept(): ?ThesaurusConcept { return $this->concept; }
    public function setConcept(?ThesaurusConcept $concept): self { $this->concept = $concept; return $this; }

    public function getLabel(): string { return $this->label; }
    public function setLabel(string $label): self { $this->label = $label; return $this; }

    public function getNormalizedLabel(): string { return $this->normalizedLabel; }
    public function setNormalizedLabel(string $label): self { $this->normalizedLabel = $label; return $this; }

    public function getLanguage(): string { return $this->language; }
    public function setLanguage(string $lang): self { $this->language = $lang; return $this; }

    public function getType(): string { return $this->type; }
    public function setType(string $type): self { $this->type = $type; return $this; }

    public function getSource(): ?string { return $this->source; }
    public function setSource(?string $src): self { $this->source = $src; return $this; }

    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(DateTimeImmutable $dt): self { $this->createdAt = $dt; return $this; }

    public function getUpdatedAt(): DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(DateTimeImmutable $dt): self { $this->updatedAt = $dt; return $this; }
}
