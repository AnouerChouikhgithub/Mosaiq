<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Board;
use App\Entity\CanvasElement;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CanvasElement>
 */
class CanvasElementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CanvasElement::class);
    }

    /** @return list<CanvasElement> */
    public function findByBoard(Board $board): array
    {
        return $this->findBy(['board' => $board], ['createdAt' => 'ASC']);
    }

    /** @return list<CanvasElement> */
    public function findByBoardAndType(Board $board, string $type): array
    {
        return $this->findBy(['board' => $board, 'type' => $type], ['createdAt' => 'ASC']);
    }

    /**
     * Looks up the element a create operation already produced. The key is
     * scoped to the board and the author, so another user or another board can
     * never observe or reuse this operation.
     */
    public function findByMutation(Board $board, User $author, string $mutationId): ?CanvasElement
    {
        return $this->findOneBy([
            'board' => $board,
            'author' => $author,
            'mutationId' => $mutationId,
        ]);
    }
}
