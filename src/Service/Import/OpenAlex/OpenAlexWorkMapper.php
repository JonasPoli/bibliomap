<?php

namespace App\Service\Import\OpenAlex;

use App\DTO\BibliographicRecordDTO;
use App\Service\Import\Ris\AbstractRisImporter;
use App\Service\Import\TextNormalizer;

/**
 * Um work da API do OpenAlex (objeto JSON) → DTO, identificado pelo Work ID curto (W…).
 */
final class OpenAlexWorkMapper
{
    public static function workId(?string $id): ?string
    {
        return $id !== null && preg_match('/(W\d+)\s*$/', $id, $m) ? $m[1] : null;
    }

    public static function fromApiWork(array $w, int $position): BibliographicRecordDTO
    {
        $id = self::workId(is_string($w['id'] ?? null) ? $w['id'] : null);
        if ($id === null) {
            throw new \RuntimeException('Work sem o identificador do OpenAlex.');
        }

        $dto = new BibliographicRecordDTO();
        $dto->source = 'openalex';
        $dto->externalId = $id;
        $dto->strictIdentity = true;
        $dto->position = $position;

        $title = $w['title'] ?? $w['display_name'] ?? null;
        $dto->title = is_string($title) ? self::text($title) : null;
        $dto->year = is_int($w['publication_year'] ?? null) ? $w['publication_year'] : null;
        $dto->publicationDate = is_string($w['publication_date'] ?? null) ? $w['publication_date'] : null;
        $dto->documentType = is_string($w['type'] ?? null) ? $w['type'] : null;
        $dto->language = is_string($w['language'] ?? null) ? $w['language'] : null;
        $dto->doi = AbstractRisImporter::doiFrom(is_string($w['doi'] ?? null) ? $w['doi'] : null);
        $pmid = $w['ids']['pmid'] ?? null;
        $dto->pmid = is_string($pmid) && preg_match('/(\d+)\s*$/', $pmid, $m) ? $m[1] : null;

        $src = $w['primary_location']['source'] ?? null;
        if (is_array($src)) {
            $dto->sourceTitle = is_string($src['display_name'] ?? null) ? $src['display_name'] : null;
            $issn = $src['issn_l'] ?? (is_array($src['issn'] ?? null) ? ($src['issn'][0] ?? null) : null);
            $dto->issn = AbstractRisImporter::issnFrom(is_string($issn) ? $issn : null);
            $dto->publisher = is_string($src['host_organization_name'] ?? null) ? $src['host_organization_name'] : null;
        }

        $b = is_array($w['biblio'] ?? null) ? $w['biblio'] : [];
        $dto->volume = self::trimmed($b['volume'] ?? null);
        $dto->issue = self::trimmed($b['issue'] ?? null);
        $dto->pageStart = self::trimmed($b['first_page'] ?? null);
        $dto->pageEnd = self::trimmed($b['last_page'] ?? null);

        $dto->citedBy = is_int($w['cited_by_count'] ?? null) ? $w['cited_by_count'] : null;
        $oa = $w['open_access']['oa_status'] ?? null;
        $dto->openAccessStatus = is_string($oa) ? ($oa === 'closed' ? 'Closed' : ucfirst($oa)) : null;

        $authors = [];
        $institutions = [];
        $countries = [];
        $affiliations = [];
        foreach (is_array($w['authorships'] ?? null) ? $w['authorships'] : [] as $a) {
            if (!is_array($a)) {
                continue;
            }
            $name = $a['author']['display_name'] ?? null;
            if (!is_string($name) || preg_match_all('/\pL/u', $name) < 2) {
                $name = is_string($a['raw_author_name'] ?? null) ? $a['raw_author_name'] : null;
            }
            if (is_string($name) && preg_match_all('/\pL/u', $name) >= 2) {
                $authors[] = trim($name);
            }
            foreach (is_array($a['institutions'] ?? null) ? $a['institutions'] : [] as $inst) {
                $iname = $inst['display_name'] ?? null;
                if (is_string($iname) && $iname !== '' && !in_array($iname, $institutions, true)) {
                    $institutions[] = $iname;
                }
                $cc = $inst['country_code'] ?? null;
                if (empty($a['countries']) && is_string($cc) && $cc !== '' && !in_array($cc, $countries, true)) {
                    $countries[] = $cc;
                }
            }
            foreach (is_array($a['countries'] ?? null) ? $a['countries'] : [] as $cc) {
                if (is_string($cc) && $cc !== '' && !in_array($cc, $countries, true)) {
                    $countries[] = $cc;
                }
            }
            foreach (is_array($a['raw_affiliation_strings'] ?? null) ? $a['raw_affiliation_strings'] : [] as $aff) {
                $aff = is_string($aff) ? trim(preg_replace('/\s+/u', ' ', $aff)) : '';
                if ($aff !== '' && !in_array($aff, $affiliations, true)) {
                    $affiliations[] = $aff;
                }
            }
        }
        $dto->authorNames = $authors;
        $dto->institutions = $institutions;
        $dto->countries = $countries;
        $dto->affiliations = $affiliations;

        $kws = [];
        foreach (is_array($w['keywords'] ?? null) ? $w['keywords'] : [] as $k) {
            $n = is_array($k) ? ($k['display_name'] ?? null) : null;
            if (is_string($n) && $n !== '' && !in_array($n, $kws, true)) {
                $kws[] = $n;
            }
        }
        $dto->indexedKeywords = $kws;

        $refs = [];
        foreach (is_array($w['referenced_works'] ?? null) ? $w['referenced_works'] : [] as $r) {
            $rid = is_string($r) ? self::workId($r) : null;
            if ($rid !== null) {
                $refs[] = $rid;
            }
        }
        $dto->references = $refs;

        $dto->abstractText = self::rebuildAbstract($w['abstract_inverted_index'] ?? null);
        $landing = $w['primary_location']['landing_page_url'] ?? null;
        $dto->url = TextNormalizer::safeUrl(is_string($landing) ? $landing : null) ?? 'https://openalex.org/' . $id;

        return $dto;
    }

    /**
     * Resumo a partir do índice invertido {palavra: [posições]}. Ignora posições absurdas e para em 20.000
     * palavras: um índice malformado não pode estourar a memória.
     */
    public static function rebuildAbstract(mixed $index): ?string
    {
        if (!is_array($index) || $index === []) {
            return null;
        }
        $words = [];
        foreach ($index as $word => $positions) {
            if (!is_array($positions)) {
                continue;
            }
            foreach ($positions as $p) {
                if (is_int($p) && $p >= 0 && $p <= 100000) {
                    $words[$p] = (string) $word;
                    if (count($words) >= 20000) {
                        break 2;
                    }
                }
            }
        }
        if ($words === []) {
            return null;
        }
        ksort($words);

        return self::text(implode(' ', $words));
    }

    private static function text(string $v): ?string
    {
        $v = TextNormalizer::decodeEntities(strip_tags($v));

        return $v === null ? null : trim(preg_replace('/\s+/u', ' ', $v));
    }

    private static function trimmed(mixed $v): ?string
    {
        if (!is_string($v) && !is_int($v)) {
            return null;
        }
        $v = trim(str_replace("\u{00A0}", ' ', (string) $v));

        return $v === '' ? null : $v;
    }
}
