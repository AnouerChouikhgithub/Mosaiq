<?php

declare(strict_types=1);

namespace App\Entity;

use App\Id\UuidGenerator;
use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\UniqueConstraint(name: 'uniq_users_email', fields: ['email'])]
#[ORM\Index(name: 'idx_users_workspace', fields: ['workspace'])]
#[ORM\Index(name: 'idx_users_workspace_status', fields: ['workspace', 'membershipStatus'])]
#[ORM\UniqueConstraint(name: 'uniq_users_api_token_hash', columns: ['api_token_hash'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const ROLES = ['Owner', 'Admin', 'Editor', 'Commenter', 'Viewer'];
    public const ADMIN_ROLES = ['Owner', 'Admin'];
    public const MEMBER_ROLES = ['Editor', 'Commenter', 'Viewer'];
    public const STATUS_ACTIVE = 'active';
    public const STATUS_REMOVED = 'removed';
    public const MEMBERSHIP_STATUSES = [self::STATUS_ACTIVE, self::STATUS_REMOVED];
    public const ACCENTS = ['cursor-1', 'cursor-2', 'cursor-3'];

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Workspace::class, inversedBy: 'users')]
    #[ORM\JoinColumn(name: 'workspace_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Workspace $workspace;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 320)]
    private string $email;

    #[ORM\Column(length: 16)]
    private string $role;

    #[ORM\Column(name: 'membership_status', length: 16, options: ['default' => self::STATUS_ACTIVE])]
    private string $membershipStatus = self::STATUS_ACTIVE;

    #[ORM\Column(name: 'membership_updated_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $membershipUpdatedAt;

    #[ORM\Column(length: 120)]
    private string $city;

    #[ORM\Column(length: 16)]
    private string $accent;

    #[ORM\Column(name: 'password_hash', length: 255, nullable: true)]
    private ?string $passwordHash = null;

    #[ORM\Column(name: 'api_token_hash', length: 64, nullable: true)]
    private ?string $apiTokenHash = null;

    #[ORM\Column(name: 'api_token_expires_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $apiTokenExpiresAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Workspace $workspace,
        string $email,
        string $name,
        string $role,
        string $city,
        string $accent,
        ?\DateTimeImmutable $now = null,
    ) {
        if (false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('User email is not valid.');
        }
        if ('' === trim($name)) {
            throw new \InvalidArgumentException('User name is required.');
        }
        if (!in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException('User role is not supported.');
        }
        if (!in_array($accent, self::ACCENTS, true)) {
            throw new \InvalidArgumentException('Presence accent is not supported.');
        }

        $this->workspace = $workspace;
        $this->email = strtolower(trim($email));
        $this->name = trim($name);
        $this->role = $role;
        $this->city = trim($city);
        $this->accent = $accent;
        $this->createdAt = $now ?? new \DateTimeImmutable();
        $this->membershipUpdatedAt = $this->createdAt;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getWorkspace(): Workspace
    {
        return $this->workspace;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function getAccent(): string
    {
        return $this->accent;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getMembershipStatus(): string
    {
        return $this->membershipStatus;
    }

    public function getMembershipUpdatedAt(): \DateTimeImmutable
    {
        return $this->membershipUpdatedAt;
    }

    public function hasActiveWorkspaceMembership(): bool
    {
        return self::STATUS_ACTIVE === $this->membershipStatus;
    }

    public function isWorkspaceOwner(): bool
    {
        return $this->hasActiveWorkspaceMembership() && 'Owner' === $this->role;
    }

    public function removeFromWorkspace(?\DateTimeImmutable $now = null): void
    {
        if ('Owner' === $this->role) {
            throw new \InvalidArgumentException('The workspace owner cannot be removed.');
        }
        $this->membershipStatus = self::STATUS_REMOVED;
        $this->membershipUpdatedAt = $now ?? new \DateTimeImmutable();
        $this->revokeApiToken();
    }

    public function getPassword(): ?string
    {
        return $this->passwordHash;
    }

    public function setPasswordHash(string $passwordHash): void
    {
        $this->passwordHash = $passwordHash;
    }

    public function getApiTokenHash(): ?string
    {
        return $this->apiTokenHash;
    }

    public function getApiTokenExpiresAt(): ?\DateTimeImmutable
    {
        return $this->apiTokenExpiresAt;
    }

    public function issueApiToken(string $apiTokenHash, \DateTimeImmutable $expiresAt): void
    {
        $this->apiTokenHash = $apiTokenHash;
        $this->apiTokenExpiresAt = $expiresAt;
    }

    public function revokeApiToken(): void
    {
        $this->apiTokenHash = null;
        $this->apiTokenExpiresAt = null;
    }

    public function hasValidApiToken(?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();

        return null !== $this->apiTokenHash
            && null !== $this->apiTokenExpiresAt
            && $this->apiTokenExpiresAt > $now;
    }

    public function updateProfile(string $name, string $city, string $accent): void
    {
        if ('' === trim($name)) {
            throw new \InvalidArgumentException('User name is required.');
        }
        if (!in_array($accent, self::ACCENTS, true)) {
            throw new \InvalidArgumentException('Presence accent is not supported.');
        }

        $this->name = trim($name);
        $this->city = trim($city);
        $this->accent = $accent;
    }

    public function changeRole(string $role, ?\DateTimeImmutable $now = null): void
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException('User role is not supported.');
        }
        if (!$this->hasActiveWorkspaceMembership()) {
            throw new \InvalidArgumentException('A removed member cannot change workspace role.');
        }
        $this->role = $role;
        $this->membershipUpdatedAt = $now ?? new \DateTimeImmutable();
    }

    public function canEditBoards(): bool
    {
        return $this->hasActiveWorkspaceMembership()
            && in_array($this->role, ['Owner', 'Admin', 'Editor'], true);
    }

    public function canAdministerWorkspace(): bool
    {
        return $this->hasActiveWorkspaceMembership()
            && in_array($this->role, self::ADMIN_ROLES, true);
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }
}
