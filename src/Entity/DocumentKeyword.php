<?php

namespace App\Entity;

use App\Repository\DocumentKeywordRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DocumentKeywordRepository::class)]
class DocumentKeyword
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Document::class, inversedBy: 'documentKeywords')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Document $document = null;

    #[ORM\ManyToOne(targetEntity: Keyword::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Keyword $keyword = null;

    #[ORM\Column(length: 255)]
    private string $originalTerm = '';

    public function getId(): ?int { return $this->id; }
    public function getDocument(): ?Document { return $this->document; }
    public function setDocument(?Document $d): static { $this->document = $d; return $this; }
    public function getKeyword(): ?Keyword { return $this->keyword; }
    public function setKeyword(?Keyword $k): static { $this->keyword = $k; return $this; }
    public function getOriginalTerm(): string { return $this->originalTerm; }
    public function setOriginalTerm(string $v): static { $this->originalTerm = $v; return $this; }
}
