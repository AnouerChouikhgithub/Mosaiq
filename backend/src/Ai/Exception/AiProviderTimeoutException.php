<?php

declare(strict_types=1);

namespace App\Ai\Exception;

/** The provider did not answer inside the attempt or total budget. */
final class AiProviderTimeoutException extends AiGenerationException
{
    public function __construct(string $logDetail)
    {
        parent::__construct(
            'The AI provider took too long to answer.',
            504,
            'ai.provider_timeout',
            [],
            null,
            $logDetail,
        );
    }
}
