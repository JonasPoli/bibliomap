<?php

namespace App\Entity;

use App\Repository\ClassificationRuleRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ClassificationRuleRepository::class)]
#[ORM\Table(name: 'classification_rule')]
class ClassificationRule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ClassificationGroup::class, inversedBy: 'rules')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ClassificationGroup $group = null;

    #[ORM\Column(length: 500)]
    private string $term = '';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getGroup(): ?ClassificationGroup { return $this->group; }
    public function setGroup(?ClassificationGroup $g): static { $this->group = $g; return $this; }

    public function getTerm(): string { return $this->term; }
    public function setTerm(string $v): static { $this->term = strtolower(trim($v)); return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
