<?php

namespace App\Tests\Service\Import;

use App\DTO\BibliographicRecordDTO;
use App\Entity\BibliometricProject;
use App\Entity\Dataset;
use App\Service\Import\DocumentEnrichmentService;
use App\Service\Import\DocumentImportService;
use App\Tests\Service\Authority\LocalDbTestCase;
use Psr\Log\NullLogger;

/**
 * Testes de importação no MySQL local: cada teste cria um projeto vazio e datasets dentro da transação de
 * LocalDbTestCase, importa e confere; o rollback no fim desfaz tudo.
 */
abstract class ImportDbTestCase extends LocalDbTestCase
{
    private ?BibliometricProject $project = null;

    protected function project(): BibliometricProject
    {
        if ($this->project === null) {
            $userId = (int) $this->conn->fetchOne('SELECT id FROM `user` ORDER BY id LIMIT 1');
            $now = date('Y-m-d H:i:s');
            $this->conn->insert('bibliometric_project', [
                'user_id' => $userId,
                'title' => 'Teste de importação ' . uniqid(),
                'slug' => 'teste-importacao-' . uniqid(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->project = $this->em->find(BibliometricProject::class, (int) $this->conn->lastInsertId());
        }

        return $this->project;
    }

    protected function newDataset(string $source): Dataset
    {
        $d = new Dataset();
        $d->setProject($this->project());
        $d->setName('teste');
        $d->setSource($source);
        $d->setOriginalFilename('teste.txt');
        $d->setFilePath('/dev/null');
        $this->em->persist($d);
        $this->em->flush();

        return $d;
    }

    /**
     * @param iterable<BibliographicRecordDTO|mixed> $records
     * @return array{read: int, imported: int, skipped: int, errors: int}
     */
    protected function import(iterable $records, Dataset $dataset): array
    {
        $service = new DocumentImportService($this->em, new NullLogger(), $this->createStub(DocumentEnrichmentService::class));

        return $service->importAll($records, $dataset, null, true);
    }

    protected static function dto(string $source, ?string $title, ?int $year, ?string $externalId = null, ?string $doi = null, bool $strict = false): BibliographicRecordDTO
    {
        $d = new BibliographicRecordDTO();
        $d->source = $source;
        $d->title = $title;
        $d->year = $year;
        $d->externalId = $externalId;
        $d->doi = $doi;
        $d->strictIdentity = $strict;

        return $d;
    }

    /** @return list<array<string, mixed>> */
    protected function skips(Dataset $dataset): array
    {
        return $this->conn->fetchAllAssociative('SELECT * FROM dataset_skip WHERE dataset_id = ? ORDER BY id', [$dataset->getId()]);
    }

    protected function docCount(Dataset $dataset): int
    {
        return (int) $this->conn->fetchOne('SELECT COUNT(*) FROM document WHERE dataset_id = ?', [$dataset->getId()]);
    }
}
