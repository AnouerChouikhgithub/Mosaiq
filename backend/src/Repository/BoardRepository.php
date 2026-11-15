<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Board;
use App\Entity\User;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Board> */
class BoardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Board::class);
    }

    /** @return list<Board> */
    public function findRecent(int $limit = 50): array
    {
        return $this->findBy([], ['updatedAt' => 'DESC'], $limit);
    }

    /** @return list<Board> */
    public function findByWorkspace(Workspace $workspace): array
    {
        return $this->findBy(['workspace' => $workspace], ['updatedAt' => 'DESC']);
    }

    /** @return list<Board> */
    public function findAccessibleTo(User $user): array
    {
        return $this->queryAccessible($user)
            ->orderBy('board.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<Board> */
    public function findAccessibleInWorkspace(User $user, Workspace $workspace): array
    {
        return $this->queryAccessible($user)
            ->andWhere('board.workspace = :requestedWorkspace')
            ->setParameter('requestedWorkspace', $workspace)
            ->orderBy('board.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Listing is limited to boards the user belongs to plus, for workspace
     * administrators, every board in their own workspace. Public boards from
     * other workspaces stay reachable through their direct link only: they must
     * not turn the dashboard into a cross-workspace directory.
     */
    private function queryAccessible(User $user): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('board')
            ->andWhere('EXISTS (SELECT membership.role FROM App\\Entity\\BoardMembership membership WHERE membership.board = board AND membership.user = :user) OR (board.workspace = :workspace AND :isAdmin = true)')
            ->setParameter('user', $user)
            ->setParameter('workspace', $user->getWorkspace())
            ->setParameter('isAdmin', $user->canAdministerWorkspace());
    }

    public function findOneByWorkspaceAndTitle(Workspace $workspace, string $title): ?Board
    {
        return $this->findOneBy(['workspace' => $workspace, 'title' => trim($title)]);
    }
}
