<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => strtolower(trim($email))]);
    }

    public function findByApiTokenHash(string $tokenHash): ?User
    {
        return $this->findOneBy(['apiTokenHash' => $tokenHash]);
    }

    /** @return list<User> */
    public function findByWorkspace(Workspace $workspace): array
    {
        return $this->findBy(['workspace' => $workspace], ['name' => 'ASC']);
    }

    /** @return list<User> */
    public function findActiveByWorkspace(Workspace $workspace): array
    {
        return $this->createQueryBuilder('user')
            ->andWhere('user.workspace = :workspace')
            ->andWhere('user.membershipStatus = :active')
            ->setParameter('workspace', $workspace)
            ->setParameter('active', User::STATUS_ACTIVE)
            ->orderBy('user.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countActiveByWorkspace(Workspace $workspace): int
    {
        return (int) $this->createQueryBuilder('user')
            ->select('COUNT(user.id)')
            ->andWhere('user.workspace = :workspace')
            ->andWhere('user.membershipStatus = :active')
            ->setParameter('workspace', $workspace)
            ->setParameter('active', User::STATUS_ACTIVE)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
