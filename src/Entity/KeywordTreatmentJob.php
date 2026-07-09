<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use DateTimeImmutable;

#[ORM\Entity]
#[ORM\Table(name: 'keyword_treatment_job')]
class KeywordTreatmentJob
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'started_at')]
    private DateTimeImmutable $startedAt;

    #[ORM\Column(name: 'finished_at', nullable: true)]
    private ?DateTimeImmutable $finishedAt = null;

    #[ORM\Column(length: 30)]
    private string $status = 'pending'; // pending, running, completed, failed

    #[ORM\Column(length: 30)]
    private string $mode = 'dry_run'; // dry_run, execute

    #[ORM\Column(name: 'started_by', length: 100)]
    private string $startedBy = 'system';

    #[ORM\Column(name: 'total_keywords', type: 'integer', options: ['default' => 0])]
    private int $totalKeywords = 0;

    #[ORM\Column(name: 'total_document_keywords', type: 'integer', options: ['default' => 0])]
    private int $totalDocumentKeywords = 0;

    #[ORM\Column(name: 'cleaned_count', type: 'integer', options: ['default' => 0])]
    private int $cleanedCount = 0;

    #[ORM\Column(name: 'invalid_count', type: 'integer', options: ['default' => 0])]
    private int $invalidCount = 0;

    #[ORM\Column(name: 'suspicious_count', type: 'integer', options: ['default' => 0])]
    private int $suspiciousCount = 0;

    #[ORM\Column(name: 'exact_matched_count', type: 'integer', options: ['default' => 0])]
    private int $exactMatchedCount = 0;

    /** @deprecated Use exactMatchedCount */
    #[ORM\Column(name: 'exact_grouped_count', type: 'integer', options: ['default' => 0])]
    private int $exactGroupedCount = 0;

    #[ORM\Column(name: 'thesaurus_matched_count', type: 'integer', options: ['default' => 0])]
    private int $thesaurusMatchedCount = 0;

    #[ORM\Column(name: 'fuzzy_auto_matched_count', type: 'integer', options: ['default' => 0])]
    private int $fuzzyAutoMatchedCount = 0;

    #[ORM\Column(name: 'fuzzy_review_count', type: 'integer', options: ['default' => 0])]
    private int $fuzzyReviewCount = 0;

    #[ORM\Column(name: 'created_concept_count', type: 'integer', options: ['default' => 0])]
    private int $createdConceptCount = 0;

    #[ORM\Column(name: 'skipped_count', type: 'integer', options: ['default' => 0])]
    private int $skippedCount = 0;

    #[ORM\Column(name: 'error_count', type: 'integer', options: ['default' => 0])]
    private int $errorCount = 0;

    #[ORM\Column(name: 'affected_document_keyword_count', type: 'integer', options: ['default' => 0])]
    private int $affectedDocumentKeywordCount = 0;

    #[ORM\Column(name: 'affected_document_count', type: 'integer', options: ['default' => 0])]
    private int $affectedDocumentCount = 0;

    #[ORM\Column(name: 'report_path', length: 255, nullable: true)]
    private ?string $reportPath = null;

    #[ORM\Column(name: 'created_at')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->startedAt = new DateTimeImmutable();
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getStartedAt(): DateTimeImmutable { return $this->startedAt; }
    public function setStartedAt(DateTimeImmutable $dt): self { $this->startedAt = $dt; return $this; }

    public function getFinishedAt(): ?DateTimeImmutable { return $this->finishedAt; }
    public function setFinishedAt(?DateTimeImmutable $dt): self { $this->finishedAt = $dt; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }

    public function getMode(): string { return $this->mode; }
    public function setMode(string $mode): self { $this->mode = $mode; return $this; }

    public function getStartedBy(): string { return $this->startedBy; }
    public function setStartedBy(string $startedBy): self { $this->startedBy = $startedBy; return $this; }

    public function getTotalKeywords(): int { return $this->totalKeywords; }
    public function setTotalKeywords(int $val): self { $this->totalKeywords = $val; return $this; }

    public function getTotalDocumentKeywords(): int { return $this->totalDocumentKeywords; }
    public function setTotalDocumentKeywords(int $val): self { $this->totalDocumentKeywords = $val; return $this; }

    public function getCleanedCount(): int { return $this->cleanedCount; }
    public function setCleanedCount(int $val): self { $this->cleanedCount = $val; return $this; }

    public function getInvalidCount(): int { return $this->invalidCount; }
    public function setInvalidCount(int $val): self { $this->invalidCount = $val; return $this; }

    public function getSuspiciousCount(): int { return $this->suspiciousCount; }
    public function setSuspiciousCount(int $val): self { $this->suspiciousCount = $val; return $this; }

    public function getExactMatchedCount(): int { return $this->exactMatchedCount; }
    public function setExactMatchedCount(int $val): self { $this->exactMatchedCount = $val; return $this; }

    /** @deprecated */
    public function getExactGroupedCount(): int { return $this->exactGroupedCount; }
    /** @deprecated */
    public function setExactGroupedCount(int $val): self { $this->exactGroupedCount = $val; return $this; }

    public function getThesaurusMatchedCount(): int { return $this->thesaurusMatchedCount; }
    public function setThesaurusMatchedCount(int $val): self { $this->thesaurusMatchedCount = $val; return $this; }

    public function getFuzzyAutoMatchedCount(): int { return $this->fuzzyAutoMatchedCount; }
    public function setFuzzyAutoMatchedCount(int $val): self { $this->fuzzyAutoMatchedCount = $val; return $this; }

    public function getFuzzyReviewCount(): int { return $this->fuzzyReviewCount; }
    public function setFuzzyReviewCount(int $val): self { $this->fuzzyReviewCount = $val; return $this; }

    public function getCreatedConceptCount(): int { return $this->createdConceptCount; }
    public function setCreatedConceptCount(int $val): self { $this->createdConceptCount = $val; return $this; }

    public function getSkippedCount(): int { return $this->skippedCount; }
    public function setSkippedCount(int $val): self { $this->skippedCount = $val; return $this; }

    public function getErrorCount(): int { return $this->errorCount; }
    public function setErrorCount(int $val): self { $this->errorCount = $val; return $this; }

    public function getAffectedDocumentKeywordCount(): int { return $this->affectedDocumentKeywordCount; }
    public function setAffectedDocumentKeywordCount(int $val): self { $this->affectedDocumentKeywordCount = $val; return $this; }

    public function getAffectedDocumentCount(): int { return $this->affectedDocumentCount; }
    public function setAffectedDocumentCount(int $val): self { $this->affectedDocumentCount = $val; return $this; }

    public function getReportPath(): ?string { return $this->reportPath; }
    public function setReportPath(?string $path): self { $this->reportPath = $path; return $this; }

    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(DateTimeImmutable $dt): self { $this->createdAt = $dt; return $this; }

    public function getUpdatedAt(): DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(DateTimeImmutable $dt): self { $this->updatedAt = $dt; return $this; }
}
