<?php

declare(strict_types=1);

namespace App\Ai\Exception;

/** The authenticated user (or the deployment) used its generation quota. */
final class AiRateLimitedException extends AiGenerationException
{
    public function __construct(int $retryAfter)
    {
        $retryAfter = max(1, $retryAfter);

        parent::__construct(
            'Too many AI generation requests. Try again later.',
            429,
            'ai.rate_limited',
            [],
            $retryAfter,
            sprintf('AI generation rate limit reached; retry after %d seconds.', $retryAfter),
        );
    }
}
