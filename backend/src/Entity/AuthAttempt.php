<?php

declare(strict_types=1);

namespace App\Entity;

use App\Id\UuidGenerator;
use App\Repository\AuthAttemptRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A failed login or registration attempt, used only for short-lived throttling.
 * The key is a SHA-256 digest of the e-mail/IP pair; raw identifiers are not stored.
 */
#[ORM\Entity(repositoryClass: AuthAttemptRepository::class)]
#[ORM\Table(name: 'auth_attempts')]
#[ORM\Index(name: 'idx_auth_attempts_lookup', columns: ['action', 'key_hash', 'attempted_at'])]
#[ORM\Index(name: 'idx_auth_attempts_time', columns: ['attempted_at'])]
class AuthAttempt
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(length: 32)]
    private string $action;

    #[ORM\Column(name: 'key_hash', length: 64)]
    private string $keyHash;

    #[ORM\Column(name: 'attempted_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $attemptedAt;

    public function __construct(string $action, string $keyHash, ?\DateTimeImmutable $attemptedAt = null)
    {
        if ('' === trim($action) || strlen($action) > 32) {
            throw new \InvalidArgumentException('Authentication attempt action is invalid.');
        }
        if (1 !== preg_match('/^[a-f0-9]{64}$/D', $keyHash)) {
            throw new \InvalidArgumentException('Authentication attempt key is invalid.');
        }

        $this->action = $action;
        $this->keyHash = $keyHash;
        $this->attemptedAt = $attemptedAt ?? new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getKeyHash(): string
    {
        return $this->keyHash;
    }

    public function getAttemptedAt(): \DateTimeImmutable
    {
        return $this->attemptedAt;
    }
}
