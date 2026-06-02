<?php

namespace App\Config;

/**
 * Central registry for all supported bibliometric data sources.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  HOW TO ADD A NEW SOURCE
 * ─────────────────────────────────────────────────────────────────────────────
 *  1. Add a new entry to the SOURCES constant below.
 *  2. Place the logo file in /public/img/sources/<key>.svg (or .png).
 *  3. That's it — every template and the Dataset entity will pick it up.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class DataSources
{
    /**
     * All known data sources, keyed by their internal identifier.
     *
     * Fields:
     *  - key        string   Internal key (must match Dataset::$source values)
     *  - label      string   Human-readable name
     *  - vendor     string   Company / project behind the source
     *  - url        ?string  Homepage / search URL
     *  - logo       string   Path relative to /public (light/white-bg version)
     *  - logoDark   string   Path relative to /public (dark-bg version, same if identical)
     *  - logoType   string   'svg' or 'img' — affects rendering in templates
     *  - formats    string[] Accepted file extensions
     *  - emoji      string   Fallback emoji (console output, no-CSS contexts)
     *  - limit      ?int     Max records per export (null = unlimited / via API)
     *  - bgColor    string   Brand background color (used in logo containers)
     *  - accentColor string  Brand accent/text color
     */
    public const SOURCES = [

        // ── Scopus (Elsevier) ─────────────────────────────────────────────────
        'scopus' => [
            'key'         => 'scopus',
            'label'       => 'Scopus',
            'vendor'      => 'Elsevier',
            'url'         => 'https://www.scopus.com',
            'logo'        => '/img/Scopus_logo.svg',
            'logoDark'    => '/img/Scopus_logo.svg',
            'logoType'    => 'svg',
            'logoBg'      => null,   // orange logo — visible on dark
            'formats'     => ['csv'],
            'emoji'       => '🔬',
            'limit'       => 2000,
            'bgColor'     => '#1a0a00',
            'accentColor' => '#e87722',
        ],

        // ── Web of Science (Clarivate) ────────────────────────────────────────
        'wos' => [
            'key'         => 'wos',
            'label'       => 'Web of Science',
            'vendor'      => 'Clarivate',
            'url'         => 'https://www.webofscience.com',
            'logo'        => '/img/clarivate-logo.svg',
            'logoDark'    => '/img/clarivate-logo.svg',
            'logoType'    => 'svg',
            'logoBg'      => '#ffffff',  // Clarivate SVG has black text
            'formats'     => ['txt'],
            'emoji'       => '🌐',
            'limit'       => 500,
            'bgColor'     => '#000d1a',
            'accentColor' => '#0056a0',
        ],

        // ── Lens.org (Cambia / IOI) ───────────────────────────────────────────
        'lens' => [
            'key'         => 'lens',
            'label'       => 'Lens.org',
            'vendor'      => 'Cambia / IOI',
            'url'         => 'https://www.lens.org/lens/scholar',
            'logo'        => '/img/IOI-Device.png',
            'logoDark'    => '/img/IOI-Device-onDark.png',
            'logoType'    => 'img',
            'logoBg'      => null,   // IOI-onDark designed for dark backgrounds
            'formats'     => ['csv'],
            'emoji'       => '🔭',
            'limit'       => 1000,
            'bgColor'     => '#0d0d1a',
            'accentColor' => '#00b4d8',
        ],

        // ── PubMed (NCBI / NLM) ───────────────────────────────────────────────
        'pubmed' => [
            'key'         => 'pubmed',
            'label'       => 'PubMed',
            'vendor'      => 'NCBI / NLM',
            'url'         => 'https://pubmed.ncbi.nlm.nih.gov',
            'logo'        => '/img/US-NLM-PubMed-Logo.svg',
            'logoDark'    => '/img/US-NLM-PubMed-Logo.svg',
            'logoType'    => 'svg',
            'logoBg'      => '#ffffff',  // NLM logo has dark-blue and dark text
            'formats'     => ['csv', 'nbib', 'xml'],
            'emoji'       => '🧬',
            'limit'       => null,
            'bgColor'     => '#00101f',
            'accentColor' => '#336699',
        ],

        // ── OpenAlex (OurResearch) ────────────────────────────────────────────
        'openalex' => [
            'key'         => 'openalex',
            'label'       => 'OpenAlex',
            'vendor'      => 'OurResearch',
            'url'         => 'https://openalex.org/works',
            'logo'        => '/img/OpenAlex_logo_2021.svg',
            'logoDark'    => '/img/OpenAlex_logo_2021.svg',
            'logoType'    => 'svg',
            'logoBg'      => null,   // assume colorful logo on transparent
            'formats'     => ['csv'],
            'emoji'       => '🔓',
            'limit'       => 10000,
            'bgColor'     => '#0a0812',
            'accentColor' => '#a78bfa',
        ],

        // ── Crossref ──────────────────────────────────────────────────────────
        'crossref' => [
            'key'         => 'crossref',
            'label'       => 'Crossref',
            'vendor'      => 'Crossref',
            'url'         => 'https://search.crossref.org',
            'logo'        => '/img/idH-UrdJOS_1780373130233.png',
            'logoDark'    => '/img/idH-UrdJOS_1780373130233.png',
            'logoType'    => 'img',
            'logoBg'      => '#ffffff',  // PNG logo assumed to have dark elements
            'formats'     => ['csv', 'ris'],
            'emoji'       => '🔗',
            'limit'       => null,
            'bgColor'     => '#1a0000',
            'accentColor' => '#cc2200',
        ],

        // ── Generic / Other ───────────────────────────────────────────────────
        'generic' => [
            'key'         => 'generic',
            'label'       => 'Genérico',
            'vendor'      => null,
            'url'         => null,
            'logo'        => null,
            'logoDark'    => null,
            'logoType'    => null,
            'logoBg'      => null,
            'formats'     => ['ris', 'bib', 'csv', 'xlsx', 'xml'],
            'emoji'       => '📄',
            'limit'       => null,
            'bgColor'     => '#0d1520',
            'accentColor' => '#4f8ef7',
        ],

    ];

    /** Returns all sources as an ordered array. */
    public static function all(): array
    {
        return self::SOURCES;
    }

    /** Returns one source config by key, or null if unknown. */
    public static function get(string $key): ?array
    {
        return self::SOURCES[$key] ?? null;
    }

    /** Returns the label for a given key, falling back to the raw key. */
    public static function label(string $key): string
    {
        return self::SOURCES[$key]['label'] ?? $key;
    }

    /** Returns the emoji for a given key (safe for non-HTML contexts). */
    public static function emoji(string $key): string
    {
        return self::SOURCES[$key]['emoji'] ?? '📄';
    }

    /** Returns all source keys (useful for form select options). */
    public static function keys(): array
    {
        return array_keys(self::SOURCES);
    }

    /**
     * Returns form-friendly choices: ['Scopus' => 'scopus', ...].
     * @return array<string, string>
     */
    public static function choices(): array
    {
        $choices = [];
        foreach (self::SOURCES as $key => $src) {
            $choices[$src['label']] = $key;
        }
        return $choices;
    }
}
