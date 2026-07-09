<?php

namespace App\Entity;

use App\Repository\DocumentAuthorRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DocumentAuthorRepository::class)]
#[ORM\Index(fields: ['document', 'authorIdentity'])]
class DocumentAuthor
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Document::class, inversedBy: 'documentAuthors')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Document $document = null;

    #[ORM\ManyToOne(targetEntity: AuthorIdentity::class)]
    #[ORM\JoinColumn(name: 'author_identity_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?AuthorIdentity $authorIdentity = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $originalName = null;

    public function getId(): ?int { return $this->id; }
    public function getDocument(): ?Document { return $this->document; }
    public function setDocument(?Document $d): static { $this->document = $d; return $this; }
    public function getAuthorIdentity(): ?AuthorIdentity { return $this->authorIdentity; }
    public function setAuthorIdentity(?AuthorIdentity $a): static { $this->authorIdentity = $a; return $this; }
    public function getPosition(): int { return $this->position; }
    public function setPosition(int $v): static { $this->position = $v; return $this; }
    public function getOriginalName(): ?string { return $this->originalName; }
    public function setOriginalName(?string $v): static { $this->originalName = $v; return $this; }
}
