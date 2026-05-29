<?php
namespace App\Repository;
use App\Entity\DocumentKeyword;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
/** @extends ServiceEntityRepository<DocumentKeyword> */
class DocumentKeywordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, DocumentKeyword::class); }
}
