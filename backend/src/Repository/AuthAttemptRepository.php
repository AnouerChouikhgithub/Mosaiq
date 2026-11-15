<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AuthAttempt;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AuthAttempt> */
class AuthAttemptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuthAttempt::class);
    }

    public function countSince(string $action, string $keyHash, \DateTimeImmutable $since): int
    {
        $count = $this->createQueryBuilder('attempt')
            ->select('COUNT(attempt.id)')
            ->andWhere('attempt.action = :action')
            ->andWhere('attempt.keyHash = :keyHash')
            ->andWhere('attempt.attemptedAt >= :since')
            ->setParameter('action', $action)
            ->setParameter('keyHash', $keyHash)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }

    public function deleteOlderThan(\DateTimeImmutable $before): void
    {
        $this->createQueryBuilder('attempt')
            ->delete()
            ->where('attempt.attemptedAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }

    /** @param list<string> $keyHashes */
    public function deleteFor(string $action, array $keyHashes): void
    {
        if ([] === $keyHashes) {
            return;
        }

        $this->createQueryBuilder('attempt')
            ->delete()
            ->where('attempt.action = :action')
            ->andWhere('attempt.keyHash IN (:keyHashes)')
            ->setParameter('action', $action)
            ->setParameter('keyHashes', $keyHashes)
            ->getQuery()
            ->execute();
    }
}
