<?php

declare(strict_types=1);

namespace App\Ai\Exception;

/**
 * The provider answered with an error, or returned a document that cannot become
 * a proposal. The client gets one stable message; the reason is logged.
 */
final class AiProviderFailureException extends AiGenerationException
{
    public function __construct(string $logDetail)
    {
        parent::__construct(
            'The AI provider could not produce a usable proposal.',
            502,
            'ai.provider_failed',
            [],
            null,
            $logDetail,
        );
    }
}
