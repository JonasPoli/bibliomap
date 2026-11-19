<?php

namespace App\Service\Import\Bdtd;

use App\Service\Import\Ris\AbstractRisImporter;
use App\Service\Import\TextNormalizer;

/**
 * Limpeza dos campos da exportação da BDTD (CSV e RIS do botão "Exportar"): o texto "Não informado pela
 * instituição" é ausência de valor (inclusive como item de lista), valores múltiplos vêm separados por "||",
 * títulos e assuntos podem ter a marca de idioma "[pt] " e a coluna de autor traz orientador, data, ORCID
 * ou e-mail misturados.
 */
final class BdtdFieldCleaner
{
    public const NOT_INFORMED = 'não informado pela instituição';

    public static function value(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $v = trim($v);
        if ($v === '' || mb_strtolower($v) === self::NOT_INFORMED) {
            return null;
        }

        return $v;
    }

    /** @return list<string> itens de uma célula "a||b||c", sem vazios, sem "não informado" e sem repetidos */
    public static function list(?string $v): array
    {
        $out = [];
        foreach (explode('||', (string) $v) as $item) {
            $item = self::value($item);
            if ($item !== null && !in_array($item, $out, true)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    public static function stripLangTag(string $v): string
    {
        return trim(preg_replace('/^\[[a-z]{2}\]\s*/i', '', trim($v)));
    }

    /** Texto: entidades HTML convertidas, marca de idioma removida, espaços juntados. */
    public static function text(?string $v): ?string
    {
        $v = TextNormalizer::decodeEntities(self::value($v));
        if ($v === null) {
            return null;
        }
        $v = trim(preg_replace('/\s+/u', ' ', self::stripLangTag($v)));

        return $v === '' ? null : $v;
    }

    /**
     * O autor do trabalho: o primeiro item que não é data, endereço, e-mail nem "não informado", sem o sufixo
     * de ano de nascimento (", 1973-") e sem a instituição entre colchetes ("[UNESP]").
     *
     * @param list<string> $items
     */
    public static function author(array $items): ?string
    {
        foreach ($items as $item) {
            $item = self::value($item);
            if ($item === null
                || preg_match('/^\d{4}(-\d{2}(-\d{2})?)?$/', $item)
                || preg_match('#^https?://#i', $item)
                || str_contains($item, '@')
                || preg_match_all('/\pL/u', $item) < 2) {
                continue;
            }
            $item = preg_replace('/,\s*\d{4}-(\d{4})?\.?\s*$/u', '', $item);
            $item = preg_replace('/\s*\[[^\]]+\]\s*$/u', '', $item);
            $item = trim($item);
            if ($item !== '') {
                return $item;
            }
        }

        return null;
    }

    /** Primeiro idioma, em código de 3 letras (pt_BR, pt → por; en_US, en → eng; es → spa). */
    public static function language(array $items): ?string
    {
        $map = ['pt_br' => 'por', 'pt' => 'por', 'pt-br' => 'por', 'en_us' => 'eng', 'en' => 'eng', 'es' => 'spa', 'it' => 'ita', 'fr' => 'fra'];
        foreach ($items as $item) {
            $item = mb_strtolower(trim($item));
            if ($item !== '') {
                return $map[$item] ?? $item;
            }
        }

        return null;
    }

    /**
     * Endereço e DOI dos itens de "Link de acesso": o DOI do primeiro endereço doi.org; o endereço é o primeiro
     * http(s) que não é DOI (ou o do DOI, se for o único). Itens que não são endereço são ignorados.
     *
     * @param list<string> $items
     * @return array{url: ?string, doi: ?string}
     */
    public static function links(array $items): array
    {
        $url = null;
        $doiUrl = null;
        $doi = null;
        foreach ($items as $item) {
            $item = trim($item);
            if (!preg_match('#^https?://#i', $item)) {
                continue;
            }
            if (preg_match('#^https?://(dx\.)?doi\.org/#i', $item)) {
                if ($doi === null && ($d = AbstractRisImporter::doiFrom($item)) !== null) {
                    $doi = $d;
                    $doiUrl = $item;
                }
                continue;
            }
            $url ??= $item;
        }

        return ['url' => TextNormalizer::safeUrl($url ?? $doiUrl), 'doi' => $doi];
    }

    public static function documentType(?string $v): ?string
    {
        return match ($v = self::value($v)) {
            'masterThesis' => 'Dissertação (mestrado)',
            'doctoralThesis' => 'Tese (doutorado)',
            default => $v,
        };
    }

    public static function openAccess(?string $v): ?string
    {
        return match (self::value($v)) {
            'openAccess' => 'Open Access',
            'embargoedAccess' => 'Embargoed',
            'restrictedAccess' => 'Closed',
            null => null,
            default => self::value($v),
        };
    }

    /**
     * Chave do registro (a BDTD não exporta identificador): autor, título, ano e os endereços do link, como vieram
     * no arquivo. "Não informado" conta como vazio e os endereços são só os http(s), unidos por "|": o RIS
     * separa os itens com "; " e deixa vazio o que o CSV chama de "não informado", e assim o CSV e o RIS da
     * mesma busca geram a mesma chave (conferido nos 7.193 registros reais).
     */
    public static function rowKey(?string $author, ?string $title, ?string $year, ?string $link): string
    {
        $urls = [];
        foreach (preg_split('/\|\||;\s/', (string) $link) as $item) {
            $item = trim($item);
            if (preg_match('#^https?://#i', $item)) {
                $urls[] = $item;
            }
        }

        return 'bdtd:' . substr(sha1(implode("\x1f", [
            self::value($author) ?? '', self::value($title) ?? '', trim((string) $year), implode('|', $urls),
        ])), 0, 40);
    }
}
