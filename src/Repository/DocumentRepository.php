<?php
namespace App\Repository;
use App\Entity\Document;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
/** @extends ServiceEntityRepository<Document> */
class DocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, Document::class); }
    public function findByProject(int $projectId, int $page = 1, int $limit = 30): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.project = :pid')->setParameter('pid', $projectId)
            ->orderBy('d.year', 'DESC')->addOrderBy('d.citedBy', 'DESC')
            ->setFirstResult(($page-1)*$limit)->setMaxResults($limit)
            ->getQuery()->getResult();
    }
    public function countByProject(int $projectId): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')->where('d.project = :pid')->setParameter('pid', $projectId)
            ->getQuery()->getSingleScalarResult();
    }
    public function findByDataset(int $datasetId, int $page = 1, int $limit = 50): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.dataset = :did')->setParameter('did', $datasetId)
            ->orderBy('d.year', 'DESC')->addOrderBy('d.citedBy', 'DESC')
            ->setFirstResult(($page-1)*$limit)->setMaxResults($limit)
            ->getQuery()->getResult();
    }
    public function countByDataset(int $datasetId): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')->where('d.dataset = :did')->setParameter('did', $datasetId)
            ->getQuery()->getSingleScalarResult();
    }

    public function findByDoi(int $projectId, string $doi): ?Document
    {
        return $this->createQueryBuilder('d')
            ->where('d.project = :pid AND d.doi = :doi')
            ->setParameter('pid', $projectId)->setParameter('doi', $doi)
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }
    public function findByHash(int $projectId, string $hash): ?Document
    {
        return $this->createQueryBuilder('d')
            ->where('d.project = :pid AND d.hash = :hash')
            ->setParameter('pid', $projectId)->setParameter('hash', $hash)
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }
}
