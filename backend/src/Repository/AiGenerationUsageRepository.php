<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AiGenerationUsage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AiGenerationUsage> */
class AiGenerationUsageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AiGenerationUsage::class);
    }

    /**
     * Increments one window and returns the new count.
     *
     * A single `INSERT ... ON CONFLICT ... RETURNING` avoids a check-then-write
     * race, so parallel PHP workers cannot both observe "under the limit" and
     * spend the last slot twice. Postgres is the only supported database in this
     * project (docker-compose and CI); this is not portable to MySQL.
     */
    public function consume(string $scope, string $windowKey, \DateTimeImmutable $expiresAt): int
    {
        $count = $this->getEntityManager()->getConnection()->fetchOne(
            'INSERT INTO ai_generation_usage (scope, window_key, used_count, expires_at)
             VALUES (:scope, :windowKey, 1, :expiresAt)
             ON CONFLICT (scope, window_key)
             DO UPDATE SET used_count = ai_generation_usage.used_count + 1
             RETURNING used_count',
            ['scope' => $scope, 'windowKey' => $windowKey, 'expiresAt' => $expiresAt],
            ['expiresAt' => Types::DATETIMETZ_IMMUTABLE],
        );

        return (int) $count;
    }

    /** Removes counters whose window closed, so the table stays tiny. */
    public function deleteExpired(\DateTimeImmutable $now): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM ai_generation_usage WHERE expires_at < :now',
            ['now' => $now],
            ['now' => Types::DATETIMETZ_IMMUTABLE],
        );
    }
}
