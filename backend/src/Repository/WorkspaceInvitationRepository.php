<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Workspace;
use App\Entity\WorkspaceInvitation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WorkspaceInvitation> */
final class WorkspaceInvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkspaceInvitation::class);
    }

    public function findByToken(string $token): ?WorkspaceInvitation
    {
        return $this->findOneBy(['tokenHash' => hash('sha256', $token)]);
    }

    public function findPendingFor(Workspace $workspace, string $email): ?WorkspaceInvitation
    {
        return $this->createQueryBuilder('invitation')
            ->andWhere('invitation.workspace = :workspace')
            ->andWhere('LOWER(invitation.email) = :email')
            ->andWhere('invitation.acceptedAt IS NULL')
            ->andWhere('invitation.revokedAt IS NULL')
            ->andWhere('invitation.expiresAt > :now')
            ->setParameter('workspace', $workspace)
            ->setParameter('email', strtolower(trim($email)))
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('invitation.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<WorkspaceInvitation> */
    public function findForWorkspace(Workspace $workspace): array
    {
        return $this->findBy(['workspace' => $workspace], ['createdAt' => 'DESC']);
    }
}
