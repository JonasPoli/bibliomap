<?php

namespace App\Entity;

use App\Repository\AuthorExternalIdentifierRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AuthorExternalIdentifierRepository::class)]
#[ORM\Table(name: 'author_external_identifier')]
class AuthorExternalIdentifier
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AuthorIdentity::class, inversedBy: 'identifiers')]
    #[ORM\JoinColumn(name: 'author_identity_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?AuthorIdentity $authorIdentity = null;

    #[ORM\Column(length: 50)]
    private string $provider = '';

    #[ORM\Column(length: 100)]
    private string $identifier = '';

    #[ORM\Column(length: 255)]
    private string $url = '';

    public function getId(): ?int { return $this->id; }

    public function getAuthorIdentity(): ?AuthorIdentity { return $this->authorIdentity; }
    public function setAuthorIdentity(?AuthorIdentity $v): static { $this->authorIdentity = $v; return $this; }

    public function getProvider(): string { return $this->provider; }
    public function setProvider(string $v): static { $this->provider = $v; return $this; }

    public function getIdentifier(): string { return $this->identifier; }
    public function setIdentifier(string $v): static { $this->identifier = $v; return $this; }

    public function getUrl(): string { return $this->url; }
    public function setUrl(string $v): static { $this->url = $v; return $this; }
}
