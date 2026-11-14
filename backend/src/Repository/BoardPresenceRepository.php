<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Board;
use App\Entity\BoardPresence;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<BoardPresence> */
final class BoardPresenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BoardPresence::class);
    }

    public function findForBoardAndUser(Board $board, User $user): ?BoardPresence
    {
        return $this->findOneBy(['board' => $board, 'user' => $user]);
    }

    /** @return list<BoardPresence> */
    public function findRecentlyActive(Board $board, \DateTimeImmutable $since): array
    {
        return $this->createQueryBuilder('presence')
            ->andWhere('presence.board = :board')
            ->andWhere('presence.updatedAt >= :since')
            ->setParameter('board', $board)
            ->setParameter('since', $since)
            ->orderBy('presence.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}