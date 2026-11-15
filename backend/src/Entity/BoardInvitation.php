<?php

declare(strict_types=1);

namespace App\Entity;

use App\Id\UuidGenerator;
use App\Repository\BoardInvitationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BoardInvitationRepository::class)]
#[ORM\Table(name: 'board_invitations')]
#[ORM\Index(name: 'idx_board_invitations_board', fields: ['board'])]
#[ORM\UniqueConstraint(name: 'uniq_board_invitation_token_hash', columns: ['token_hash'])]
class BoardInvitation
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
    #[ORM\JoinColumn(name: 'invited_by_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $invitedBy;

    #[ORM\Column(length: 320)]
    private string $email;

    #[ORM\Column(length: 16)]
    private string $role;

    #[ORM\Column(name: 'token_hash', length: 64)]
    private string $tokenHash;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'expires_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'accepted_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $acceptedAt = null;

    public function __construct(Board $board, User $invitedBy, string $email, string $role, string $tokenHash, ?\DateTimeImmutable $now = null)
    {
        $email = strtolower(trim($email));
        if (false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invitation email is invalid.');
        }
        if (!in_array($role, ['Viewer', 'Commenter', 'Editor'], true)) {
            throw new \InvalidArgumentException('Invitation role is not supported.');
        }
        if (1 !== preg_match('/^[a-f0-9]{64}$/', $tokenHash)) {
            throw new \InvalidArgumentException('Invitation token hash is invalid.');
        }
        $this->board = $board;
        $this->invitedBy = $invitedBy;
        $this->email = $email;
        $this->role = $role;
        $this->tokenHash = $tokenHash;
        $this->createdAt = $now ?? new \DateTimeImmutable();
        $this->expiresAt = $this->createdAt->modify('+7 days');
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getBoard(): Board
    {
        return $this->board;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->acceptedAt;
    }

    public function matchesEmail(string $email): bool
    {
        return hash_equals($this->email, strtolower(trim($email)));
    }

    public function isPendingAt(?\DateTimeImmutable $now = null): bool
    {
        return null === $this->acceptedAt && $this->expiresAt > ($now ?? new \DateTimeImmutable());
    }

    public function accept(?\DateTimeImmutable $now = null): void
    {
        if (!$this->isPendingAt($now)) {
            throw new \InvalidArgumentException('Invitation is expired or already accepted.');
        }
        $this->acceptedAt = $now ?? new \DateTimeImmutable();
    }
}
