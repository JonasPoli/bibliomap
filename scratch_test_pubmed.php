<?php

require __DIR__ . '/vendor/autoload.php';

use App\Service\Import\PubmedNbibImporter;

$importer = new PubmedNbibImporter();
$filePath = '/Users/jonaspoli/work/html/bibliometric/docs/sources/pubmed-Artificial-set.txt';

echo "=== DETECTING ===\n";
$score = $importer->detect($filePath);
echo "Confidence score: {$score}\n";

echo "=== COUNTING ===\n";
$total = $importer->countRows($filePath);
echo "Total records in file: {$total}\n";

echo "=== PARSING FIRST 3 RECORDS ===\n";
$result = $importer->parse($filePath, 3);

echo "Total parsed: " . count($result->records) . "\n";
foreach ($result->records as $index => $record) {
    echo "\n--- Record #" . ($index + 1) . " ---\n";
    echo "PMID: " . $record->pmid . "\n";
    echo "Title: " . $record->title . "\n";
    echo "Year: " . $record->year . "\n";
    echo "Journal: " . $record->sourceTitle . "\n";
    echo "DOI: " . $record->doi . "\n";
    echo "Authors: " . implode(', ', $record->authorNames) . "\n";
    echo "Keywords: " . implode(', ', $record->authorKeywords) . "\n";
    echo "MeSH: " . implode(', ', $record->indexedKeywords) . "\n";
    echo "Countries: " . implode(', ', $record->countries) . "\n";
    echo "Institutions: " . implode(', ', $record->institutions) . "\n";
    echo "Abstract Length: " . strlen($record->abstractText ?? '') . "\n";
}
