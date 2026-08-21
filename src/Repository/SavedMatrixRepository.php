<?php

namespace App\Repository;

use App\Entity\SavedMatrix;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SavedMatrix>
 */
class SavedMatrixRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SavedMatrix::class);
    }

    /**
     * @return SavedMatrix[]
     */
    public function findByProject(int $projectId): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.project = :proj')
            ->setParameter('proj', $projectId)
            ->orderBy('m.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
