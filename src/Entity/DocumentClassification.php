<?php

namespace App\Entity;

use App\Repository\DocumentClassificationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DocumentClassificationRepository::class)]
#[ORM\Table(name: 'document_classification')]
#[ORM\Index(fields: ['project'])]
#[ORM\Index(fields: ['document'])]
#[ORM\UniqueConstraint(name: 'uniq_doc_classification', columns: ['document_id', 'project_id'])]
class DocumentClassification
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Document $document = null;

    /** Null means "unclassified" (no group matched and no validator failed) */
    #[ORM\ManyToOne(targetEntity: ClassificationGroup::class, inversedBy: 'classifications')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ClassificationGroup $group = null;

    #[ORM\ManyToOne(targetEntity: BibliometricProject::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?BibliometricProject $project = null;

    /** The term that triggered this classification */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $matchedTerm = null;

    #[ORM\Column]
    private \DateTimeImmutable $runAt;

    /** Flag set when user manually overrides the automatic classification */
    #[ORM\Column(options: ['default' => false])]
    private bool $manualOverride = false;

    public function __construct()
    {
        $this->runAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getDocument(): ?Document { return $this->document; }
    public function setDocument(?Document $d): static { $this->document = $d; return $this; }

    public function getGroup(): ?ClassificationGroup { return $this->group; }
    public function setGroup(?ClassificationGroup $g): static { $this->group = $g; return $this; }

    public function getProject(): ?BibliometricProject { return $this->project; }
    public function setProject(?BibliometricProject $p): static { $this->project = $p; return $this; }

    public function getMatchedTerm(): ?string { return $this->matchedTerm; }
    public function setMatchedTerm(?string $v): static { $this->matchedTerm = $v; return $this; }

    public function getRunAt(): \DateTimeImmutable { return $this->runAt; }
    public function setRunAt(\DateTimeImmutable $v): static { $this->runAt = $v; return $this; }

    public function isManualOverride(): bool { return $this->manualOverride; }
    public function setManualOverride(bool $v): static { $this->manualOverride = $v; return $this; }
}
