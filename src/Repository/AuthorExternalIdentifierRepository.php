<?php

namespace App\Repository;

use App\Entity\AuthorExternalIdentifier;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuthorExternalIdentifier>
 */
class AuthorExternalIdentifierRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuthorExternalIdentifier::class);
    }
}
