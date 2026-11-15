<?php

declare(strict_types=1);

namespace App\Entity;

use App\Id\UuidGenerator;
use App\Repository\BoardPresenceRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BoardPresenceRepository::class)]
#[ORM\Table(name: 'board_presence')]
#[ORM\UniqueConstraint(name: 'uniq_board_presence', fields: ['board', 'user'])]
#[ORM\Index(name: 'idx_board_presence_updated', fields: ['updatedAt'])]
class BoardPresence
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Board::class)]
    #[ORM\JoinColumn(name: 'board_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Board $board;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'float')]
    private float $x;

    #[ORM\Column(type: 'float')]
    private float $y;

    #[ORM\Column(length: 24)]
    private string $tool;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Board $board, User $user, float $x, float $y, string $tool, ?\DateTimeImmutable $now = null)
    {
        $this->board = $board;
        $this->user = $user;
        $this->update($x, $y, $tool, $now);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getBoard(): Board
    {
        return $this->board;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getX(): float
    {
        return $this->x;
    }

    public function getY(): float
    {
        return $this->y;
    }

    public function getTool(): string
    {
        return $this->tool;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function update(float $x, float $y, string $tool, ?\DateTimeImmutable $now = null): void
    {
        if (!in_array($tool, ['select', 'note', 'comment', 'shape', 'connector', 'text', 'pen', 'image'], true)) {
            throw new \InvalidArgumentException('Presence tool is not supported.');
        }
        $this->x = $x;
        $this->y = $y;
        $this->tool = $tool;
        $this->updatedAt = $now ?? new \DateTimeImmutable();
    }
}
