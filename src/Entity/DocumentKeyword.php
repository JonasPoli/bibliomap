<?php

namespace App\Entity;

use App\Repository\DocumentKeywordRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DocumentKeywordRepository::class)]
#[ORM\Index(name: 'idx_dk_document_keyword', columns: ['document_id', 'keyword_id'])]
#[ORM\Index(name: 'idx_dk_doc_effective', columns: ['document_id', 'keyword_id', 'effective_keyword_id'])]
#[ORM\Index(name: 'idx_dk_effective', columns: ['effective_keyword_id'])]
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

    /**
     * Identidade efetiva da palavra-chave (derivada, reconstruível por app:authority:rebuild). 0 = ainda não
     * calculada. Positivo = keyword.id efetivo; negativo = -thesaurus_concept.id.
     */
    #[ORM\Column(name: 'effective_keyword_id', options: ['default' => 0])]
    private int $effectiveKeywordId = 0;

    public function getId(): ?int { return $this->id; }
    public function getDocument(): ?Document { return $this->document; }
    public function setDocument(?Document $d): static { $this->document = $d; return $this; }
    public function getKeyword(): ?Keyword { return $this->keyword; }
    public function setKeyword(?Keyword $k): static { $this->keyword = $k; return $this; }
    public function getOriginalTerm(): string { return $this->originalTerm; }
    public function setOriginalTerm(string $v): static { $this->originalTerm = $v; return $this; }
    public function getEffectiveKeywordId(): int { return $this->effectiveKeywordId; }
    public function setEffectiveKeywordId(int $v): static { $this->effectiveKeywordId = $v; return $this; }
}
