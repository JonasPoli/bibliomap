<?php

namespace App\Service\Import\Brapci;

use App\DTO\BibliographicRecordDTO;
use App\Service\Import\Ris\AbstractRisImporter;
use App\Service\Import\TextNormalizer;

/**
 * Uma linha da exportação da cesta da BRAPCI (CSV ou XLS, mesmas colunas) → DTO.
 * Colunas: ID, CLASS, YEAR, PUBLICATION, AUTHORS, TITLE, KEYWORDS, ABSTRACTS, SESSION, LEGEND, URL.
 */
final class BrapciRecordMapper
{
    public const COLUMNS = ['ID', 'CLASS', 'YEAR', 'PUBLICATION', 'AUTHORS', 'TITLE', 'KEYWORDS', 'ABSTRACTS', 'SESSION', 'LEGEND', 'URL'];

    public const HANDLE_PREFIX = 'https://hdl.handle.net/20.500.11959/brapci/';

    /** Rótulos dos tipos, na ordem dos cartões da cesta. */
    public const TYPE_LABELS = ['Article' => 'Artigos', 'Proceeding' => 'Trabalhos de eventos', 'Book' => 'Livros', 'BookChapter' => 'Capítulos'];

    /**
     * @param array<string, ?string> $row       linha com as colunas pelo nome
     * @param array<string, true>    $sharedDois DOIs normalizados usados por mais de um ID no arquivo
     */
    public static function map(array $row, int $position, array $sharedDois = []): BibliographicRecordDTO
    {
        $id = self::value($row['ID'] ?? null);
        if ($id === null || !ctype_digit($id)) {
            throw new \RuntimeException('Linha sem o ID numérico da BRAPCI.');
        }

        $dto = new BibliographicRecordDTO();
        $dto->source = 'brapci';
        $dto->externalId = $id;
        $dto->strictIdentity = true;
        $dto->position = $position;

        $class = self::value($row['CLASS'] ?? null);
        $dto->documentType = $class;

        $year = self::value($row['YEAR'] ?? null);
        $dto->year = $year !== null && preg_match('/^\d{4}$/', $year) ? (int) $year : null;

        $pub = self::text($row['PUBLICATION'] ?? null);
        $dto->sourceTitle = $pub !== null && !in_array(mb_strtoupper($pub), ['LIVRO', 'CAPITULO DE LIVRO', 'CAPÍTULO DE LIVRO'], true) ? $pub : null;

        $title = self::text($row['TITLE'] ?? null);
        $dto->title = $title !== null && !preg_match('/^::\s*sem t[ií]tulo\s*::$/iu', $title) ? $title : null;

        $dto->authorNames = self::authors($row['AUTHORS'] ?? null);
        $dto->authorKeywords = self::split($row['KEYWORDS'] ?? null);

        $abstract = self::text($row['ABSTRACTS'] ?? null);
        $dto->abstractText = $abstract !== null ? trim(strip_tags($abstract)) : null;

        [$dto->volume, $dto->issue] = self::legend(self::text($row['LEGEND'] ?? null));

        $dto->url = self::HANDLE_PREFIX . $id;
        $doi = self::doiFromUrl(self::value($row['URL'] ?? null));
        if ($doi !== null && !isset($sharedDois[$doi]) && $class === 'Article') {
            $dto->doi = $doi;
        }

        return $dto;
    }

    /** DOI normalizado da coluna URL (só quando ela é um endereço do doi.org e o DOI é bem formado). */
    public static function doiFromUrl(?string $url): ?string
    {
        if ($url === null || !preg_match('#^https?://(dx\.)?doi\.org/#i', $url)) {
            return null;
        }

        return AbstractRisImporter::doiFrom($url);
    }

    /** @return array{0: ?string, 1: ?string} [volume, número] */
    public static function legend(?string $legend): array
    {
        if ($legend === null) {
            return [null, null];
        }
        $volume = preg_match('/,\s*v\.\s*([^,]*?)\s*(?:,|$)/u', $legend, $m) ? trim($m[1]) : null;
        $issue = null;
        if (preg_match('/,\s*n\.\s*([^,]*?)\s*(?:,|$)/u', $legend, $m)) {
            $issue = trim(preg_replace('/^(n[ºo°]\.?|no\.?|número)\s*/iu', '', trim($m[1])));
        }

        return [$volume === '' ? null : $volume, $issue === '' ? null : $issue];
    }

    /** @return list<string> */
    public static function authors(?string $raw): array
    {
        $out = [];
        foreach (self::split($raw) as $name) {
            $name = trim(preg_replace('/\s*\((org|orgs|coord|ed|eds)\.?\)\s*$/iu', '', $name));
            if ($name !== '') {
                $out[] = $name;
            }
        }

        return $out;
    }

    /** @return list<string> */
    public static function split(?string $raw): array
    {
        $raw = self::text($raw);
        if ($raw === null) {
            return [];
        }
        $out = [];
        foreach (explode(';', $raw) as $part) {
            $part = trim($part);
            if ($part !== '' && !in_array($part, $out, true)) {
                $out[] = $part;
            }
        }

        return $out;
    }

    /** Valor bruto: vazio e o "null" que o exportador escreve sem aspas viram null. */
    public static function value(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $v = trim($v);

        return $v === '' || $v === 'null' ? null : $v;
    }

    /** Texto com entidades HTML convertidas e espaços juntados. */
    public static function text(?string $v): ?string
    {
        $v = TextNormalizer::decodeEntities(self::value($v));
        if ($v === null) {
            return null;
        }
        $v = trim(preg_replace('/\s+/u', ' ', $v));

        return $v === '' ? null : $v;
    }
}
