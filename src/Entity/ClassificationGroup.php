<?php

namespace App\Entity;

use App\Repository\ClassificationGroupRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ClassificationGroupRepository::class)]
#[ORM\Table(name: 'classification_group')]
class ClassificationGroup
{
    public const TYPE_NORMAL       = 'normal';
    public const TYPE_NOISE        = 'noise';
    public const TYPE_VALIDATOR    = 'validator';
    public const TYPE_UNCLASSIFIED = 'unclassified';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: BibliometricProject::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?BibliometricProject $project = null;

    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 30, options: ['default' => 'normal'])]
    private string $type = self::TYPE_NORMAL;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $color = '#4f8ef7';

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $icon = 'bi-collection';

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, ClassificationRule> */
    #[ORM\OneToMany(targetEntity: ClassificationRule::class, mappedBy: 'group', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $rules;

    /** @var Collection<int, DocumentClassification> */
    #[ORM\OneToMany(targetEntity: DocumentClassification::class, mappedBy: 'group', cascade: ['remove'])]
    private Collection $classifications;

    public function __construct()
    {
        $this->rules           = new ArrayCollection();
        $this->classifications = new ArrayCollection();
        $this->createdAt       = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getProject(): ?BibliometricProject { return $this->project; }
    public function setProject(?BibliometricProject $p): static { $this->project = $p; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $v): static { $this->name = $v; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $v): static { $this->description = $v; return $this; }

    public function getType(): string { return $this->type; }
    public function setType(string $v): static { $this->type = $v; return $this; }

    public function getColor(): ?string { return $this->color ?: '#4f8ef7'; }
    public function setColor(?string $v): static { $this->color = $v; return $this; }

    public function getIcon(): ?string { return $this->icon ?: 'bi-collection'; }
    public function setIcon(?string $v): static { $this->icon = $v ?: 'bi-collection'; return $this; }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $v): static { $this->position = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** @return Collection<int, ClassificationRule> */
    public function getRules(): Collection { return $this->rules; }

    public function addRule(ClassificationRule $rule): static
    {
        if (!$this->rules->contains($rule)) {
            $this->rules->add($rule);
            $rule->setGroup($this);
        }
        return $this;
    }

    /** @return Collection<int, DocumentClassification> */
    public function getClassifications(): Collection { return $this->classifications; }

    public function getTypeLabel(): string
    {
        return match($this->type) {
            self::TYPE_NORMAL       => 'Grupo Normal',
            self::TYPE_NOISE        => 'Ruído / Falso Positivo',
            self::TYPE_VALIDATOR    => 'Validador',
            self::TYPE_UNCLASSIFIED => 'Sem Classificação',
            default                 => $this->type,
        };
    }

    public function getTypeColor(): string
    {
        return match($this->type) {
            self::TYPE_NORMAL       => 'primary',
            self::TYPE_NOISE        => 'danger',
            self::TYPE_VALIDATOR    => 'warning',
            self::TYPE_UNCLASSIFIED => 'secondary',
            default                 => 'secondary',
        };
    }

    /** @return string[] */
    public function getTerms(): array
    {
        return $this->rules->map(fn(ClassificationRule $r) => $r->getTerm())->toArray();
    }
}
