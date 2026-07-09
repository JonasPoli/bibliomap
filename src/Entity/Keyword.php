<?php

namespace App\Entity;

use App\Repository\KeywordRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: KeywordRepository::class)]
#[ORM\Table(name: 'keyword')]
class Keyword
{
    public const TYPE_AUTHOR = 'author_keyword';
    public const TYPE_INDEXED = 'indexed_keyword';
    public const TYPE_MESH = 'mesh';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'keyword_original', length: 255)]
    private string $keywordOriginal = '';

    #[ORM\Column(name: 'keyword_display', length: 255, nullable: true)]
    private ?string $keywordDisplay = null;

    #[ORM\Column(name: 'keyword_normalized', length: 255)]
    private string $keywordNormalized = '';

    #[ORM\Column(name: 'keyword_type', length: 50, options: ['default' => self::TYPE_AUTHOR])]
    private string $keywordType = self::TYPE_AUTHOR;

    #[ORM\Column(options: ['default' => true])]
    private bool $status = true;

    #[ORM\Column(name: 'review_reasons', length: 255, nullable: true)]
    private ?string $reviewReasons = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'keyword_concept_id', referencedColumnName: 'id', onDelete: 'SET NULL')]
    private ?self $keywordConcept = null;

    /** @var Collection<int, KeywordVariation> */
    #[ORM\OneToMany(targetEntity: KeywordVariation::class, mappedBy: 'keyword', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $variations;

    public function __construct()
    {
        $this->variations = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getKeywordOriginal(): string { return $this->keywordOriginal; }
    public function setKeywordOriginal(string $v): static { $this->keywordOriginal = $v; return $this; }

    public function getKeywordDisplay(): ?string { return $this->keywordDisplay; }
    public function setKeywordDisplay(?string $v): static { $this->keywordDisplay = $v; return $this; }

    public function getKeywordNormalized(): string { return $this->keywordNormalized; }
    public function setKeywordNormalized(string $v): static { $this->keywordNormalized = $v; return $this; }

    public function getKeywordType(): string { return $this->keywordType; }
    public function setKeywordType(string $v): static { $this->keywordType = $v; return $this; }

    public function isStatus(): bool { return $this->status; }
    public function setStatus(bool $v): static { $this->status = $v; return $this; }

    public function getReviewReasons(): ?string { return $this->reviewReasons; }
    public function setReviewReasons(?string $v): static { $this->reviewReasons = $v; return $this; }

    public function getKeywordConcept(): ?self { return $this->keywordConcept; }
    public function setKeywordConcept(?self $v): static { $this->keywordConcept = $v; return $this; }

    /** @return Collection<int, KeywordVariation> */
    public function getVariations(): Collection { return $this->variations; }

    public function addVariation(KeywordVariation $variation): static
    {
        if (!$this->variations->contains($variation)) {
            $this->variations->add($variation);
            $variation->setKeyword($this);
        }
        return $this;
    }

    public function removeVariation(KeywordVariation $variation): static
    {
        if ($this->variations->removeElement($variation)) {
            if ($variation->getKeyword() === $this) {
                $variation->setKeyword(null);
            }
        }
        return $this;
    }

    // Compatibility wrappers for existing code
    public function getTerm(): string { return $this->keywordDisplay ?? $this->keywordOriginal; }
    public function setTerm(string $v): static { $this->keywordOriginal = $v; return $this; }
    public function getType(): string { return $this->keywordType; }
    public function setType(string $v): static { $this->keywordType = $v; return $this; }
    public function getNormalizedTerm(): string { return $this->keywordNormalized; }
    public function setNormalizedTerm(string $v): static { $this->keywordNormalized = $v; return $this; }
}
