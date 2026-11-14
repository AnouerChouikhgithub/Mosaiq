<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => strtolower(trim($email))]);
    }

    public function findByApiTokenHash(string $tokenHash): ?User
    {
        return $this->findOneBy(['apiTokenHash' => $tokenHash]);
    }

    /** @return list<User> */
    public function findByWorkspace(Workspace $workspace): array
    {
        return $this->findBy(['workspace' => $workspace], ['name' => 'ASC']);
    }
}
