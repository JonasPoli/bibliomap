<?php

namespace App\Entity;

use App\Config\DataSources;
use App\Repository\DatasetRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DatasetRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Dataset
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_IMPORTING = 'importing';
    public const STATUS_IMPORTED = 'imported';
    public const STATUS_DELETED = 'deleted';
    public const STATUS_ERROR = 'error';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: BibliometricProject::class, inversedBy: 'datasets')]
    #[ORM\JoinColumn(nullable: false)]
    private ?BibliometricProject $project = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $source = null;  // scopus, wos, lens, pubmed, generic...

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $searchPeriodStart = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $searchPeriodEnd = null;

    #[ORM\Column(length: 255)]
    private ?string $originalFilename = null;

    #[ORM\Column(length: 500)]
    private ?string $filePath = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $fileFormat = null;  // csv, txt, ris, bib, xlsx, xml, json

    #[ORM\Column(options: ['default' => 0])]
    private int $recordsCount = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $importedCount = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $duplicatedCount = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $errorCount = 0;

    #[ORM\Column(length: 30, options: ['default' => 'pending'])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $importedAt = null;

    /** Registros lidos do arquivo pela importação (toda saída do leitor, inclusive os ilegíveis). Nulo em dataset antigo. */
    #[ORM\Column(nullable: true)]
    private ?int $readCount = null;

    /** Total de resultados que a base mostrou na busca, informado pelo dono na prévia. */
    #[ORM\Column(nullable: true)]
    private ?int $expectedCount = null;

    /** Aviso da importação sobre o arquivo (ex.: base ajustada pela detecção). */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $importNotice = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getProject(): ?BibliometricProject { return $this->project; }
    public function setProject(?BibliometricProject $project): static { $this->project = $project; return $this; }

    public function getName(): ?string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $v): static { $this->description = $v; return $this; }

    public function getSource(): ?string { return $this->source; }
    public function setSource(?string $source): static { $this->source = $source; return $this; }

    public function getSearchPeriodStart(): ?\DateTimeImmutable { return $this->searchPeriodStart; }
    public function setSearchPeriodStart(?\DateTimeImmutable $v): static { $this->searchPeriodStart = $v; return $this; }

    public function getSearchPeriodEnd(): ?\DateTimeImmutable { return $this->searchPeriodEnd; }
    public function setSearchPeriodEnd(?\DateTimeImmutable $v): static { $this->searchPeriodEnd = $v; return $this; }

    public function getOriginalFilename(): ?string { return $this->originalFilename; }
    public function setOriginalFilename(string $v): static { $this->originalFilename = $v; return $this; }

    public function getFilePath(): ?string { return $this->filePath; }
    public function setFilePath(string $v): static { $this->filePath = $v; return $this; }

    public function getFileFormat(): ?string { return $this->fileFormat; }
    public function setFileFormat(?string $v): static { $this->fileFormat = $v; return $this; }

    public function getRecordsCount(): int { return $this->recordsCount; }
    public function setRecordsCount(int $v): static { $this->recordsCount = $v; return $this; }

    public function getImportedCount(): int { return $this->importedCount; }
    public function setImportedCount(int $v): static { $this->importedCount = $v; return $this; }

    public function getDuplicatedCount(): int { return $this->duplicatedCount; }
    public function setDuplicatedCount(int $v): static { $this->duplicatedCount = $v; return $this; }

    public function getErrorCount(): int { return $this->errorCount; }
    public function setErrorCount(int $v): static { $this->errorCount = $v; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function getErrorMessage(): ?string { return $this->errorMessage; }
    public function setErrorMessage(?string $v): static { $this->errorMessage = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getImportedAt(): ?\DateTimeImmutable { return $this->importedAt; }
    public function setImportedAt(?\DateTimeImmutable $v): static { $this->importedAt = $v; return $this; }

    public function getReadCount(): ?int { return $this->readCount; }
    public function setReadCount(?int $v): static { $this->readCount = $v; return $this; }
    public function getExpectedCount(): ?int { return $this->expectedCount; }
    public function setExpectedCount(?int $v): static { $this->expectedCount = $v; return $this; }
    public function getImportNotice(): ?string { return $this->importNotice; }
    public function setImportNotice(?string $v): static { $this->importNotice = $v === null ? null : mb_substr($v, 0, 500); return $this; }

    /**
     * Conferência da importação: lidos = importados + duplicados + erros, e a comparação com o total contado na
     * prévia e com o total que a base informou. Dataset antigo (sem readCount) usa a soma dos três como lidos.
     *
     * @return array{read: int, imported: int, duplicated: int, errors: int, sum: int, closes: bool, previewDiff: ?int, expectedDiff: ?int, expected: ?int, preview: ?int}
     */
    public function getReconciliation(): array
    {
        $sum = $this->importedCount + $this->duplicatedCount + $this->errorCount;
        $read = $this->readCount ?? $sum;
        $preview = $this->recordsCount > 0 ? $this->recordsCount : null;

        return [
            'read' => $read,
            'imported' => $this->importedCount,
            'duplicated' => $this->duplicatedCount,
            'errors' => $this->errorCount,
            'sum' => $sum,
            'closes' => $read === $sum,
            'preview' => $preview,
            'previewDiff' => $preview !== null && $this->readCount !== null ? $preview - $this->readCount : null,
            'expected' => $this->expectedCount,
            'expectedDiff' => $this->expectedCount !== null ? $this->expectedCount - $read : null,
        ];
    }

    public function getSourceLabel(): string
    {
        return DataSources::label($this->source ?? 'generic');
    }

    public function getSourceIcon(): string
    {
        return DataSources::emoji($this->source ?? 'generic');
    }

    public function getStatusLabel(): string
    {
        return match($this->status) {
            self::STATUS_PENDING => 'Pendente',
            self::STATUS_IMPORTING => 'Importando',
            self::STATUS_IMPORTED => 'Importado',
            self::STATUS_ERROR => 'Erro',
            self::STATUS_DELETED => 'Removido',
            default => $this->status,
        };
    }

    public function getStatusColor(): string
    {
        return match($this->status) {
            self::STATUS_IMPORTED => 'success',
            self::STATUS_IMPORTING => 'warning',
            self::STATUS_ERROR => 'danger',
            self::STATUS_DELETED => 'secondary',
            default => 'secondary',
        };
    }

    public function getSuccessRate(): float
    {
        if ($this->recordsCount === 0) return 0.0;
        return round(($this->importedCount / $this->recordsCount) * 100, 1);
    }

    public function getFileSizeFormatted(): string
    {
        if (!$this->filePath || !file_exists($this->filePath)) return 'N/A';
        $bytes = filesize($this->filePath);
        if ($bytes > 1024*1024*1024) return round($bytes/1024/1024/1024, 1).' GB';
        if ($bytes > 1024*1024) return round($bytes/1024/1024, 1).' MB';
        return round($bytes/1024, 0).' KB';
    }
}
