<?php

declare(strict_types=1);

namespace App\Ai\Brainstorming;

use App\Ai\Exception\AiProviderFailureException;
use App\Ai\Exception\AiProviderTimeoutException;
use App\Ai\Exception\AiProviderUnavailableException;

/**
 * Contract §10.3. A provider turns a validated request into the raw JSON
 * document produced by the model; it never builds DTOs, validates a proposal or
 * touches persistence, so a second provider cannot change what "valid" means.
 */
interface BrainstormingProviderInterface
{
    /** Stable slug used in logs and configuration. */
    public function name(): string;

    /**
     * @throws AiProviderUnavailableException when generation is disabled or unconfigured
     * @throws AiProviderTimeoutException     when the attempt and total budgets are exhausted
     * @throws AiProviderFailureException     when the provider answers with an error or no usable document
     */
    public function generate(BrainstormingRequest $request): string;
}
