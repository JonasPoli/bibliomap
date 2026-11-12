<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Um vínculo documento -> autor/palavra-chave/instituição alterado por uma ação do diário
 * (id anterior, texto original e posição). Só inserção; sem chave estrangeira.
 */
#[ORM\Entity]
#[ORM\Table(name: 'authority_merge_log_link')]
#[ORM\Index(name: 'idx_amll_log', columns: ['log_id'])]
class AuthorityMergeLogLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'log_id')]
    private int $logId = 0;

    #[ORM\Column(name: 'document_id')]
    private int $documentId = 0;

    /** document_author | document_keyword | documento_instituicoes */
    #[ORM\Column(name: 'link_table', length: 40)]
    private string $linkTable = '';

    #[ORM\Column(name: 'old_entity_id')]
    private int $oldEntityId = 0;

    #[ORM\Column(name: 'original_text', length: 255, nullable: true)]
    private ?string $originalText = null;

    #[ORM\Column(nullable: true)]
    private ?int $position = null;

    public function getId(): ?int { return $this->id; }
    public function getLogId(): int { return $this->logId; }
    public function getDocumentId(): int { return $this->documentId; }
    public function getLinkTable(): string { return $this->linkTable; }
    public function getOldEntityId(): int { return $this->oldEntityId; }
    public function getOriginalText(): ?string { return $this->originalText; }
    public function getPosition(): ?int { return $this->position; }
}
