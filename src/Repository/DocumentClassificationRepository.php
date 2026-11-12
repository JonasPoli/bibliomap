<?php

namespace App\Repository;

use App\Entity\DocumentClassification;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<DocumentClassification> */
class DocumentClassificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentClassification::class);
    }

    /** @return DocumentClassification[] */
    public function findByProjectAndGroup(int $projectId, ?int $groupId, int $page = 1, int $limit = 100): array
    {
        $qb = $this->createQueryBuilder('dc')
            ->join('dc.document', 'd')
            ->where('dc.project = :pid')
            ->setParameter('pid', $projectId)
            ->orderBy('d.citedBy', 'DESC')
            ->addOrderBy('d.year', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        if ($groupId === null) {
            $qb->andWhere('dc.group IS NULL');
        } else {
            $qb->andWhere('dc.group = :gid')->setParameter('gid', $groupId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Página de resultados já com tudo o que a tela mostra, em poucas consultas (sem carregar
     * Document pelo ORM: o resumo e as referências não vêm duplicados e não há N+1).
     * Ordem: citações, ano e, para a paginação não repetir nem pular linhas empatadas, o id.
     *
     * @return list<array{id:int, matchedTerm:?string, manualOverride:bool, document:array<string,mixed>}>
     */
    public function findPageForDisplay(int $projectId, ?int $groupId, int $page = 1, int $limit = 100): array
    {
        $conn   = $this->getEntityManager()->getConnection();
        $params = [$projectId];
        $where  = 'dc.project_id = ?';
        if ($groupId === null) {
            $where .= ' AND dc.group_id IS NULL';
        } else {
            $where .= ' AND dc.group_id = ?';
            $params[] = $groupId;
        }

        $rows = $conn->fetchAllAssociative(
            'SELECT dc.id AS clf_id, dc.matched_term, dc.manual_override,
                    d.id AS doc_id, d.title, d.year, d.source_title, d.document_type, d.cited_by, d.publisher,
                    d.qualis, d.doi, d.url, d.issn, d.isbn, d.volume, d.issue, d.page_start, d.page_end, d.abstract_text
             FROM document_classification dc
             JOIN document d ON d.id = dc.document_id
             WHERE ' . $where . '
             ORDER BY d.cited_by DESC, d.year DESC, d.id ASC, dc.id ASC
             LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, ($page - 1) * $limit),
            $params
        );
        if (!$rows) {
            return [];
        }

        $docIds = array_values(array_unique(array_map(fn($r) => (int) $r['doc_id'], $rows)));
        $idType = [\Doctrine\DBAL\ArrayParameterType::INTEGER];

        // Autores na ordem da publicação (igual a Document::getAuthorsString)
        $authors = [];
        foreach ($conn->fetchAllAssociative(
            'SELECT da.document_id, ai.preferred_name
             FROM document_author da
             JOIN author_identity ai ON ai.id = da.effective_author_id
             WHERE da.document_id IN (?) AND ' . \App\Service\Analytics\ReportService::firstEffectiveAuthorFilter('da') . '
             ORDER BY da.document_id, da.position ASC, da.id ASC',
            [$docIds],
            $idType
        ) as $a) {
            $authors[(int) $a['document_id']][] = $a['preferred_name'];
        }

        // Palavras-chave: a forma tratada, senão a original (igual a Keyword::getTerm)
        $keywords = [];
        foreach ($conn->fetchAllAssociative(
            'SELECT dk.document_id, COALESCE(k.keyword_display, k.keyword_original) AS term
             FROM document_keyword dk
             JOIN keyword k ON k.id = dk.keyword_id
             WHERE dk.document_id IN (?)
             ORDER BY dk.document_id, dk.id ASC',
            [$docIds],
            $idType
        ) as $k) {
            $keywords[(int) $k['document_id']][] = $k['term'];
        }

        $result = [];
        foreach ($rows as $r) {
            $id = (int) $r['doc_id'];
            $result[] = [
                'id'             => (int) $r['clf_id'],
                'matchedTerm'    => $r['matched_term'],
                'manualOverride' => (bool) $r['manual_override'],
                'document'       => [
                    'id'            => $id,
                    'title'         => $r['title'],
                    'year'          => $r['year'],
                    'sourceTitle'   => $r['source_title'],
                    'documentType'  => $r['document_type'],
                    'citedBy'       => $r['cited_by'],
                    'publisher'     => $r['publisher'],
                    'qualis'        => $r['qualis'],
                    'doi'           => $r['doi'],
                    'url'           => $r['url'],
                    'issn'          => $r['issn'],
                    'isbn'          => $r['isbn'],
                    'volume'        => $r['volume'],
                    'issue'         => $r['issue'],
                    'pageStart'     => $r['page_start'],
                    'pageEnd'       => $r['page_end'],
                    'abstractText'  => $r['abstract_text'],
                    'authorsString' => implode('; ', $authors[$id] ?? []),
                    'keywordTerms'  => $keywords[$id] ?? [],
                ],
            ];
        }

        return $result;
    }

    /**
     * Nomes dos grupos de cada documento (uma consulta para a página inteira).
     *
     * @param int[] $documentIds
     * @return array<int, string[]> documentId => nomes de grupo ("Sem Classificação" quando o grupo é nulo)
     */
    public function findGroupNamesByDocuments(array $documentIds, int $projectId): array
    {
        if (!$documentIds) {
            return [];
        }
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT dc.document_id, g.name
             FROM document_classification dc
             LEFT JOIN classification_group g ON g.id = dc.group_id
             WHERE dc.document_id IN (?) AND dc.project_id = ?
             ORDER BY dc.document_id, g.position ASC, dc.id ASC',
            [array_values(array_unique(array_map('intval', $documentIds))), $projectId],
            [\Doctrine\DBAL\ArrayParameterType::INTEGER, \Doctrine\DBAL\ParameterType::INTEGER]
        );

        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['document_id']][] = $r['name'] ?? 'Sem Classificação';
        }

        return $map;
    }

    public function countByProjectAndGroup(int $projectId, ?int $groupId): int
    {
        $qb = $this->createQueryBuilder('dc')
            ->select('COUNT(dc.id)')
            ->where('dc.project = :pid')
            ->setParameter('pid', $projectId);

        if ($groupId === null) {
            $qb->andWhere('dc.group IS NULL');
        } else {
            $qb->andWhere('dc.group = :gid')->setParameter('gid', $groupId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return array<int, int> groupId => count (null key = unclassified) */
    public function countByGroup(int $projectId): array
    {
        $rows = $this->createQueryBuilder('dc')
            ->select('IDENTITY(dc.group) AS gid, COUNT(dc.id) AS cnt')
            ->where('dc.project = :pid')
            ->setParameter('pid', $projectId)
            ->groupBy('dc.group')
            ->getQuery()
            ->getScalarResult();

        $result = [];
        foreach ($rows as $r) {
            $result[$r['gid'] ?? 0] = (int) $r['cnt'];
        }
        return $result;
    }

    /** Count distinct documents (not classification rows) */
    public function countDistinctDocuments(int $projectId): int
    {
        return (int) $this->createQueryBuilder('dc')
            ->select('COUNT(DISTINCT dc.document)')
            ->where('dc.project = :pid')
            ->setParameter('pid', $projectId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Get all group names for a given document in a project.
     * @return string[] group names
     */
    public function findGroupNamesByDocument(int $documentId, int $projectId): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT g.name
             FROM document_classification dc
             LEFT JOIN classification_group g ON g.id = dc.group_id
             WHERE dc.document_id = ? AND dc.project_id = ?
             ORDER BY g.position ASC',
            [$documentId, $projectId]
        );

        return array_map(fn($r) => $r['name'] ?? 'Sem Classificação', $rows);
    }

    public function deleteByProject(int $projectId): void
    {
        $this->createQueryBuilder('dc')
            ->delete()
            ->where('dc.project = :pid')
            ->setParameter('pid', $projectId)
            ->getQuery()
            ->execute();
    }

    public function hasResults(int $projectId): bool
    {
        return (bool) $this->createQueryBuilder('dc')
            ->select('COUNT(dc.id)')
            ->where('dc.project = :pid')
            ->setParameter('pid', $projectId)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
