<?php

declare(strict_types=1);

namespace App\Ai\Brainstorming\Provider;

use App\Ai\Brainstorming\BrainstormingLimits;
use App\Ai\Brainstorming\BrainstormingPromptBuilder;
use App\Ai\Brainstorming\BrainstormingProviderInterface;
use App\Ai\Brainstorming\BrainstormingRequest;
use App\Ai\Exception\AiProviderFailureException;
use App\Ai\Exception\AiProviderTimeoutException;
use App\Ai\Exception\AiProviderUnavailableException;
use App\Ai\Exception\AiRateLimitedException;

/**
 * The one provider of the first release: any endpoint that speaks the OpenAI
 * chat-completions shape with a JSON response format.
 *
 * Why this shape: it is the most widely implemented structured-output API, so
 * the deployment can point AI_BASE_URL at the chosen vendor or gateway without
 * a code change; it supports a forced JSON object plus `temperature` and
 * `max_tokens`, which is what the contract's prompt relies on; and it keeps the
 * surface to one HTTP call with no SDK, matching the "avoid unnecessary
 * dependencies" rule.
 *
 * The adapter owns configuration, transport, retries and the response envelope.
 * It never builds or validates a proposal: that is the validator's job, so a
 * second provider cannot redefine what a valid proposal is.
 */
final class OpenAiCompatibleBrainstormingProvider implements BrainstormingProviderInterface
{
    private const SUPPORTED_SLUG = 'openai-compatible';
    private const MAX_OUTPUT_TOKENS = 2000;
    private const TEMPERATURE = 0.7;
    private const DEFAULT_RATE_LIMIT_RETRY_AFTER = 60;

    public function __construct(
        private readonly ChatCompletionTransportInterface $transport,
        private readonly BrainstormingPromptBuilder $prompts,
        private readonly bool $enabled,
        private readonly string $providerSlug,
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly string $apiKey,
        private readonly int $timeoutSeconds,
    ) {
    }

    public function name(): string
    {
        return $this->providerSlug;
    }

    public function generate(BrainstormingRequest $request): string
    {
        $this->assertConfigured();

        $url = rtrim(trim($this->baseUrl), '/').'/chat/completions';
        $body = json_encode([
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $this->prompts->systemMessage()],
                ['role' => 'user', 'content' => $this->prompts->userMessage($request)],
            ],
            'temperature' => self::TEMPERATURE,
            'max_tokens' => self::MAX_OUTPUT_TOKENS,
            'response_format' => ['type' => 'json_object'],
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);

        $headers = [
            'Authorization' => 'Bearer '.$this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        // Every attempt shares one total budget, so a retry can never double the
        // time a request keeps a PHP worker busy.
        $deadline = microtime(true) + (float) BrainstormingLimits::TOTAL_PROVIDER_BUDGET_SECONDS;
        $attempt = 0;

        while (true) {
            ++$attempt;
            $remaining = $deadline - microtime(true);
            if ($remaining < 0.5) {
                throw new AiProviderTimeoutException('The total provider budget ran out before an attempt could start.');
            }
            $timeout = max(0.5, min((float) $this->timeoutSeconds, $remaining, (float) BrainstormingLimits::MAX_PROVIDER_TIMEOUT_SECONDS));

            try {
                $response = $this->transport->post($url, $headers, $body, $timeout);
            } catch (AiProviderTimeoutException $timeoutException) {
                if ($attempt >= BrainstormingLimits::MAX_PROVIDER_ATTEMPTS) {
                    throw $timeoutException;
                }
                usleep(BrainstormingLimits::PROVIDER_RETRY_DELAY_MICROSECONDS);

                continue;
            }

            $status = $response['status'];
            if ($status >= 200 && $status < 300) {
                return $this->extractContent($response['body']);
            }

            // A provider quota answer is the caller's rate limit, not a server
            // fault: fail immediately instead of retrying against the quota.
            if (429 === $status) {
                throw new AiRateLimitedException($this->saneRetryAfter($response['retryAfter']));
            }

            if ($status >= 500) {
                if ($attempt >= BrainstormingLimits::MAX_PROVIDER_ATTEMPTS) {
                    throw new AiProviderFailureException(sprintf('Provider answered HTTP %d after %d attempts.', $status, $attempt));
                }
                usleep(BrainstormingLimits::PROVIDER_RETRY_DELAY_MICROSECONDS);

                continue;
            }

            // 4xx other than 429 will not improve on retry (bad key, bad model,
            // rejected parameters), so it is reported as a provider failure.
            throw new AiProviderFailureException(sprintf('Provider answered HTTP %d.', $status));
        }
    }

    /**
     * Reads `choices[0].message.content`, the only part of the envelope this
     * feature needs. The content string is still untrusted and is validated
     * afterwards.
     */
    private function extractContent(string $responseBody): string
    {
        try {
            $decoded = json_decode($responseBody, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new AiProviderFailureException('Provider response body is not valid JSON.');
        }

        if (!is_array($decoded) || !array_key_exists('choices', $decoded) || !is_array($decoded['choices'])) {
            throw new AiProviderFailureException('Provider response has no choices array.');
        }

        $firstChoice = $decoded['choices'][0] ?? null;
        if (!is_array($firstChoice) || !array_key_exists('message', $firstChoice) || !is_array($firstChoice['message'])) {
            throw new AiProviderFailureException('Provider response has no first choice message.');
        }

        $content = $firstChoice['message']['content'] ?? null;
        if (!is_string($content) || '' === trim($content)) {
            throw new AiProviderFailureException('Provider response has no message content.');
        }

        return $content;
    }

    private function assertConfigured(): void
    {
        if (!$this->enabled) {
            throw new AiProviderUnavailableException('AI generation is disabled (AI_ENABLED is false).');
        }
        if (self::SUPPORTED_SLUG !== trim($this->providerSlug)) {
            throw new AiProviderUnavailableException(sprintf('Unsupported AI provider slug "%s".', $this->providerSlug));
        }
        if ('' === trim($this->baseUrl) || '' === trim($this->model) || '' === trim($this->apiKey)) {
            // Names only: the key itself is never logged or echoed.
            throw new AiProviderUnavailableException('AI_BASE_URL, AI_MODEL and AI_PROVIDER_API_KEY must all be set.');
        }
    }

    /** Keeps a hostile or missing Retry-After inside a sane range. */
    private function saneRetryAfter(?int $retryAfter): int
    {
        if (null === $retryAfter || $retryAfter < 1 || $retryAfter > 3600) {
            return self::DEFAULT_RATE_LIMIT_RETRY_AFTER;
        }

        return $retryAfter;
    }
}
