<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BoardInvitation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<BoardInvitation> */
final class BoardInvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BoardInvitation::class);
    }

    public function findByToken(string $token): ?BoardInvitation
    {
        return $this->findOneBy(['tokenHash' => hash('sha256', $token)]);
    }
}
