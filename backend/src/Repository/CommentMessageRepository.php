<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CommentMessage;
use App\Entity\CommentThread;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CommentMessage>
 */
class CommentMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CommentMessage::class);
    }

    /** @return list<CommentMessage> */
    public function findByThread(CommentThread $thread): array
    {
        return $this->findBy(['thread' => $thread], ['createdAt' => 'ASC']);
    }
}
