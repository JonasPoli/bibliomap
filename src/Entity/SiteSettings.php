<?php

namespace App\Entity;

use App\Repository\SiteSettingsRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Singleton entity — always id = 1.
 * Use SiteSettingsRepository::getInstance() to read,
 * and SiteSettingsService to write/update.
 */
#[ORM\Entity(repositoryClass: SiteSettingsRepository::class)]
#[ORM\Table(name: 'site_settings')]
#[ORM\HasLifecycleCallbacks]
class SiteSettings
{
    public const SINGLETON_ID = 1;

    #[ORM\Id]
    #[ORM\Column]
    private int $id = self::SINGLETON_ID;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $googleAnalyticsId = null;

    #[ORM\Column(length: 120)]
    private string $seoTitle = 'BiblioMap — Plataforma Bibliométrica';

    #[ORM\Column(type: 'text')]
    private string $seoDescription = 'BiblioMap é a plataforma para análise bibliométrica, cientométrica e mapeamento da produção científica.';

    #[ORM\Column(type: 'text')]
    private string $seoKeywords = 'bibliometria, cientometria, análise bibliométrica, mapa temático, rede de coautoria';

    /** Filename stored under public/uploads/seo/ */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $ogImage = null;

    #[ORM\Column(length: 255)]
    private string $baseUrl = 'http://localhost';

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): int { return $this->id; }

    public function getGoogleAnalyticsId(): ?string { return $this->googleAnalyticsId; }
    public function setGoogleAnalyticsId(?string $v): static { $this->googleAnalyticsId = $v ?: null; return $this; }

    public function getSeoTitle(): string { return $this->seoTitle; }
    public function setSeoTitle(string $v): static { $this->seoTitle = $v; return $this; }

    public function getSeoDescription(): string { return $this->seoDescription; }
    public function setSeoDescription(string $v): static { $this->seoDescription = $v; return $this; }

    public function getSeoKeywords(): string { return $this->seoKeywords; }
    public function setSeoKeywords(string $v): static { $this->seoKeywords = $v; return $this; }

    public function getOgImage(): ?string { return $this->ogImage; }
    public function setOgImage(?string $v): static { $this->ogImage = $v; return $this; }

    public function getBaseUrl(): string { return $this->baseUrl; }
    public function setBaseUrl(string $v): static { $this->baseUrl = rtrim($v, '/'); return $this; }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** Returns the public URL of the OG image, or null. */
    public function getOgImageUrl(): ?string
    {
        if (!$this->ogImage) {
            return null;
        }
        return '/uploads/seo/' . $this->ogImage;
    }
}
