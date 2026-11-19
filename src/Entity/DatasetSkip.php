<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'dataset_skip')]
#[ORM\Index(name: 'idx_dataset_skip_dataset', columns: ['dataset_id'])]
class DatasetSkip
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Dataset::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Dataset $dataset = null;

    #[ORM\Column(type: 'text')]
    private ?string $title = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $doi = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $hash = null;

    #[ORM\Column(length: 50)]
    private ?string $reason = null; // doi, hash, db_constraint, error_parse, error_invalid, error_db

    /** Identificador do registro na base (para localizar no arquivo e na base). */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $externalId = null;

    /** Posição do registro no arquivo, a partir de 1. */
    #[ORM\Column(nullable: true)]
    private ?int $position = null;

    /** Detalhe do motivo (mensagem do leitor ou do banco). */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $detail = null;

    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Document $matchedDocument = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDataset(): ?Dataset
    {
        return $this->dataset;
    }

    public function setDataset(?Dataset $dataset): static
    {
        $this->dataset = $dataset;
        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getDoi(): ?string
    {
        return $this->doi;
    }

    public function setDoi(?string $doi): static
    {
        $this->doi = $doi;
        return $this;
    }

    public function getHash(): ?string
    {
        return $this->hash;
    }

    public function setHash(?string $hash): static
    {
        $this->hash = $hash;
        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(string $reason): static
    {
        $this->reason = $reason;
        return $this;
    }

    public function getMatchedDocument(): ?Document
    {
        return $this->matchedDocument;
    }

    public function setMatchedDocument(?Document $doc): static
    {
        $this->matchedDocument = $doc;
        return $this;
    }

    public function getExternalId(): ?string { return $this->externalId; }
    public function setExternalId(?string $v): static { $this->externalId = $v; return $this; }
    public function getPosition(): ?int { return $this->position; }
    public function setPosition(?int $v): static { $this->position = $v; return $this; }
    public function getDetail(): ?string { return $this->detail; }
    public function setDetail(?string $v): static { $this->detail = $v; return $this; }

    public function isError(): bool
    {
        return str_starts_with((string) $this->reason, 'error_');
    }

    /** Motivo em português, para a tela do dataset. */
    public function getReasonLabel(): string
    {
        return match ($this->reason) {
            'doi' => 'DOI já existe no projeto',
            'hash' => 'Mesmo documento já existe no projeto',
            'db_constraint' => 'Recusado pelo banco como repetido',
            'error_parse' => 'Registro ilegível',
            'error_invalid' => 'Sem título, DOI e identificador',
            'error_db' => 'Erro ao gravar',
            default => (string) $this->reason,
        };
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
