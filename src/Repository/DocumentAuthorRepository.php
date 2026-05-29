<?php
namespace App\Repository;
use App\Entity\DocumentAuthor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
/** @extends ServiceEntityRepository<DocumentAuthor> */
class DocumentAuthorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, DocumentAuthor::class); }
}
