<?php

namespace App\Twig;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Ajuda contextual ("?") exibida ao lado de painéis, campos e áreas.
 *
 * O conteúdo fica no catálogo config/help/*.yaml, uma entrada por chave:
 *
 *   report.authors.top_table:
 *     title: "Autores mais produtivos"
 *     what: "Para que serve"
 *     how: "Como usar"
 *     not: "Para que não serve"
 *     example: "Exemplo num caso real"
 *
 * Uso no Twig: {{ help('report.authors.top_table') }}
 */
class HelpExtension extends AbstractExtension
{
    public const SECTIONS = [
        'what'    => ['Para que serve', 'bi-bullseye'],
        'how'     => ['Como usar', 'bi-hand-index'],
        'not'     => ['Não serve para', 'bi-slash-circle'],
        'example' => ['Exemplo real', 'bi-lightbulb'],
    ];

    private ?array $catalog = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/help')]
        private readonly string $helpDir,
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('help', [$this, 'renderHelp'], ['is_safe' => ['html']]),
        ];
    }

    /** @return array<string, array<string, string>> */
    public function getCatalog(): array
    {
        if ($this->catalog !== null) {
            return $this->catalog;
        }

        $this->catalog = [];
        foreach (glob($this->helpDir . '/*.yaml') ?: [] as $file) {
            $this->catalog += Yaml::parseFile($file) ?? [];
        }

        return $this->catalog;
    }

    public function renderHelp(string $key, string $placement = 'auto'): string
    {
        $entry = $this->getCatalog()[$key] ?? null;

        if ($entry === null) {
            return $this->debug
                ? sprintf('<span class="bm-help bm-help-missing" title="Ajuda ausente: %s"><i class="bi bi-question-circle"></i></span>', $this->e($key))
                : '';
        }

        $title = $entry['title'] ?? '';
        $body = '';
        foreach (self::SECTIONS as $field => [$label, $icon]) {
            if (empty($entry[$field])) {
                continue;
            }
            $body .= sprintf(
                '<div class="bm-help-sec bm-help-%s"><div class="bm-help-lbl"><i class="bi %s"></i> %s</div><div>%s</div></div>',
                $field, $icon, $label, $this->format($entry[$field])
            );
        }

        return sprintf(
            '<span role="button" tabindex="0" class="bm-help" data-bm-help data-bs-placement="%s" data-bs-title="%s" data-bs-content="%s" aria-label="Ajuda: %s"><i class="bi bi-question-circle"></i></span>',
            $this->e($placement), $this->e($title), $this->e($body), $this->e($title)
        );
    }

    /** Texto simples com **negrito** e quebras de linha. */
    private function format(string $text): string
    {
        $html = $this->e(trim($text));
        $html = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html);

        return nl2br($html, false);
    }

    private function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
