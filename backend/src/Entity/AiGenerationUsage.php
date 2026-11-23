<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AiGenerationUsageRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One fixed-window counter row per scope and window.
 *
 * The row deliberately stores no prompt, user agent or content: only the
 * counter identity and its count are needed to throttle, and the scope is the
 * user id or the deployment. `(scope, window_key)` is the primary key so the
 * repository can increment it with a single atomic upsert.
 */
#[ORM\Entity(repositoryClass: AiGenerationUsageRepository::class)]
#[ORM\Table(name: 'ai_generation_usage')]
#[ORM\Index(name: 'idx_ai_generation_usage_expiry', columns: ['expires_at'])]
class AiGenerationUsage
{
    #[ORM\Id]
    #[ORM\Column(length: 120)]
    private string $scope;

    #[ORM\Id]
    #[ORM\Column(name: 'window_key', length: 16)]
    private string $windowKey;

    #[ORM\Column(name: 'used_count')]
    private int $usedCount;

    #[ORM\Column(name: 'expires_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $expiresAt;

    public function __construct(string $scope, string $windowKey, int $usedCount, \DateTimeImmutable $expiresAt)
    {
        if ('' === trim($scope) || strlen($scope) > 120) {
            throw new \InvalidArgumentException('AI generation usage scope is invalid.');
        }
        if (1 !== preg_match('/^[0-9a-z]{1,4}:[0-9]{1,10}$/D', $windowKey)) {
            throw new \InvalidArgumentException('AI generation usage window is invalid.');
        }
        if ($usedCount < 0) {
            throw new \InvalidArgumentException('AI generation usage count cannot be negative.');
        }

        $this->scope = $scope;
        $this->windowKey = $windowKey;
        $this->usedCount = $usedCount;
        $this->expiresAt = $expiresAt;
    }

    public function getScope(): string
    {
        return $this->scope;
    }

    public function getWindowKey(): string
    {
        return $this->windowKey;
    }

    public function getUsedCount(): int
    {
        return $this->usedCount;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }
}
