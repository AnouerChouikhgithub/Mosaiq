<?php

declare(strict_types=1);

namespace App\Ai;

use App\Ai\Exception\AiRateLimitedException;
use App\Entity\User;
use App\Repository\AiGenerationUsageRepository;

/**
 * Fixed-window rate limiting for AI generation (contract §6.8/§8).
 *
 * Counters live in the database (the same approach as the auth throttle) so
 * every PHP worker shares them; this is not a distributed limiter and does not
 * coordinate across deployments. A slot is consumed by every attempt, including
 * one that is then refused, which keeps the check atomic and cannot be gamed by
 * retrying.
 */
final class AiGenerationRateLimiter
{
    private const TEN_MINUTES = 600;
    private const ONE_HOUR = 3600;
    private const ONE_MINUTE = 60;
    private const DEPLOYMENT_SCOPE = 'deployment';

    public function __construct(
        private readonly AiGenerationUsageRepository $usage,
        private readonly int $perTenMinutes,
        private readonly int $perHour,
        private readonly int $globalPerMinute,
    ) {
        if (min($this->perTenMinutes, $this->perHour, $this->globalPerMinute) < 1) {
            throw new \InvalidArgumentException('AI generation rate limits must be positive.');
        }
    }

    /**
     * @throws AiRateLimitedException when the user or the deployment is over its window
     */
    public function consume(User $user, ?\DateTimeImmutable $now = null): void
    {
        $now = ($now ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone('UTC'));
        $this->usage->deleteExpired($now);

        $retryAfter = 0;
        foreach ($this->windows($user, $now) as $window) {
            $used = $this->usage->consume($window['scope'], $window['key'], $window['expiresAt']);
            if ($used > $window['limit']) {
                $retryAfter = max($retryAfter, max(1, $window['expiresAt']->getTimestamp() - $now->getTimestamp()));
            }
        }

        if ($retryAfter > 0) {
            throw new AiRateLimitedException($retryAfter);
        }
    }

    /**
     * @return list<array{scope: string, key: string, limit: int, expiresAt: \DateTimeImmutable}>
     */
    private function windows(User $user, \DateTimeImmutable $now): array
    {
        $timestamp = $now->getTimestamp();

        return [
            $this->window('user:'.$user->getId(), '10m', $timestamp, self::TEN_MINUTES, $this->perTenMinutes),
            $this->window('user:'.$user->getId(), '1h', $timestamp, self::ONE_HOUR, $this->perHour),
            $this->window(self::DEPLOYMENT_SCOPE, '1m', $timestamp, self::ONE_MINUTE, $this->globalPerMinute),
        ];
    }

    /**
     * @return array{scope: string, key: string, limit: int, expiresAt: \DateTimeImmutable}
     */
    private function window(string $scope, string $prefix, int $timestamp, int $length, int $limit): array
    {
        $windowStart = intdiv($timestamp, $length) * $length;

        return [
            'scope' => $scope,
            // Kept under the 16-character column and the entity pattern.
            'key' => $prefix.':'.$windowStart,
            'limit' => $limit,
            'expiresAt' => new \DateTimeImmutable('@'.($windowStart + $length)),
        ];
    }
}
