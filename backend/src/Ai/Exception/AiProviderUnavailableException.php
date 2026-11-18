<?php

declare(strict_types=1);

namespace App\Ai\Exception;

/** Generation is switched off or the provider configuration is incomplete. */
final class AiProviderUnavailableException extends AiGenerationException
{
    public function __construct(string $logDetail)
    {
        // The client learns only that generation is not configured; the missing
        // variable or unsupported provider slug stays in the server log.
        parent::__construct(
            'AI generation is not configured.',
            503,
            'ai.provider_unavailable',
            [],
            null,
            $logDetail,
        );
    }
}
