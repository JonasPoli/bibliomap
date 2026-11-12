<?php

namespace App\Entity;

use App\Repository\DocumentAuthorRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DocumentAuthorRepository::class)]
#[ORM\Index(fields: ['document', 'authorIdentity'])]
#[ORM\Index(name: 'idx_da_doc_effective', columns: ['document_id', 'effective_author_id'])]
#[ORM\Index(name: 'idx_da_effective', columns: ['effective_author_id'])]
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

    /**
     * Identidade efetiva do autor (derivada, reconstruível por app:authority:rebuild). 0 = ainda não calculada.
     * Hoje é igual a author_identity_id; com mapeamentos em authority_mapping passa a ser o alvo do mapeamento.
     */
    #[ORM\Column(name: 'effective_author_id', options: ['default' => 0])]
    private int $effectiveAuthorId = 0;

    public function getId(): ?int { return $this->id; }
    public function getDocument(): ?Document { return $this->document; }
    public function setDocument(?Document $d): static { $this->document = $d; return $this; }
    public function getAuthorIdentity(): ?AuthorIdentity { return $this->authorIdentity; }
    public function setAuthorIdentity(?AuthorIdentity $a): static { $this->authorIdentity = $a; return $this; }
    public function getPosition(): int { return $this->position; }
    public function setPosition(int $v): static { $this->position = $v; return $this; }
    public function getOriginalName(): ?string { return $this->originalName; }
    public function setOriginalName(?string $v): static { $this->originalName = $v; return $this; }
    public function getEffectiveAuthorId(): int { return $this->effectiveAuthorId; }
    public function setEffectiveAuthorId(int $v): static { $this->effectiveAuthorId = $v; return $this; }
}
