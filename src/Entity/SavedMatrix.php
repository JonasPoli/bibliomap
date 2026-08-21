<?php

namespace App\Entity;

use App\Repository\SavedMatrixRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SavedMatrixRepository::class)]
#[ORM\Table(name: 'saved_matrix')]
class SavedMatrix
{
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

    #[ORM\Column(length: 100)]
    private string $rowDimension = 'keyword_author';

    #[ORM\Column(length: 100)]
    private string $columnDimension = 'country';

    #[ORM\Column(options: ['default' => 1])]
    private int $minCellWeight = 1;

    #[ORM\Column(options: ['default' => 30])]
    private int $maxRows = 30;

    #[ORM\Column(options: ['default' => 30])]
    private int $maxCols = 30;

    #[ORM\Column(options: ['default' => true])]
    private bool $useThesaurus = true;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $options = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->useThesaurus = true;
        $this->minCellWeight = 1;
        $this->maxRows = 30;
        $this->maxCols = 30;
    }

    public function getId(): ?int { return $this->id; }

    public function getProject(): ?BibliometricProject { return $this->project; }
    public function setProject(?BibliometricProject $p): static { $this->project = $p; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $v): static { $this->name = $v; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $v): static { $this->description = $v; return $this; }

    public function getRowDimension(): string { return $this->rowDimension; }
    public function setRowDimension(string $v): static { $this->rowDimension = $v; return $this; }

    public function getColumnDimension(): string { return $this->columnDimension; }
    public function setColumnDimension(string $v): static { $this->columnDimension = $v; return $this; }

    public function getMinCellWeight(): int { return $this->minCellWeight; }
    public function setMinCellWeight(int $v): static { $this->minCellWeight = $v; return $this; }

    public function getMaxRows(): int { return $this->maxRows; }
    public function setMaxRows(int $v): static { $this->maxRows = $v; return $this; }

    public function getMaxCols(): int { return $this->maxCols; }
    public function setMaxCols(int $v): static { $this->maxCols = $v; return $this; }

    public function isUseThesaurus(): bool { return $this->useThesaurus; }
    public function setUseThesaurus(bool $v): static { $this->useThesaurus = $v; return $this; }

    public function getOptions(): ?array { return $this->options; }
    public function setOptions(?array $v): static { $this->options = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
