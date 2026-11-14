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
#[ORM\UniqueConstraint(name: 'uniq_users_api_token_hash', columns: ['api_token_hash'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const ROLES = ['Owner', 'Admin', 'Editor', 'Commenter', 'Viewer'];
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

    #[ORM\Column(length: 120)]
    private string $city;

    #[ORM\Column(length: 16)]
    private string $accent;

    #[ORM\Column(name: 'password_hash', length: 255, nullable: true)]
    private ?string $passwordHash = null;

    #[ORM\Column(name: 'api_token_hash', length: 64, nullable: true)]
    private ?string $apiTokenHash = null;

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

    public function setApiTokenHash(?string $apiTokenHash): void
    {
        $this->apiTokenHash = $apiTokenHash;
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

    public function changeRole(string $role): void
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException('User role is not supported.');
        }
        $this->role = $role;
    }

    public function canEditBoards(): bool
    {
        return in_array($this->role, ['Owner', 'Admin', 'Editor'], true);
    }

    public function canAdministerWorkspace(): bool
    {
        return in_array($this->role, ['Owner', 'Admin'], true);
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
