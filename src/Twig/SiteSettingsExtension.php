<?php

namespace App\Twig;

use App\Entity\SiteSettings;
use App\Repository\SiteSettingsRepository;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Injects 'site_settings' as a Twig global available in ALL templates,
 * both public (home) and authenticated (admin/app).
 */
class SiteSettingsExtension extends AbstractExtension implements GlobalsInterface
{
    private ?SiteSettings $cache = null;

    public function __construct(
        private readonly SiteSettingsRepository $repo,
    ) {}

    public function getGlobals(): array
    {
        if ($this->cache === null) {
            try {
                $this->cache = $this->repo->getInstance();
            } catch (\Throwable) {
                // During migrations or first boot the table may not exist yet
                $this->cache = new SiteSettings();
            }
        }

        return [
            'site_settings' => $this->cache,
        ];
    }
}
