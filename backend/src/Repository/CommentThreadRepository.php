<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Board;
use App\Entity\CommentThread;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CommentThread>
 */
class CommentThreadRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CommentThread::class);
    }

    /** @return list<CommentThread> */
    public function findByBoard(Board $board): array
    {
        return $this->findBy(['board' => $board], ['createdAt' => 'ASC']);
    }
}
