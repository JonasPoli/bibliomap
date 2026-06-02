<?php

namespace App\Twig;

use App\Config\DataSources;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\Markup;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Twig extension that exposes data source logos and info to templates.
 *
 * Available in Twig:
 *   {{ source_logo('scopus') }}                  → <img> or emoji span, safe HTML
 *   {{ source_logo('wos', 32) }}                 → logo at 32px height
 *   {{ source_label('wos') }}                    → "Web of Science"
 *   {{ source_info('scopus') }}                  → full source array
 *   {{ data_sources() }}                         → all sources array
 */
class DataSourceExtension extends AbstractExtension implements GlobalsInterface
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('source_logo',  [$this, 'sourceLogo'],  ['is_safe' => ['html']]),
            new TwigFunction('source_label', [$this, 'sourceLabel']),
            new TwigFunction('source_info',  [$this, 'sourceInfo']),
            new TwigFunction('data_sources', [$this, 'dataSources']),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('source_logo',  [$this, 'sourceLogo'],  ['is_safe' => ['html']]),
            new TwigFilter('source_label', [$this, 'sourceLabel']),
        ];
    }

    public function getGlobals(): array
    {
        // Makes `data_sources` available as a global Twig variable too
        return ['data_sources' => DataSources::all()];
    }

    // ── Public functions exposed to Twig ──────────────────────────────────────

    /**
     * Returns an <img> or styled <span> for a source logo.
     *
     * @param string $key    Source key (e.g. 'scopus', 'wos')
     * @param int    $height Logo height in px (default 28)
     * @param bool   $dark   Use dark-background variant (default true, suits dark UI)
     */
    public function sourceLogo(string $key, int $height = 28, bool $dark = true): string
    {
        $src = DataSources::get($key);

        if ($src === null || $src['logo'] === null) {
            // Fallback: emoji in a styled span
            return $this->emojiSpan(DataSources::emoji($key), $height);
        }

        $logo = $dark ? ($src['logoDark'] ?? $src['logo']) : $src['logo'];

        return sprintf(
            '<img src="%s" alt="%s" height="%d" style="max-width:%dpx;object-fit:contain;display:block" loading="lazy">',
            htmlspecialchars($logo, ENT_QUOTES),
            htmlspecialchars($src['label'], ENT_QUOTES),
            $height,
            $height * 5,  // generous max-width
        );
    }

    public function sourceLabel(string $key): string
    {
        return DataSources::label($key);
    }

    public function sourceInfo(string $key): ?array
    {
        return DataSources::get($key);
    }

    public function dataSources(): array
    {
        return DataSources::all();
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function emojiSpan(string $emoji, int $height): string
    {
        $fontSize = max(14, (int) ($height * 0.85));
        return sprintf(
            '<span style="font-size:%dpx;line-height:1" role="img">%s</span>',
            $fontSize,
            htmlspecialchars($emoji, ENT_QUOTES),
        );
    }
}
