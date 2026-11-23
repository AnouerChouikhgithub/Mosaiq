<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Ai\Brainstorming\BrainstormingProviderInterface;
use App\Ai\Brainstorming\BrainstormingRequest;

/**
 * Scripted provider for the test environment (see config/services_test.yaml).
 *
 * Responses and failures are queued by the test, so the suite is deterministic,
 * needs no API key and cannot reach the network. A request with nothing queued
 * is a test bug and fails loudly instead of silently returning canned content.
 */
final class FakeBrainstormingProvider implements BrainstormingProviderInterface
{
    /** @var list<string|\Throwable> */
    private static array $queue = [];

    /** @var list<BrainstormingRequest> */
    private static array $requests = [];

    public static function reset(): void
    {
        self::$queue = [];
        self::$requests = [];
    }

    public static function queueDocument(string $document): void
    {
        self::$queue[] = $document;
    }

    public static function queueFailure(\Throwable $failure): void
    {
        self::$queue[] = $failure;
    }

    /** @return list<BrainstormingRequest> */
    public static function receivedRequests(): array
    {
        return self::$requests;
    }

    public static function queuedResponses(): int
    {
        return count(self::$queue);
    }

    public function name(): string
    {
        return 'fake';
    }

    public function generate(BrainstormingRequest $request): string
    {
        self::$requests[] = $request;
        $next = array_shift(self::$queue);
        if (null === $next) {
            throw new \LogicException('No fake brainstorming response is queued.');
        }
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }
}
