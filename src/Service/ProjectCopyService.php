<?php

namespace App\Service;

use App\Entity\BibliometricProject;
use App\Entity\Dataset;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Efficiently deep-clones a BibliometricProject to another user.
 *
 * Strategy:
 *  1. ORM  – clone project (1 row)
 *  2. ORM  – clone datasets (few rows) + copy files on disk; build old→new dataset ID map
 *  3. DBAL – bulk INSERT documents with CASE WHEN dataset remapping
 *  4. DBAL – build old_doc_id → new_doc_id map (ORDER BY trick on sequential IDs)
 *  5. DBAL – batch INSERT document_author + document_keyword using the mapping
 *
 * Author and Keyword rows are global (no project FK) → reused as-is.
 */
class ProjectCopyService
{
    private const BATCH = 500; // rows per INSERT batch

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection             $dbal,
        private readonly SluggerInterface       $slugger,
    ) {}

    /**
     * @throws \Throwable
     */
    public function copy(BibliometricProject $source, User $targetUser): BibliometricProject
    {
        $this->em->beginTransaction();
        try {
            // ── 1. Clone project ──────────────────────────────────────────────
            $newProject = $this->cloneProject($source, $targetUser);
            $this->em->persist($newProject);
            $this->em->flush(); // get new project ID

            // ── 2. Clone datasets + files ─────────────────────────────────────
            // Map: old_dataset_id => new_dataset_id
            $datasetMap = $this->cloneDatasets($source, $newProject);
            $this->em->flush();

            // ── 3. Bulk clone documents ───────────────────────────────────────
            // Returns ordered list of [old_id, new_id] pairs
            $docMap = $this->bulkCloneDocuments(
                $source->getId(),
                $newProject->getId(),
                $datasetMap,
            );

            // ── 4. Bulk clone document_author + document_keyword ──────────────
            if (!empty($docMap)) {
                $this->bulkCloneDocumentAuthors($docMap);
                $this->bulkCloneDocumentKeywords($docMap);
            }

            $this->em->commit();
            return $newProject;

        } catch (\Throwable $e) {
            $this->em->rollback();
            throw $e;
        }
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function cloneProject(BibliometricProject $source, User $targetUser): BibliometricProject
    {
        $suffix = 1;
        $baseTitle = $source->getTitle() . ' (Cópia)';
        $baseSlug  = (string) $this->slugger->slug($baseTitle)->lower();

        // Ensure unique slug
        $slug = $baseSlug;
        while ($this->em->getRepository(BibliometricProject::class)->findOneBy(['slug' => $slug])) {
            $slug = $baseSlug . '-' . $suffix++;
        }

        return (new BibliometricProject())
            ->setUser($targetUser)
            ->setTitle($baseTitle)
            ->setSlug($slug)
            ->setDescription($source->getDescription())
            ->setResearchQuestion($source->getResearchQuestion())
            ->setObjective($source->getObjective())
            ->setSearchString($source->getSearchString())
            ->setDatabaseSources($source->getDatabaseSources())
            ->setStartYear($source->getStartYear())
            ->setEndYear($source->getEndYear())
            ->setStatus($source->getStatus())
            ->setVisibility(BibliometricProject::VISIBILITY_PRIVATE);
    }

    /**
     * @return array<int,int>  old_dataset_id => new_dataset_id
     */
    private function cloneDatasets(BibliometricProject $source, BibliometricProject $newProject): array
    {
        $map = [];
        foreach ($source->getDatasets() as $ds) {
            $newDs = $this->cloneDataset($ds, $newProject);
            $this->em->persist($newDs);
            // Flush one-by-one to obtain IDs; datasets are few (usually < 20)
            $this->em->flush();
            $map[$ds->getId()] = $newDs->getId();
        }
        return $map;
    }

    private function cloneDataset(Dataset $source, BibliometricProject $newProject): Dataset
    {
        $newDs = (new Dataset())
            ->setProject($newProject)
            ->setName($source->getName())
            ->setDescription($source->getDescription())
            ->setSource($source->getSource())
            ->setSearchPeriodStart($source->getSearchPeriodStart())
            ->setSearchPeriodEnd($source->getSearchPeriodEnd())
            ->setOriginalFilename($source->getOriginalFilename())
            ->setFileFormat($source->getFileFormat())
            ->setRecordsCount($source->getRecordsCount())
            ->setImportedCount($source->getImportedCount())
            ->setDuplicatedCount($source->getDuplicatedCount())
            ->setErrorCount($source->getErrorCount())
            ->setStatus($source->getStatus())
            ->setImportedAt($source->getImportedAt());

        // Copy physical file
        $newPath = $this->copyFile($source->getFilePath());
        $newDs->setFilePath($newPath);

        return $newDs;
    }

    private function copyFile(string $sourcePath): string
    {
        if (!file_exists($sourcePath)) {
            return $sourcePath; // keep original path reference if file missing
        }

        $dir  = dirname($sourcePath);
        $ext  = pathinfo($sourcePath, PATHINFO_EXTENSION);
        $dest = $dir . '/' . bin2hex(random_bytes(8)) . ($ext ? '.' . $ext : '');

        copy($sourcePath, $dest);
        return $dest;
    }

    /**
     * Bulk-inserts documents via DBAL INSERT … SELECT.
     * Returns old_doc_id → new_doc_id mapping.
     *
     * @param  array<int,int> $datasetMap  old_dataset_id => new_dataset_id
     * @return array<int,int>              old_doc_id     => new_doc_id
     */
    private function bulkCloneDocuments(int $oldProjectId, int $newProjectId, array $datasetMap): array
    {
        // Build the CASE WHEN for dataset_id remapping
        $caseWhen = 'dataset_id'; // default: keep null as-is
        if (!empty($datasetMap)) {
            $cases = [];
            foreach ($datasetMap as $oldId => $newId) {
                $cases[] = "WHEN {$oldId} THEN {$newId}";
            }
            $caseWhen = 'CASE dataset_id ' . implode(' ', $cases) . ' ELSE dataset_id END';
        }

        // Fetch ordered old document IDs BEFORE insert (to build mapping later)
        $oldIds = $this->dbal->fetchFirstColumn(
            'SELECT id FROM document WHERE project_id = ? ORDER BY id ASC',
            [$oldProjectId],
        );

        if (empty($oldIds)) {
            return [];
        }

        // Bulk INSERT via INSERT … SELECT
        // Note: id (auto-increment) is omitted → DB assigns new sequential IDs
        $this->dbal->executeStatement(
            "INSERT INTO document
                (project_id, dataset_id, title, normalized_title, abstract_text,
                 year, document_type, doi, pmid, isbn, issn, url, language,
                 source_title, volume, issue, page_start, page_end, publisher,
                 cited_by, local_citations, open_access_status, publication_stage,
                 external_id, source, hash, countries, institutions, `references`, created_at)
             SELECT
                :newProjectId,
                {$caseWhen},
                title, normalized_title, abstract_text,
                year, document_type, doi, pmid, isbn, issn, url, language,
                source_title, volume, issue, page_start, page_end, publisher,
                cited_by, local_citations, open_access_status, publication_stage,
                external_id, source, hash, countries, institutions, `references`, NOW()
             FROM document
             WHERE project_id = :oldProjectId
             ORDER BY id ASC",
            ['newProjectId' => $newProjectId, 'oldProjectId' => $oldProjectId],
        );

        // Fetch new document IDs in insertion order (same ORDER BY id ASC)
        $newIds = $this->dbal->fetchFirstColumn(
            'SELECT id FROM document WHERE project_id = ? ORDER BY id ASC',
            [$newProjectId],
        );

        // Build mapping old → new (same cardinality, same order)
        return array_combine($oldIds, $newIds) ?: [];
    }

    /**
     * @param array<int,int> $docMap  old_document_id => new_document_id
     */
    private function bulkCloneDocumentAuthors(array $docMap): void
    {
        $oldDocIds = array_keys($docMap);

        // Fetch all document_author rows for old documents
        $rows = $this->dbal->fetchAllAssociative(
            'SELECT document_id, author_identity_id, position, original_name
             FROM document_author
             WHERE document_id IN (' . implode(',', $oldDocIds) . ')
             ORDER BY document_id, position',
        );

        if (empty($rows)) return;

        // Batch INSERT with remapped document_id
        foreach (array_chunk($rows, self::BATCH) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $row) {
                $values[] = '(?, ?, ?, ?)';
                $params[] = $docMap[$row['document_id']];
                $params[] = $row['author_identity_id'];
                $params[] = $row['position'];
                $params[] = $row['original_name'];
            }
            $this->dbal->executeStatement(
                'INSERT INTO document_author (document_id, author_identity_id, position, original_name) VALUES '
                . implode(', ', $values),
                $params,
            );
        }
    }

    /**
     * @param array<int,int> $docMap  old_document_id => new_document_id
     */
    private function bulkCloneDocumentKeywords(array $docMap): void
    {
        $oldDocIds = array_keys($docMap);

        $rows = $this->dbal->fetchAllAssociative(
            'SELECT document_id, keyword_id
             FROM document_keyword
             WHERE document_id IN (' . implode(',', $oldDocIds) . ')
             ORDER BY document_id',
        );

        if (empty($rows)) return;

        foreach (array_chunk($rows, self::BATCH) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $row) {
                $values[] = '(?, ?)';
                $params[] = $docMap[$row['document_id']];
                $params[] = $row['keyword_id'];
            }
            $this->dbal->executeStatement(
                'INSERT INTO document_keyword (document_id, keyword_id) VALUES '
                . implode(', ', $values),
                $params,
            );
        }
    }
}
