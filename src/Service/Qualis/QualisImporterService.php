<?php

namespace App\Service\Qualis;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

class QualisImporterService
{
    private const BULK_SIZE = 1000;

    public function __construct(
        private readonly Connection $conn,
        private readonly LoggerInterface $logger,
        private readonly string $projectDir,
    ) {}

    /**
     * Extracts and imports Qualis journals from PDF.
     *
     * @param string $pdfPath Absolute path to PDF
     * @param string $outputJsonPath Intermediary JSON output path
     * @return array Import stats
     */
    public function importFromPdf(string $pdfPath, string $outputJsonPath): array
    {
        $pythonScript = $this->projectDir . '/src/Service/Qualis/qualis_pdf_extractor.py';

        $this->logger->info("Running Python extractor: {$pythonScript}");
        
        $process = new Process(['python3', $pythonScript, $pdfPath, $outputJsonPath]);
        $process->setTimeout(300); // 5 minutes max
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException("Python extraction failed: " . $process->getErrorOutput());
        }

        $this->logger->info("Python extraction completed successfully. Output: " . $process->getOutput());

        if (!file_exists($outputJsonPath)) {
            throw new \RuntimeException("Output JSON file not found at: {$outputJsonPath}");
        }

        $jsonContent = file_get_contents($outputJsonPath);
        $journals = json_decode($jsonContent, true);

        if (!is_array($journals)) {
            throw new \RuntimeException("Invalid JSON extracted from PDF.");
        }

        $totalJournals = count($journals);
        $this->logger->info("Starting database import of {$totalJournals} journals...");

        $startTime = microtime(true);

        // Upsert por ISSN normalizado. Antes era DELETE FROM qualis_journal + INSERT: o FK SET NULL
        // tirava a revista/Qualis de todos os documentos e o CASCADE apagava variações e bases.
        // Revistas fora do PDF (cadastro manual, Crossref) são mantidas.
        $this->conn->beginTransaction();
        try {
            $batch = [];
            $imported = 0;
            $inserted = 0;
            $updated = 0;
            $seen = [];

            foreach ($journals as $journal) {
                // Mesmo ISSN repetido no PDF (outra área): vale a 1ª ocorrência, como no INSERT IGNORE anterior
                if (isset($seen[$journal['normalized_issn']])) {
                    continue;
                }
                $seen[$journal['normalized_issn']] = true;

                $batch[] = [
                    'issn' => $journal['issn'],
                    'normalized_issn' => $journal['normalized_issn'],
                    'title' => $journal['title'],
                    'qualis' => $journal['qualis']
                ];

                if (count($batch) >= self::BULK_SIZE) {
                    [$ins, $upd] = $this->upsertBatch($batch);
                    $inserted += $ins;
                    $updated += $upd;
                    $imported += count($batch);
                    $batch = [];
                }
            }

            if (!empty($batch)) {
                [$ins, $upd] = $this->upsertBatch($batch);
                $inserted += $ins;
                $updated += $upd;
                $imported += count($batch);
            }

            $this->conn->commit();
            
            $elapsed = round(microtime(true) - $startTime, 2);
            $this->logger->info("Successfully imported {$imported} journals in {$elapsed} seconds.");

            return [
                'total_extracted' => $totalJournals,
                'total_imported' => $imported,
                'inserted' => $inserted,
                'updated' => $updated,
                'time_seconds' => $elapsed
            ];

        } catch (\Throwable $e) {
            $this->conn->rollBack();
            $this->logger->error("Database import failed: " . $e->getMessage());
            throw $e;
        } finally {
            // Clean up intermediary JSON file to save space
            if (file_exists($outputJsonPath)) {
                unlink($outputJsonPath);
            }
        }
    }

    /**
     * Insere revistas novas e atualiza as existentes (mesmo normalized_issn) mantendo o id,
     * para não desfazer vínculos de documentos, variações e bases.
     *
     * @return array{0: int, 1: int} [inseridas, atualizadas]
     */
    private function upsertBatch(array $batch): array
    {
        $existing = (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM qualis_journal WHERE normalized_issn IN (' . implode(',', array_fill(0, count($batch), '?')) . ')',
            array_column($batch, 'normalized_issn')
        );

        $sql = 'INSERT INTO qualis_journal (issn, normalized_issn, title, qualis) VALUES ';
        $placeholders = [];
        $values = [];

        foreach ($batch as $row) {
            $placeholders[] = '(?, ?, ?, ?)';
            $values[] = $row['issn'];
            $values[] = $row['normalized_issn'];
            $values[] = $row['title'];
            $values[] = $row['qualis'];
        }

        $sql .= implode(', ', $placeholders)
            . ' ON DUPLICATE KEY UPDATE issn = VALUES(issn), title = VALUES(title), qualis = VALUES(qualis)';
        $this->conn->executeStatement($sql, $values);

        return [count($batch) - $existing, $existing];
    }
}
