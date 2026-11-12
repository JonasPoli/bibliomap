<?php

namespace App\Service;

use App\Entity\BibliometricProject;
use App\Entity\Dataset;
use App\Entity\User;
use App\Service\Authority\AuthorityResolver;
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
 *  4. DBAL – tabela temporária old_doc_id → new_doc_id (posição na ordem de id, como a inserção é feita)
 *  5. DBAL – INSERT … SELECT … JOIN da tabela temporária para document_author e document_keyword
 *  6. DBAL – vínculos geográficos/institucionais, classificação temática,
 *            matrizes salvas e duplicados ignorados (sem eles os relatórios da cópia saem vazios)
 *
 * Author and Keyword rows are global (no project FK) → reused as-is.
 */
class ProjectCopyService
{
    private const BATCH = 500; // rows per INSERT batch

    /** @var list<array{source: string, dest: string, datasetId: int}> cópias de arquivo a fazer depois do commit */
    private array $pendingCopies = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection             $dbal,
        private readonly SluggerInterface       $slugger,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%kernel.project_dir%')]
        private readonly string                 $projectDir,
        private ?AuthorityResolver              $authority = null,
    ) {}

    /**
     * @throws \Throwable
     */
    public function copy(BibliometricProject $source, User $targetUser): BibliometricProject
    {
        $this->pendingCopies = [];
        $this->em->beginTransaction();
        try {
            // ── 1. Clone project ──────────────────────────────────────────────
            $newProject = $this->cloneProject($source, $targetUser);
            $this->em->persist($newProject);
            $this->em->flush(); // get new project ID

            // ── 2. Clone datasets (arquivos são copiados depois da confirmação) ─
            // Map: old_dataset_id => new_dataset_id
            $datasetMap = $this->cloneDatasets($source, $newProject);
            $this->em->flush();

            // ── 3. Bulk clone documents ───────────────────────────────────────
            // Cria a tabela temporária tmp_doc_map (old_id, new_id)
            $docCount = $this->bulkCloneDocuments(
                $source->getId(),
                $newProject->getId(),
                $datasetMap,
            );

            // ── 4. Bulk clone document_author + document_keyword ──────────────
            if ($docCount > 0) {
                $this->bulkCloneDocumentAuthors();
                $this->bulkCloneDocumentKeywords();
                // As colunas efetivas vão copiadas; o recálculo do projeto novo só grava onde diferem (mapeamentos
                // do projeto de origem não acompanham a cópia, os do usuário dono e os globais valem para o destino)
                $authority = $this->authority ??= new AuthorityResolver($this->dbal);
                $authority->refreshLinks(AuthorityResolver::ENTITY_AUTHOR, 'd.project_id = ?', [$newProject->getId()]);
                $authority->refreshLinks(AuthorityResolver::ENTITY_KEYWORD, 'd.project_id = ?', [$newProject->getId()]);

                // Vínculos gerados pela sincronização (mapas, instituições)
                $this->bulkCloneDocumentLinks('documento_paises', ['country_id']);
                $this->bulkCloneDocumentLinks('documento_estados', ['state_id']);
                $this->bulkCloneDocumentLinks('documento_cidades', ['city_id']);
                $this->bulkCloneDocumentLinks('documento_instituicoes', ['institution_id', 'link_type']);
            }

            // ── 5. Classificação temática, matrizes salvas, duplicados ────────
            $groupMap = $this->cloneClassificationGroups($source->getId(), $newProject->getId());
            if ($docCount > 0) {
                $this->cloneDocumentClassifications($source->getId(), $newProject->getId(), $groupMap);
            }
            $this->cloneSavedMatrices($source->getId(), $newProject->getId());
            $this->cloneDatasetSkips($datasetMap);

            $this->dropMapTables();
            $this->em->commit();
        } catch (\Throwable $e) {
            $this->dropMapTables();
            $this->em->rollback();
            $this->pendingCopies = [];
            throw $e;
        }

        // ── 6. Arquivos, fora da transação: copiar arquivo grande não segura bloqueios do banco ──
        $this->copyPendingFiles();

        return $newProject;
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
            if ($newDs->getFilePath() !== $ds->getFilePath()) {
                $this->pendingCopies[] = ['source' => $ds->getFilePath(), 'dest' => $newDs->getFilePath(), 'datasetId' => $newDs->getId()];
            }
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

        // O arquivo físico é copiado depois do commit (copyPendingFiles); aqui só se define o destino,
        // na pasta do projeto novo. Arquivo ausente: mantém a referência ao caminho original.
        $newPath = $this->planFileCopy($source->getFilePath(), $newProject);
        $newDs->setFilePath($newPath);

        return $newDs;
    }

    /** Caminho do arquivo na pasta do projeto novo (antes ia para a do projeto de origem: apagar um levava o arquivo do outro). */
    private function planFileCopy(string $sourcePath, BibliometricProject $newProject): string
    {
        if (!file_exists($sourcePath)) {
            return $sourcePath; // keep original path reference if file missing
        }

        $ext = pathinfo($sourcePath, PATHINFO_EXTENSION);

        return $this->projectDir . '/var/uploads/' . $newProject->getId() . '/' . bin2hex(random_bytes(8)) . ($ext ? '.' . $ext : '');
    }

    /**
     * Copia os arquivos dos datasets depois do commit. Se uma cópia falhar, o dataset volta a
     * apontar para o arquivo original (como no caso de arquivo ausente) e o erro vai ao log do PHP.
     */
    private function copyPendingFiles(): void
    {
        foreach ($this->pendingCopies as $job) {
            $dir = dirname($job['dest']);
            $ok = (is_dir($dir) || @mkdir($dir, 0755, true) || is_dir($dir)) && @copy($job['source'], $job['dest']);
            if ($ok) {
                continue;
            }
            @unlink($job['dest']);
            $this->dbal->executeStatement('UPDATE dataset SET file_path = ? WHERE id = ?', [$job['source'], $job['datasetId']]);
            error_log(sprintf('ProjectCopyService: não foi possível copiar %s; o dataset #%d aponta para o original.', $job['source'], $job['datasetId']));
        }
        $this->pendingCopies = [];
    }

    // ── Tabelas temporárias de correspondência (antigo → novo) ───────────────

    private function createMapTable(string $name): void
    {
        $this->dbal->executeStatement("DROP TEMPORARY TABLE IF EXISTS {$name}");
        $this->dbal->executeStatement("CREATE TEMPORARY TABLE {$name} (old_id INT NOT NULL PRIMARY KEY, new_id INT NOT NULL)");
    }

    /** @param array<int,int> $map */
    private function fillMapTable(string $name, array $map): void
    {
        $this->createMapTable($name);
        foreach (array_chunk($map, self::BATCH, true) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $old => $new) {
                $values[] = '(?, ?)';
                $params[] = (int) $old;
                $params[] = (int) $new;
            }
            $this->dbal->executeStatement("INSERT INTO {$name} (old_id, new_id) VALUES " . implode(', ', $values), $params);
        }
    }

    private function dropMapTables(): void
    {
        foreach (['tmp_doc_map', 'tmp_group_map', 'tmp_dataset_map'] as $name) {
            try {
                $this->dbal->executeStatement("DROP TEMPORARY TABLE IF EXISTS {$name}");
            } catch (\Throwable) {}
        }
    }

    /**
     * Bulk-inserts documents via DBAL INSERT … SELECT e deixa a tabela temporária tmp_doc_map
     * (old_id → new_id). Devolve quantos documentos foram copiados.
     *
     * @param  array<int,int> $datasetMap  old_dataset_id => new_dataset_id
     */
    private function bulkCloneDocuments(int $oldProjectId, int $newProjectId, array $datasetMap): int
    {
        // Build the CASE WHEN for dataset_id remapping
        $caseWhen = 'dataset_id'; // default: keep null as-is
        if (!empty($datasetMap)) {
            $cases = [];
            foreach ($datasetMap as $oldId => $newId) {
                $cases[] = 'WHEN ' . (int) $oldId . ' THEN ' . (int) $newId;
            }
            $caseWhen = 'CASE dataset_id ' . implode(' ', $cases) . ' ELSE dataset_id END';
        }

        // Bulk INSERT via INSERT … SELECT
        // Note: id (auto-increment) is omitted → DB assigns new sequential IDs
        $copied = (int) $this->dbal->executeStatement(
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

        // Correspondência antigo → novo: o n-ésimo documento do projeto de origem (por id) é o
        // n-ésimo do projeto novo, pois a inserção acima preservou a ordem.
        $this->createMapTable('tmp_doc_map');
        $this->dbal->executeStatement(
            'INSERT INTO tmp_doc_map (old_id, new_id)
             SELECT o.id, n.id
             FROM (SELECT id, ROW_NUMBER() OVER (ORDER BY id) AS rn FROM document WHERE project_id = ?) o
             JOIN (SELECT id, ROW_NUMBER() OVER (ORDER BY id) AS rn FROM document WHERE project_id = ?) n ON n.rn = o.rn',
            [$oldProjectId, $newProjectId],
        );

        return $copied;
    }

    private function bulkCloneDocumentAuthors(): void
    {
        $this->dbal->executeStatement(
            'INSERT INTO document_author (document_id, author_identity_id, position, original_name, effective_author_id)
             SELECT m.new_id, da.author_identity_id, da.position, da.original_name, da.effective_author_id
             FROM document_author da
             JOIN tmp_doc_map m ON m.old_id = da.document_id
             ORDER BY da.document_id, da.position, da.id'
        );
    }

    private function bulkCloneDocumentKeywords(): void
    {
        $this->dbal->executeStatement(
            'INSERT INTO document_keyword (document_id, keyword_id, original_term, effective_keyword_id)
             SELECT m.new_id, dk.keyword_id, dk.original_term, dk.effective_keyword_id
             FROM document_keyword dk
             JOIN tmp_doc_map m ON m.old_id = dk.document_id
             ORDER BY dk.document_id, dk.id'
        );
    }

    /**
     * Copia uma tabela de vínculo documento → X trocando apenas o document_id.
     *
     * @param string[] $columns  colunas copiadas como estão (além de document_id)
     */
    private function bulkCloneDocumentLinks(string $table, array $columns): void
    {
        $cols = implode(', ', $columns);
        $srcCols = implode(', ', array_map(fn (string $c) => 't.' . $c, $columns));
        $this->dbal->executeStatement(
            "INSERT INTO {$table} (document_id, {$cols})
             SELECT m.new_id, {$srcCols}
             FROM {$table} t
             JOIN tmp_doc_map m ON m.old_id = t.document_id"
        );
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
     * @param array<int,int> $groupMap  old_group_id => new_group_id
     */
    private function cloneDocumentClassifications(int $oldProjectId, int $newProjectId, array $groupMap): void
    {
        $this->fillMapTable('tmp_group_map', $groupMap);
        $this->dbal->executeStatement(
            'INSERT INTO document_classification (document_id, group_id, project_id, matched_term, run_at, manual_override)
             SELECT m.new_id, g.new_id, ?, dc.matched_term, dc.run_at, dc.manual_override
             FROM document_classification dc
             JOIN tmp_doc_map m ON m.old_id = dc.document_id
             LEFT JOIN tmp_group_map g ON g.old_id = dc.group_id
             WHERE dc.project_id = ?',
            [$newProjectId, $oldProjectId],
        );
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
     */
    private function cloneDatasetSkips(array $datasetMap): void
    {
        if (empty($datasetMap)) {
            return;
        }
        $this->fillMapTable('tmp_dataset_map', $datasetMap);
        $this->dbal->executeStatement(
            'INSERT INTO dataset_skip (dataset_id, title, doi, hash, reason, matched_document_id, created_at)
             SELECT dm.new_id, s.title, s.doi, s.hash, s.reason, m.new_id, s.created_at
             FROM dataset_skip s
             JOIN tmp_dataset_map dm ON dm.old_id = s.dataset_id
             LEFT JOIN tmp_doc_map m ON m.old_id = s.matched_document_id
             ORDER BY s.id'
        );
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
