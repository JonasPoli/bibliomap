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
 *  6. DBAL – vínculos geográficos/institucionais, classificação temática,
 *            matrizes salvas e duplicados ignorados (sem eles os relatórios da cópia saem vazios)
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

                // Vínculos gerados pela sincronização (mapas, instituições)
                $this->bulkCloneDocumentLinks('documento_paises', ['country_id'], $docMap);
                $this->bulkCloneDocumentLinks('documento_estados', ['state_id'], $docMap);
                $this->bulkCloneDocumentLinks('documento_cidades', ['city_id'], $docMap);
                $this->bulkCloneDocumentLinks('documento_instituicoes', ['institution_id', 'link_type'], $docMap);
            }

            // ── 5. Classificação temática, matrizes salvas, duplicados ────────
            $groupMap = $this->cloneClassificationGroups($source->getId(), $newProject->getId());
            if (!empty($docMap)) {
                $this->cloneDocumentClassifications($source->getId(), $newProject->getId(), $docMap, $groupMap);
            }
            $this->cloneSavedMatrices($source->getId(), $newProject->getId());
            $this->cloneDatasetSkips($datasetMap, $docMap);

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
            ->setClassificationMinYear($source->getClassificationMinYear())
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
                 external_id, source, hash, countries, institutions, `references`,
                 qualis, qualis_journal_id, created_at)
             SELECT
                :newProjectId,
                {$caseWhen},
                title, normalized_title, abstract_text,
                year, document_type, doi, pmid, isbn, issn, url, language,
                source_title, volume, issue, page_start, page_end, publisher,
                cited_by, local_citations, open_access_status, publication_stage,
                external_id, source, hash, countries, institutions, `references`,
                qualis, qualis_journal_id, NOW()
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
            'SELECT document_id, keyword_id, original_term
             FROM document_keyword
             WHERE document_id IN (' . implode(',', $oldDocIds) . ')
             ORDER BY document_id',
        );

        if (empty($rows)) return;

        foreach (array_chunk($rows, self::BATCH) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $row) {
                $values[] = '(?, ?, ?)';
                $params[] = $docMap[$row['document_id']];
                $params[] = $row['keyword_id'];
                $params[] = $row['original_term']; // NOT NULL na tabela
            }
            $this->dbal->executeStatement(
                'INSERT INTO document_keyword (document_id, keyword_id, original_term) VALUES '
                . implode(', ', $values),
                $params,
            );
        }
    }

    /**
     * Copia uma tabela de vínculo documento → X trocando apenas o document_id.
     *
     * @param string[]       $columns  colunas copiadas como estão (além de document_id)
     * @param array<int,int> $docMap   old_document_id => new_document_id
     */
    private function bulkCloneDocumentLinks(string $table, array $columns, array $docMap): void
    {
        $cols = implode(', ', $columns);
        $rows = $this->dbal->fetchAllAssociative(
            "SELECT document_id, {$cols} FROM {$table} WHERE document_id IN (" . implode(',', array_keys($docMap)) . ')'
        );

        $placeholders = '(' . implode(', ', array_fill(0, count($columns) + 1, '?')) . ')';
        foreach (array_chunk($rows, self::BATCH) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $row) {
                $values[] = $placeholders;
                $params[] = $docMap[$row['document_id']];
                foreach ($columns as $col) {
                    $params[] = $row[$col];
                }
            }
            $this->dbal->executeStatement(
                "INSERT INTO {$table} (document_id, {$cols}) VALUES " . implode(', ', $values),
                $params,
            );
        }
    }

    /**
     * Copia os grupos de classificação temática (com seus termos).
     *
     * @return array<int,int>  old_group_id => new_group_id
     */
    private function cloneClassificationGroups(int $oldProjectId, int $newProjectId): array
    {
        $map = [];
        $groups = $this->dbal->fetchAllAssociative(
            'SELECT * FROM classification_group WHERE project_id = ? ORDER BY id',
            [$oldProjectId],
        );
        foreach ($groups as $group) {
            $oldId = (int) $group['id'];
            unset($group['id']);
            $group['project_id'] = $newProjectId;
            $this->dbal->insert('classification_group', $this->quoteKeys($group));
            $newId = (int) $this->dbal->lastInsertId();
            $map[$oldId] = $newId;

            $this->dbal->executeStatement(
                'INSERT INTO classification_rule (group_id, term, created_at)
                 SELECT ?, term, created_at FROM classification_rule WHERE group_id = ? ORDER BY id',
                [$newId, $oldId],
            );
        }
        return $map;
    }

    /**
     * @param array<int,int> $docMap    old_document_id => new_document_id
     * @param array<int,int> $groupMap  old_group_id    => new_group_id
     */
    private function cloneDocumentClassifications(int $oldProjectId, int $newProjectId, array $docMap, array $groupMap): void
    {
        $rows = $this->dbal->fetchAllAssociative(
            'SELECT document_id, group_id, matched_term, run_at, manual_override
             FROM document_classification WHERE project_id = ?',
            [$oldProjectId],
        );

        foreach (array_chunk($rows, self::BATCH) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $row) {
                if (!isset($docMap[$row['document_id']])) {
                    continue;
                }
                $values[] = '(?, ?, ?, ?, ?, ?)';
                $params[] = $docMap[$row['document_id']];
                $params[] = $row['group_id'] !== null ? ($groupMap[$row['group_id']] ?? null) : null;
                $params[] = $newProjectId;
                $params[] = $row['matched_term'];
                $params[] = $row['run_at'];
                $params[] = $row['manual_override'];
            }
            if ($values) {
                $this->dbal->executeStatement(
                    'INSERT INTO document_classification (document_id, group_id, project_id, matched_term, run_at, manual_override) VALUES '
                    . implode(', ', $values),
                    $params,
                );
            }
        }
    }

    private function cloneSavedMatrices(int $oldProjectId, int $newProjectId): void
    {
        $rows = $this->dbal->fetchAllAssociative(
            'SELECT * FROM saved_matrix WHERE project_id = ? ORDER BY id',
            [$oldProjectId],
        );
        foreach ($rows as $row) {
            unset($row['id']);
            $row['project_id'] = $newProjectId;
            $this->dbal->insert('saved_matrix', $this->quoteKeys($row));
        }
    }

    /**
     * Lista de "duplicados ignorados" de cada importação, para a cópia bater com as contagens do dataset.
     *
     * @param array<int,int> $datasetMap  old_dataset_id  => new_dataset_id
     * @param array<int,int> $docMap      old_document_id => new_document_id
     */
    private function cloneDatasetSkips(array $datasetMap, array $docMap): void
    {
        if (empty($datasetMap)) {
            return;
        }
        $rows = $this->dbal->fetchAllAssociative(
            'SELECT dataset_id, title, doi, hash, reason, matched_document_id, created_at
             FROM dataset_skip WHERE dataset_id IN (' . implode(',', array_map('intval', array_keys($datasetMap))) . ')
             ORDER BY id',
        );

        foreach (array_chunk($rows, self::BATCH) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $row) {
                $values[] = '(?, ?, ?, ?, ?, ?, ?)';
                $params[] = $datasetMap[$row['dataset_id']];
                $params[] = $row['title'];
                $params[] = $row['doi'];
                $params[] = $row['hash'];
                $params[] = $row['reason'];
                $params[] = $row['matched_document_id'] !== null ? ($docMap[$row['matched_document_id']] ?? null) : null;
                $params[] = $row['created_at'];
            }
            $this->dbal->executeStatement(
                'INSERT INTO dataset_skip (dataset_id, title, doi, hash, reason, matched_document_id, created_at) VALUES '
                . implode(', ', $values),
                $params,
            );
        }
    }

    /** Colunas como `references`/`position` exigem crase no insert do DBAL. */
    private function quoteKeys(array $row): array
    {
        $quoted = [];
        foreach ($row as $col => $val) {
            $quoted['`' . $col . '`'] = $val;
        }
        return $quoted;
    }
}
