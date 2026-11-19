<?php

namespace App\Text;

/**
 * Texto simples dos catálogos (ajuda contextual e vitrine da home) para HTML seguro:
 * escapa tudo, depois troca **negrito** por <strong> e as quebras de linha por <br>.
 */
final class InlineFormatter
{
    public function format(string $text): string
    {
        $html = $this->escape(trim($text));
        $html = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html);

        return nl2br($html, false);
    }

    public function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
