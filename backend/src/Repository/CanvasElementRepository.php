<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Board;
use App\Entity\CanvasElement;
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
}
