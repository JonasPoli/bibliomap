<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    /** @return User[] */
    public function findPending(): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.status = :status')
            ->setParameter('status', User::STATUS_PENDING)
            ->orderBy('u.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns counts grouped by status.
     * @return array{pending: int, active: int, inactive: int, total: int}
     */
    public function countByStatus(): array
    {
        $rows = $this->createQueryBuilder('u')
            ->select('u.status, COUNT(u.id) as cnt')
            ->groupBy('u.status')
            ->getQuery()
            ->getResult();

        $counts = ['pending' => 0, 'active' => 0, 'inactive' => 0, 'total' => 0];
        foreach ($rows as $row) {
            $counts[$row['status']] = (int) $row['cnt'];
            $counts['total']       += (int) $row['cnt'];
        }
        return $counts;
    }

    /**
     * Full-featured filtered search for the admin panel.
     *
     * @return User[]
     */
    public function findWithFilters(
        string $search  = '',
        string $status  = 'all',
        string $period  = 'all',
        string $sort    = 'newest',
    ): array {
        $qb = $this->createQueryBuilder('u');

        // Search by name, email or institution
        if ($search !== '') {
            $qb->andWhere('u.name LIKE :search OR u.email LIKE :search OR u.institution LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }

        // Filter by status
        if ($status !== 'all') {
            $qb->andWhere('u.status = :status')
               ->setParameter('status', $status);
        }

        // Filter by registration period
        if ($period !== 'all') {
            $now  = new \DateTimeImmutable();
            $from = match ($period) {
                '24h'  => $now->modify('-24 hours'),
                '7d'   => $now->modify('-7 days'),
                '30d'  => $now->modify('-30 days'),
                default => null,
            };
            if ($from !== null) {
                $qb->andWhere('u.createdAt >= :from')
                   ->setParameter('from', $from);
            }
        }

        // Sort
        match ($sort) {
            'oldest'      => $qb->orderBy('u.createdAt', 'ASC'),
            'name_asc'    => $qb->orderBy('u.name', 'ASC'),
            'name_desc'   => $qb->orderBy('u.name', 'DESC'),
            'last_login'  => $qb->orderBy('u.lastLoginAt', 'DESC'),
            default       => $qb->orderBy('u.createdAt', 'DESC'),
        };

        return $qb->getQuery()->getResult();
    }

    /**
     * Returns all active users except the given one, sorted by name.
     * Used to populate the "copy project to" user selector.
     *
     * @return User[]
     */
    public function findActiveUsersExcept(int $excludeId): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.status = :status')
            ->setParameter('status', User::STATUS_ACTIVE)
            ->andWhere('u.id != :excludeId')
            ->setParameter('excludeId', $excludeId)
            ->orderBy('u.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}

