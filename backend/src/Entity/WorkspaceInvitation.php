<?php

declare(strict_types=1);

namespace App\Entity;

use App\Id\UuidGenerator;
use App\Repository\WorkspaceInvitationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WorkspaceInvitationRepository::class)]
#[ORM\Table(name: 'workspace_invitations')]
#[ORM\Index(name: 'idx_workspace_invitations_workspace', columns: ['workspace_id', 'created_at'])]
#[ORM\Index(name: 'idx_workspace_invitations_email', columns: ['workspace_id', 'email'])]
#[ORM\Index(name: 'idx_workspace_invitations_expiry', columns: ['expires_at'])]
#[ORM\UniqueConstraint(name: 'uniq_workspace_invitation_token_hash', columns: ['token_hash'])]
class WorkspaceInvitation
{
    public const ROLES = ['Admin', 'Editor', 'Commenter', 'Viewer'];
    public const PENDING = 'pending';
    public const ACCEPTED = 'accepted';
    public const REVOKED = 'revoked';
    public const EXPIRED = 'expired';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(name: 'workspace_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Workspace $workspace;

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

    #[ORM\Column(name: 'revoked_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(
        Workspace $workspace,
        User $invitedBy,
        string $email,
        string $role,
        string $tokenHash,
        ?\DateTimeImmutable $now = null,
    ) {
        $email = strtolower(trim($email));
        if (false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invitation email is invalid.');
        }
        if (!in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException('Workspace invitation role is not supported.');
        }
        if (1 !== preg_match('/^[a-f0-9]{64}$/D', $tokenHash)) {
            throw new \InvalidArgumentException('Invitation token hash is invalid.');
        }
        if (!$invitedBy->hasActiveWorkspaceMembership() || $invitedBy->getWorkspace()->getId() !== $workspace->getId()) {
            throw new \InvalidArgumentException('Inviter does not belong to the workspace.');
        }

        $this->workspace = $workspace;
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

    public function getWorkspace(): Workspace
    {
        return $this->workspace;
    }

    public function getInvitedBy(): User
    {
        return $this->invitedBy;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->acceptedAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function matchesEmail(string $email): bool
    {
        return hash_equals($this->email, strtolower(trim($email)));
    }

    public function isPendingAt(?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();

        return null === $this->acceptedAt
            && null === $this->revokedAt
            && $this->expiresAt > $now;
    }

    public function statusAt(?\DateTimeImmutable $now = null): string
    {
        if (null !== $this->acceptedAt) {
            return self::ACCEPTED;
        }
        if (null !== $this->revokedAt) {
            return self::REVOKED;
        }

        return $this->expiresAt > ($now ?? new \DateTimeImmutable()) ? self::PENDING : self::EXPIRED;
    }

    public function accept(?\DateTimeImmutable $now = null): void
    {
        if (!$this->isPendingAt($now)) {
            throw new \InvalidArgumentException('Invitation is expired, revoked or already accepted.');
        }
        $this->acceptedAt = $now ?? new \DateTimeImmutable();
    }

    public function revoke(?\DateTimeImmutable $now = null): void
    {
        if (!$this->isPendingAt($now)) {
            throw new \InvalidArgumentException('Invitation is expired, revoked or already accepted.');
        }
        $this->revokedAt = $now ?? new \DateTimeImmutable();
    }
}
