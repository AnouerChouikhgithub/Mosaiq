<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Ai\Brainstorming\Provider\ChatCompletionTransportInterface;

/**
 * Records the chat-completion call and replays queued outcomes, so provider
 * tests can exercise retries, 429 handling and timeouts without a network.
 */
final class RecordingChatCompletionTransport implements ChatCompletionTransportInterface
{
    /** @var list<array{url: string, headers: array<string, string>, body: array<string, mixed>, timeout: float}> */
    public array $calls = [];

    /** @var list<array{status: int, body: string, retryAfter: int|null}|\Throwable> */
    private array $queue = [];

    public function queueResponse(int $status, string $body, ?int $retryAfter = null): void
    {
        $this->queue[] = ['status' => $status, 'body' => $body, 'retryAfter' => $retryAfter];
    }

    public function queueFailure(\Throwable $failure): void
    {
        $this->queue[] = $failure;
    }

    public function post(string $url, array $headers, string $body, float $timeoutSeconds): array
    {
        $decoded = json_decode($body, true, 64, \JSON_THROW_ON_ERROR);
        \assert(is_array($decoded));

        $this->calls[] = [
            'url' => $url,
            'headers' => $headers,
            'body' => $decoded,
            'timeout' => $timeoutSeconds,
        ];

        $next = array_shift($this->queue);
        if (null === $next) {
            throw new \LogicException('No transport response is queued.');
        }
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }
}
