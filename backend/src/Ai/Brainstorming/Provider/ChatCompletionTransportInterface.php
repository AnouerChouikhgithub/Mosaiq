<?php

declare(strict_types=1);

namespace App\Ai\Brainstorming\Provider;

use App\Ai\Exception\AiProviderFailureException;
use App\Ai\Exception\AiProviderTimeoutException;

/**
 * One HTTP POST, isolated from the provider logic.
 *
 * This seam is what keeps the adapter testable without a network: the tests
 * supply a recorded transport and can reproduce timeouts, 5xx retries and
 * malformed bodies deterministically. It is deliberately only about HTTP
 * transport, not about prompts, schema or retries.
 */
interface ChatCompletionTransportInterface
{
    /**
     * @param array<string, string> $headers
     *
     * @return array{status: int, body: string, retryAfter: int|null}
     *
     * @throws AiProviderTimeoutException when the request exceeds its budget
     * @throws AiProviderFailureException when the request cannot be performed at all
     */
    public function post(string $url, array $headers, string $body, float $timeoutSeconds): array;
}
