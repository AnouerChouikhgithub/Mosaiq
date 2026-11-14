<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'board_members')]
#[ORM\Index(name: 'idx_board_members_user', columns: ['user_id'])]
class BoardMembership
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Board::class, inversedBy: 'memberships')]
    #[ORM\JoinColumn(name: 'board_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Board $board;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 16, options: ['default' => 'Viewer'])]
    private string $role;

    public function __construct(Board $board, User $user, string $role)
    {
        if (!in_array($role, ['Owner', 'Admin', 'Editor', 'Commenter', 'Viewer'], true)) {
            throw new \InvalidArgumentException('Board role is not supported.');
        }
        $this->board = $board;
        $this->user = $user;
        $this->role = $role;
    }

    public function getBoard(): Board
    {
        return $this->board;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function changeRole(string $role): void
    {
        if (!in_array($role, ['Owner', 'Admin', 'Editor', 'Commenter', 'Viewer'], true)) {
            throw new \InvalidArgumentException('Board role is not supported.');
        }
        $this->role = $role;
    }
}
