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
