<?php

namespace App\Repository;

use App\Entity\ClassificationGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ClassificationGroup> */
class ClassificationGroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClassificationGroup::class);
    }

    /** @return ClassificationGroup[] */
    public function findByProject(int $projectId): array
    {
        return $this->createQueryBuilder('g')
            ->where('g.project = :pid')
            ->setParameter('pid', $projectId)
            ->orderBy('g.position', 'ASC')
            ->addOrderBy('g.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return ClassificationGroup[] ordered: validators first, then noise, then normal, then unclassified */
    public function findByProjectOrdered(int $projectId): array
    {
        $rows = $this->findByProject($projectId);

        usort($rows, function (ClassificationGroup $a, ClassificationGroup $b) {
            $order = [
                ClassificationGroup::TYPE_VALIDATOR    => 0,
                ClassificationGroup::TYPE_NOISE        => 1,
                ClassificationGroup::TYPE_NORMAL       => 2,
                ClassificationGroup::TYPE_UNCLASSIFIED => 3,
            ];
            $ao = $order[$a->getType()] ?? 99;
            $bo = $order[$b->getType()] ?? 99;

            if ($ao !== $bo) return $ao <=> $bo;
            return $a->getPosition() <=> $b->getPosition();
        });

        return $rows;
    }
}
