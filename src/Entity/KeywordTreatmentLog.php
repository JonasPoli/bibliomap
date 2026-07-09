<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use DateTimeImmutable;

#[ORM\Entity]
#[ORM\Table(name: 'keyword_treatment_log')]
class KeywordTreatmentLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: KeywordTreatmentJob::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?KeywordTreatmentJob $job = null;

    #[ORM\ManyToOne(targetEntity: Keyword::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Keyword $keyword = null;

    #[ORM\Column(length: 50)]
    private string $action = '';

    #[ORM\Column(name: 'old_display', length: 255, nullable: true)]
    private ?string $oldDisplay = null;

    #[ORM\Column(name: 'new_display', length: 255, nullable: true)]
    private ?string $newDisplay = null;

    #[ORM\Column(name: 'old_normalized', length: 255, nullable: true)]
    private ?string $oldNormalized = null;

    #[ORM\Column(name: 'new_normalized', length: 255, nullable: true)]
    private ?string $newNormalized = null;

    /** @deprecated Legacy keyword-based concept references */
    #[ORM\ManyToOne(targetEntity: Keyword::class)]
    #[ORM\JoinColumn(name: 'old_concept_id', referencedColumnName: 'id', onDelete: 'SET NULL', nullable: true)]
    private ?Keyword $oldConcept = null;

    /** @deprecated Legacy keyword-based concept references */
    #[ORM\ManyToOne(targetEntity: Keyword::class)]
    #[ORM\JoinColumn(name: 'new_concept_id', referencedColumnName: 'id', onDelete: 'SET NULL', nullable: true)]
    private ?Keyword $newConcept = null;

    #[ORM\ManyToOne(targetEntity: ThesaurusConcept::class)]
    #[ORM\JoinColumn(name: 'old_thesaurus_concept_id', referencedColumnName: 'id', onDelete: 'SET NULL', nullable: true)]
    private ?ThesaurusConcept $oldThesaurusConcept = null;

    #[ORM\ManyToOne(targetEntity: ThesaurusConcept::class)]
    #[ORM\JoinColumn(name: 'new_thesaurus_concept_id', referencedColumnName: 'id', onDelete: 'SET NULL', nullable: true)]
    private ?ThesaurusConcept $newThesaurusConcept = null;

    #[ORM\Column(name: 'match_method', length: 50, nullable: true)]
    private ?string $matchMethod = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $score = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(length: 30, options: ['default' => 'pending'])]
    private string $status = 'pending'; // pending, applied, rejected

    #[ORM\Column(name: 'created_at')]
    private DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getJob(): ?KeywordTreatmentJob { return $this->job; }
    public function setJob(?KeywordTreatmentJob $job): self { $this->job = $job; return $this; }

    public function getKeyword(): ?Keyword { return $this->keyword; }
    public function setKeyword(?Keyword $keyword): self { $this->keyword = $keyword; return $this; }

    public function getAction(): string { return $this->action; }
    public function setAction(string $action): self { $this->action = $action; return $this; }

    public function getOldDisplay(): ?string { return $this->oldDisplay; }
    public function setOldDisplay(?string $val): self { $this->oldDisplay = $val; return $this; }

    public function getNewDisplay(): ?string { return $this->newDisplay; }
    public function setNewDisplay(?string $val): self { $this->newDisplay = $val; return $this; }

    public function getOldNormalized(): ?string { return $this->oldNormalized; }
    public function setOldNormalized(?string $val): self { $this->oldNormalized = $val; return $this; }

    public function getNewNormalized(): ?string { return $this->newNormalized; }
    public function setNewNormalized(?string $val): self { $this->newNormalized = $val; return $this; }

    /** @deprecated */
    public function getOldConcept(): ?Keyword { return $this->oldConcept; }
    /** @deprecated */
    public function setOldConcept(?Keyword $concept): self { $this->oldConcept = $concept; return $this; }

    /** @deprecated */
    public function getNewConcept(): ?Keyword { return $this->newConcept; }
    /** @deprecated */
    public function setNewConcept(?Keyword $concept): self { $this->newConcept = $concept; return $this; }

    public function getOldThesaurusConcept(): ?ThesaurusConcept { return $this->oldThesaurusConcept; }
    public function setOldThesaurusConcept(?ThesaurusConcept $c): self { $this->oldThesaurusConcept = $c; return $this; }

    public function getNewThesaurusConcept(): ?ThesaurusConcept { return $this->newThesaurusConcept; }
    public function setNewThesaurusConcept(?ThesaurusConcept $c): self { $this->newThesaurusConcept = $c; return $this; }

    public function getMatchMethod(): ?string { return $this->matchMethod; }
    public function setMatchMethod(?string $m): self { $this->matchMethod = $m; return $this; }

    public function getScore(): ?float { return $this->score; }
    public function setScore(?float $score): self { $this->score = $score; return $this; }

    public function getReason(): ?string { return $this->reason; }
    public function setReason(?string $reason): self { $this->reason = $reason; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }

    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(DateTimeImmutable $dt): self { $this->createdAt = $dt; return $this; }
}
