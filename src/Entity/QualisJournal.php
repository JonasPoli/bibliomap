<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'qualis_journal')]
#[ORM\Index(fields: ['normalizedIssn'])]
class QualisJournal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $issn = null;

    #[ORM\Column(length: 50, name: 'normalized_issn', unique: true)]
    private ?string $normalizedIssn = null;

    #[ORM\Column(length: 500)]
    private ?string $title = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $qualis = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIssn(): ?string
    {
        return $this->issn;
    }

    public function setIssn(?string $issn): self
    {
        $this->issn = $issn;
        return $this;
    }

    public function getNormalizedIssn(): ?string
    {
        return $this->normalizedIssn;
    }

    public function setNormalizedIssn(string $normalizedIssn): self
    {
        $this->normalizedIssn = $normalizedIssn;
        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;
        return $this;
    }

    public function getQualis(): ?string
    {
        return $this->qualis;
    }

    public function setQualis(?string $qualis): self
    {
        $this->qualis = $qualis;
        return $this;
    }
}
