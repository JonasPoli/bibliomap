<?php

namespace App\Entity;

use App\Repository\BibliometricProjectRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: BibliometricProjectRepository::class)]
#[ORM\HasLifecycleCallbacks]
class BibliometricProject
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_AWAITING_IMPORT = 'awaiting_import';
    public const STATUS_IMPORTING = 'importing';
    public const STATUS_IMPORTED = 'imported';
    public const STATUS_NORMALIZING = 'normalizing';
    public const STATUS_READY = 'ready';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DONE = 'done';
    public const STATUS_ERROR = 'error';
    public const STATUS_ARCHIVED = 'archived';

    public const VISIBILITY_PRIVATE = 'private';
    public const VISIBILITY_PUBLIC = 'public';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'projects')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'O título é obrigatório.')]
    private ?string $title = null;

    #[ORM\Column(length: 255, unique: true)]
    private ?string $slug = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $researchQuestion = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $objective = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $searchString = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $databaseSources = null;

    #[ORM\Column(nullable: true)]
    private ?int $startYear = null;

    #[ORM\Column(nullable: true)]
    private ?int $endYear = null;

    #[ORM\Column(length: 50, options: ['default' => 'draft'])]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column(length: 20, options: ['default' => 'private'])]
    private string $visibility = self::VISIBILITY_PRIVATE;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, Dataset> */
    #[ORM\OneToMany(targetEntity: Dataset::class, mappedBy: 'project', orphanRemoval: true)]
    private Collection $datasets;

    public function __construct()
    {
        $this->datasets = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }

    public function getTitle(): ?string { return $this->title; }
    public function setTitle(string $title): static { $this->title = $title; return $this; }

    public function getSlug(): ?string { return $this->slug; }
    public function setSlug(string $slug): static { $this->slug = $slug; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }

    public function getResearchQuestion(): ?string { return $this->researchQuestion; }
    public function setResearchQuestion(?string $v): static { $this->researchQuestion = $v; return $this; }

    public function getObjective(): ?string { return $this->objective; }
    public function setObjective(?string $v): static { $this->objective = $v; return $this; }

    public function getSearchString(): ?string { return $this->searchString; }
    public function setSearchString(?string $v): static { $this->searchString = $v; return $this; }

    public function getDatabaseSources(): ?array { return $this->databaseSources; }
    public function setDatabaseSources(?array $v): static { $this->databaseSources = $v; return $this; }

    public function getStartYear(): ?int { return $this->startYear; }
    public function setStartYear(?int $v): static { $this->startYear = $v; return $this; }

    public function getEndYear(): ?int { return $this->endYear; }
    public function setEndYear(?int $v): static { $this->endYear = $v; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function getVisibility(): string { return $this->visibility; }
    public function setVisibility(string $visibility): static { $this->visibility = $visibility; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** @return Collection<int, Dataset> */
    public function getDatasets(): Collection { return $this->datasets; }

    public function addDataset(Dataset $dataset): static
    {
        if (!$this->datasets->contains($dataset)) {
            $this->datasets->add($dataset);
            $dataset->setProject($this);
        }
        return $this;
    }

    public function removeDataset(Dataset $dataset): static
    {
        if ($this->datasets->removeElement($dataset)) {
            if ($dataset->getProject() === $this) {
                $dataset->setProject(null);
            }
        }
        return $this;
    }

    public function getStatusLabel(): string
    {
        return match($this->status) {
            self::STATUS_DRAFT => 'Rascunho',
            self::STATUS_AWAITING_IMPORT => 'Aguardando importação',
            self::STATUS_IMPORTING => 'Importando',
            self::STATUS_IMPORTED => 'Importado',
            self::STATUS_NORMALIZING => 'Normalizando',
            self::STATUS_READY => 'Pronto para análise',
            self::STATUS_PROCESSING => 'Processando',
            self::STATUS_DONE => 'Concluído',
            self::STATUS_ERROR => 'Erro',
            self::STATUS_ARCHIVED => 'Arquivado',
            default => $this->status,
        };
    }

    public function getStatusColor(): string
    {
        return match($this->status) {
            self::STATUS_DONE => 'success',
            self::STATUS_ERROR => 'danger',
            self::STATUS_IMPORTING, self::STATUS_NORMALIZING, self::STATUS_PROCESSING => 'warning',
            self::STATUS_READY => 'info',
            self::STATUS_ARCHIVED => 'secondary',
            default => 'primary',
        };
    }

    public function isPublic(): bool { return $this->visibility === self::VISIBILITY_PUBLIC; }
}
