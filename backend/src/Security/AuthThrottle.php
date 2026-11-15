<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\AuthAttempt;
use App\Repository\AuthAttemptRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Small database-backed throttle for the two unauthenticated credential
 * endpoints. It deliberately does not reveal whether the e-mail exists: the
 * key is a digest of the normalized e-mail plus the client IP, and every
 * failure returns the same generic authentication error until the limit is hit.
 *
 * Client IP comes from Symfony's Request::getClientIp(). Trusted proxies are
 * not configured, so X-Forwarded-For cannot be spoofed to bypass the limit; a
 * deployment behind a proxy must configure trusted proxies explicitly.
 */
final class AuthThrottle
{
    public function __construct(
        private readonly AuthAttemptRepository $attempts,
        private readonly EntityManagerInterface $entityManager,
        private readonly bool $enabled,
        private readonly int $loginMaxAttempts,
        private readonly int $loginIpMaxAttempts,
        private readonly int $loginWindowSeconds,
        private readonly int $registerMaxAttempts,
        private readonly int $registerWindowSeconds,
    ) {
        if ($this->enabled && min(
            $this->loginMaxAttempts,
            $this->loginIpMaxAttempts,
            $this->loginWindowSeconds,
            $this->registerMaxAttempts,
            $this->registerWindowSeconds,
        ) < 1) {
            throw new \InvalidArgumentException('Authentication throttle limits must be positive.');
        }
    }

    /** @return int|null Seconds to wait, or null when the attempt is allowed. */
    public function loginRetryAfter(string $email, ?string $ip): ?int
    {
        if (!$this->enabled) {
            return null;
        }

        $since = (new \DateTimeImmutable())->modify(sprintf('-%d seconds', $this->loginWindowSeconds));
        foreach ($this->loginKeys($email, $ip) as $attempt) {
            if ($this->attempts->countSince('login', $attempt['hash'], $since) >= $attempt['max']) {
                return $this->loginWindowSeconds;
            }
        }

        return null;
    }

    public function recordLoginFailure(string $email, ?string $ip): void
    {
        if (!$this->enabled) {
            return;
        }

        $now = new \DateTimeImmutable();
        $this->prune($now);
        foreach ($this->loginKeys($email, $ip) as $attempt) {
            $this->entityManager->persist(new AuthAttempt('login', $attempt['hash'], $now));
        }
        $this->entityManager->flush();
    }

    public function clearLoginFailures(string $email, ?string $ip): void
    {
        if (!$this->enabled) {
            return;
        }

        $hashes = array_map(
            static fn (array $attempt): string => $attempt['hash'],
            $this->loginKeys($email, $ip),
        );
        $this->attempts->deleteFor('login', $hashes);
    }

    /** @return int|null Seconds to wait, or null when the attempt is allowed. */
    public function registrationRetryAfter(?string $ip): ?int
    {
        if (!$this->enabled) {
            return null;
        }

        $since = (new \DateTimeImmutable())->modify(sprintf('-%d seconds', $this->registerWindowSeconds));
        $count = $this->attempts->countSince('register', $this->registrationKey($ip), $since);

        return $count >= $this->registerMaxAttempts ? $this->registerWindowSeconds : null;
    }

    public function recordRegistrationAttempt(?string $ip): void
    {
        if (!$this->enabled) {
            return;
        }

        $now = new \DateTimeImmutable();
        $this->prune($now);
        $this->entityManager->persist(new AuthAttempt('register', $this->registrationKey($ip), $now));
        $this->entityManager->flush();
    }

    /** @return list<array{hash: string, max: int}> */
    private function loginKeys(string $email, ?string $ip): array
    {
        $client = $ip ?? 'unknown';

        return [
            [
                'hash' => hash('sha256', 'login-email|'.strtolower(trim($email)).'|'.$client),
                'max' => $this->loginMaxAttempts,
            ],
            [
                'hash' => hash('sha256', 'login-ip|'.$client),
                'max' => $this->loginIpMaxAttempts,
            ],
        ];
    }

    private function registrationKey(?string $ip): string
    {
        return hash('sha256', 'register-ip|'.($ip ?? 'unknown'));
    }

    private function prune(\DateTimeImmutable $now): void
    {
        $window = max($this->loginWindowSeconds, $this->registerWindowSeconds);
        $this->attempts->deleteOlderThan($now->modify(sprintf('-%d seconds', $window)));
    }
}
