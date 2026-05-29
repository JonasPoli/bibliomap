<?php

namespace App\Entity;

use App\Repository\KeywordRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: KeywordRepository::class)]
#[ORM\Index(fields: ['normalizedTerm'])]
#[ORM\UniqueConstraint(fields: ['normalizedTerm', 'type'])]
class Keyword
{
    public const TYPE_AUTHOR = 'author';
    public const TYPE_INDEXED = 'indexed';
    public const TYPE_MESH = 'mesh';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $term = '';

    #[ORM\Column(length: 255)]
    private string $normalizedTerm = '';

    #[ORM\Column(length: 20, options: ['default' => 'author'])]
    private string $type = self::TYPE_AUTHOR;

    public function getId(): ?int { return $this->id; }
    public function getTerm(): string { return $this->term; }
    public function setTerm(string $v): static { $this->term = $v; return $this; }
    public function getNormalizedTerm(): string { return $this->normalizedTerm; }
    public function setNormalizedTerm(string $v): static { $this->normalizedTerm = $v; return $this; }
    public function getType(): string { return $this->type; }
    public function setType(string $v): static { $this->type = $v; return $this; }
}
