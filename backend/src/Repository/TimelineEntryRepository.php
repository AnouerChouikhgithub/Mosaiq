<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Board;
use App\Entity\TimelineEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TimelineEntry>
 */
class TimelineEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TimelineEntry::class);
    }

    /** @return list<TimelineEntry> */
    public function findByBoard(Board $board): array
    {
        return $this->findBy(['board' => $board], ['at' => 'ASC']);
    }
}
