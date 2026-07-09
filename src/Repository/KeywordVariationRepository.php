<?php

namespace App\Repository;

use App\Entity\KeywordVariation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<KeywordVariation>
 */
class KeywordVariationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, KeywordVariation::class);
    }
}
