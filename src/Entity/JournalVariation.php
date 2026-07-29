<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use DateTimeImmutable;

#[ORM\Entity]
#[ORM\Table(name: 'qualis_journal_variacoes_nome')]
#[ORM\Index(fields: ['normalizedName'])]
class JournalVariation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: QualisJournal::class, inversedBy: 'variations')]
    #[ORM\JoinColumn(name: 'journal_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?QualisJournal $journal = null;

    #[ORM\Column(name: 'variation_name', length: 500)]
    private string $variationName = '';

    #[ORM\Column(name: 'normalized_name', length: 500)]
    private string $normalizedName = '';

    #[ORM\Column(name: 'variation_type', length: 50, options: ['default' => 'alternative'])]
    private string $variationType = 'alternative'; // official, alternative

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $status = true;

    #[ORM\Column(name: 'created_at')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getJournal(): ?QualisJournal
    {
        return $this->journal;
    }

    public function setJournal(?QualisJournal $journal): self
    {
        $this->journal = $journal;
        return $this;
    }

    public function getVariationName(): string
    {
        return $this->variationName;
    }

    public function setVariationName(string $variationName): self
    {
        $this->variationName = $variationName;
        return $this;
    }

    public function getNormalizedName(): string
    {
        return $this->normalizedName;
    }

    public function setNormalizedName(string $normalizedName): self
    {
        $this->normalizedName = $normalizedName;
        return $this;
    }

    public function getVariationType(): string
    {
        return $this->variationType;
    }

    public function setVariationType(string $variationType): self
    {
        $this->variationType = $variationType;
        return $this;
    }

    public function isStatus(): bool
    {
        return $this->status;
    }

    public function setStatus(bool $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }
}
