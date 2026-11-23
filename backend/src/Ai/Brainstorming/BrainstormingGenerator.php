<?php

declare(strict_types=1);

namespace App\Ai\Brainstorming;

use App\Ai\Exception\AiGenerationException;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates one generation: provider call, strict validation, technical
 * metrics (contract §10.1/§10.2).
 *
 * It has no entity manager, repository or board service by construction, so a
 * successful generation cannot write anything. Logs carry identifiers, counts
 * and durations only — never the prompt or the proposal text.
 */
final class BrainstormingGenerator
{
    public function __construct(
        private readonly BrainstormingProviderInterface $provider,
        private readonly BrainstormingResponseValidator $validator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function generate(BrainstormingRequest $request, string $userId): BrainstormingProposal
    {
        $startedAt = microtime(true);

        try {
            $proposal = $this->validator->validate($this->provider->generate($request));
        } catch (AiGenerationException $exception) {
            $this->logger->warning('ai.brainstorming.failed', [
                'user' => $userId,
                'provider' => $this->provider->name(),
                'code' => $exception->getErrorCode(),
                'reason' => $exception->getLogDetail(),
                'prompt_length' => $request->promptLength(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            throw $exception;
        }

        $this->logger->info('ai.brainstorming.generated', [
            'user' => $userId,
            'provider' => $this->provider->name(),
            'language' => $request->language,
            'detail_level' => $request->detailLevel,
            'prompt_length' => $request->promptLength(),
            'sections' => count($proposal->sections),
            'notes' => $proposal->noteCount(),
            'truncated' => $proposal->truncated,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return $proposal;
    }
}
