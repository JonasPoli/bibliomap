<?php

namespace App\Service\Import\Bdtd;

use App\DTO\BibliographicRecordDTO;

/**
 * Montagem do registro da BDTD, comum ao CSV e ao RIS, a partir dos valores brutos dos campos.
 */
trait BdtdRecordBuilder
{
    /** @var array<string, int> chave → vezes que já apareceu no arquivo (número de ordem) */
    private array $seenKeys = [];

    private function resetKeys(): void
    {
        $this->seenKeys = [];
    }

    /**
     * @param array{author: ?string, title: ?string, year: ?string, link: ?string, type?: ?string, language?: ?string,
     *              abstract?: ?string, institution?: ?string, country?: ?string, subjects?: list<string>, access?: ?string} $f
     */
    private function buildRecord(array $f, int $position): BibliographicRecordDTO
    {
        $dto = new BibliographicRecordDTO();
        $dto->source = 'bdtd';
        $dto->position = $position;
        $dto->strictIdentity = true;

        $key = BdtdFieldCleaner::rowKey($f['author'], $f['title'], $f['year'], $f['link']);
        $n = ($this->seenKeys[$key] ?? 0) + 1;
        $this->seenKeys[$key] = $n;
        $dto->externalId = $n > 1 ? $key . '#' . $n : $key;

        $author = BdtdFieldCleaner::author(explode('||', (string) $f['author']));
        $dto->authorNames = $author !== null ? [$author] : [];
        $dto->title = BdtdFieldCleaner::text($f['title']);
        $year = BdtdFieldCleaner::value($f['year']);
        $dto->year = $year !== null && preg_match('/^\d{4}$/', $year) ? (int) $year : null;
        $dto->documentType = BdtdFieldCleaner::documentType($f['type'] ?? null);
        $dto->language = BdtdFieldCleaner::language(BdtdFieldCleaner::list($f['language'] ?? null));
        $abstract = BdtdFieldCleaner::text($f['abstract'] ?? null);
        $dto->abstractText = $abstract !== null ? trim(strip_tags($abstract)) : null;
        $links = BdtdFieldCleaner::links(preg_split('/\|\||;\s/', (string) $f['link']) ?: []);
        $dto->url = $links['url'];
        $dto->doi = $links['doi'];
        $dto->openAccessStatus = BdtdFieldCleaner::openAccess($f['access'] ?? null);

        $inst = BdtdFieldCleaner::text($f['institution'] ?? null);
        if ($inst !== null) {
            $inst = trim(preg_replace('/\s*\([^()]+\)\s*$/u', '', $inst));
            $dto->institutions = [$inst];
            $dto->publisher = $inst;
        }
        if (array_key_exists('country', $f)) {
            $country = BdtdFieldCleaner::value($f['country']);
            $dto->countries = [$country === null || in_array(mb_strtolower($country), ['brasil', 'br', 'brazil'], true) ? 'Brasil' : $country];
        }

        $kw = [];
        $seen = [];
        foreach ($f['subjects'] ?? [] as $s) {
            $s = BdtdFieldCleaner::text($s);
            if ($s !== null && !isset($seen[mb_strtolower($s)])) {
                $seen[mb_strtolower($s)] = true;
                $kw[] = $s;
            }
        }
        $dto->authorKeywords = $kw;

        return $dto;
    }
}
