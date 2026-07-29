<?php

namespace App\Service\Thesaurus;

use League\Csv\Reader;
use League\Csv\Writer;

class ThesaurusFileService
{
    /**
     * Parses a thesaurus file (.the or .csv).
     * Returns an array of records: [ ['header' => string, 'variations' => string[]], ... ]
     */
    public function parseFile(string $filePath, ?string $extension = null): array
    {
        if ($extension === null) {
            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        }

        if ($extension === 'csv') {
            return $this->parseCsv($filePath);
        }

        return $this->parseThe($filePath);
    }

    public function parseTheContent(string $content): array
    {
        if (!mb_detect_encoding($content, 'UTF-8', true)) {
            $content = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
        }

        $lines = explode("\n", $content);
        $currentHeader = null;
        $currentVars = [];
        $entries = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            if (str_starts_with($line, '**')) {
                if ($currentHeader !== null) {
                    $entries[] = [
                        'header' => $currentHeader,
                        'variations' => array_values(array_unique($currentVars))
                    ];
                }
                $currentHeader = trim(ltrim($line, '*#'));
                $currentVars = [];
            } else {
                if (preg_match('/\\^(.*?)\\$?$/', $line, $m)) {
                    $v = trim($m[1]);
                    if ($v !== '') $currentVars[] = $v;
                } else {
                    $v = trim(rtrim($line, '$'));
                    if ($v !== '') $currentVars[] = $v;
                }
            }
        }

        if ($currentHeader !== null) {
            $entries[] = [
                'header' => $currentHeader,
                'variations' => array_values(array_unique($currentVars))
            ];
        }

        return $entries;
    }

    private function parseThe(string $filePath): array
    {
        $content = file_get_contents($filePath);
        return $this->parseTheContent($content);
    }

    private function parseCsv(string $filePath): array
    {
        $csv = Reader::createFromPath($filePath, 'r');
        $csv->setHeaderOffset(0);

        $grouped = [];
        foreach ($csv->getRecords() as $record) {
            $header = trim($record['preferred_name'] ?? $record['preferred_keyword'] ?? $record['preferred_journal'] ?? $record['preferred'] ?? $record['keep_name'] ?? $record['keep'] ?? $record['header'] ?? '');
            $variant = trim($record['variant_name'] ?? $record['variant_keyword'] ?? $record['variant_journal'] ?? $record['variant'] ?? $record['discard_name'] ?? $record['discard'] ?? $record['variation'] ?? '');

            if ($header === '' || $variant === '') continue;

            if (!isset($grouped[$header])) {
                $grouped[$header] = [];
            }
            $grouped[$header][] = $variant;
        }

        $entries = [];
        foreach ($grouped as $h => $vars) {
            $entries[] = [
                'header' => $h,
                'variations' => array_values(array_unique($vars))
            ];
        }

        return $entries;
    }

    /**
     * Generates VantagePoint .the file content.
     * $data is an array of [ 'header' => string, 'variations' => string[] ]
     */
    public function generateTheContent(array $data): string
    {
        $out = [];
        foreach ($data as $item) {
            $header = trim($item['header'] ?? '');
            if ($header === '') continue;

            $out[] = "**#" . mb_strtolower($header, 'UTF-8');
            $vars = $item['variations'] ?? [];
            foreach ($vars as $v) {
                $v = trim($v);
                if ($v === '') continue;
                $out[] = "100 1 ^" . mb_strtolower($v, 'UTF-8') . "$";
            }
        }
        return implode("\r\n", $out);
    }

    /**
     * Generates CSV file content.
     * $data is an array of [ 'header' => string, 'variations' => string[] ]
     */
    public function generateCsvContent(array $data): string
    {
        $csv = Writer::createFromString('');
        $csv->insertOne(['preferred_name', 'variant_name']);

        foreach ($data as $item) {
            $header = trim($item['header'] ?? '');
            if ($header === '') continue;

            $vars = $item['variations'] ?? [];
            foreach ($vars as $v) {
                $v = trim($v);
                if ($v === '') continue;
                $csv->insertOne([$header, $v]);
            }
        }

        return $csv->toString();
    }
}
