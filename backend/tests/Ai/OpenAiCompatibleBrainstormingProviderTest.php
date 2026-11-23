<?php

declare(strict_types=1);

namespace App\Tests\Ai;

use App\Ai\Brainstorming\BrainstormingPromptBuilder;
use App\Ai\Brainstorming\BrainstormingRequest;
use App\Ai\Brainstorming\Provider\OpenAiCompatibleBrainstormingProvider;
use App\Ai\Exception\AiProviderFailureException;
use App\Ai\Exception\AiProviderTimeoutException;
use App\Ai\Exception\AiProviderUnavailableException;
use App\Ai\Exception\AiRateLimitedException;
use App\Tests\Support\RecordingChatCompletionTransport;
use PHPUnit\Framework\TestCase;

/**
 * Provider adapter behaviour without a network: configuration, request shape,
 * retry policy, quota mapping and envelope parsing.
 */
final class OpenAiCompatibleBrainstormingProviderTest extends TestCase
{
    private const CONTENT = '{"proposal":{"title":"Board","objective":"Objective","sections":[{"id":"s1","title":"One","category":"ideas","notes":[{"id":"n1","type":"idea","content":"Idea"}]}]}}';

    public function testBuildsTheChatCompletionRequestFromConfiguration(): void
    {
        $transport = new RecordingChatCompletionTransport();
        $transport->queueResponse(200, $this->envelope(self::CONTENT));

        $provider = $this->provider($transport);
        $document = $provider->generate($this->request());

        self::assertSame(self::CONTENT, $document);
        self::assertCount(1, $transport->calls);

        $call = $transport->calls[0];
        self::assertSame('https://api.example.test/v1/chat/completions', $call['url']);
        self::assertSame('Bearer test-key', $call['headers']['Authorization']);
        self::assertSame('example-model', $call['body']['model']);
        self::assertSame(0.7, $call['body']['temperature']);
        self::assertSame(2000, $call['body']['max_tokens']);
        self::assertSame(['type' => 'json_object'], $call['body']['response_format']);
        self::assertSame(20.0, $call['timeout']);

        $messages = $call['body']['messages'];
        self::assertIsArray($messages);
        self::assertSame('system', $messages[0]['role']);
        self::assertStringContainsString('at most 5', (string) $messages[0]['content']);
        self::assertStringContainsString('idea, question, challenge, opportunity, action', (string) $messages[0]['content']);
        self::assertSame('user', $messages[1]['role']);
        self::assertStringContainsString('Topic: Bike sharing in Tunis', (string) $messages[1]['content']);
        self::assertStringContainsString('Language: English', (string) $messages[1]['content']);
    }

    public function testAddsTheArabicInstructionOnlyForArabic(): void
    {
        $transport = new RecordingChatCompletionTransport();
        $transport->queueResponse(200, $this->envelope(self::CONTENT));

        $this->provider($transport)->generate($this->request(['language' => 'ar']));

        $messages = $transport->calls[0]['body']['messages'];
        self::assertIsArray($messages);
        self::assertStringContainsString('Modern Standard Arabic', (string) $messages[1]['content']);
    }

    public function testFailsClosedWhenGenerationIsDisabled(): void
    {
        $transport = new RecordingChatCompletionTransport();

        try {
            $this->provider($transport, enabled: false)->generate($this->request());
            self::fail('Disabled generation must not reach the transport.');
        } catch (AiProviderUnavailableException $exception) {
            self::assertSame('ai.provider_unavailable', $exception->getErrorCode());
        }

        self::assertCount(0, $transport->calls);
    }

    public function testFailsClosedWithoutAnApiKey(): void
    {
        $transport = new RecordingChatCompletionTransport();

        $this->expectException(AiProviderUnavailableException::class);
        try {
            $this->provider($transport, apiKey: '')->generate($this->request());
        } finally {
            self::assertCount(0, $transport->calls);
        }
    }

    public function testRejectsAnUnsupportedProviderSlug(): void
    {
        $transport = new RecordingChatCompletionTransport();

        $this->expectException(AiProviderUnavailableException::class);
        $this->provider($transport, slug: 'other-vendor')->generate($this->request());
    }

    public function testMapsAProviderQuotaResponseToTheRateLimitContract(): void
    {
        $transport = new RecordingChatCompletionTransport();
        $transport->queueResponse(429, '{"error":"quota"}', 42);

        try {
            $this->provider($transport)->generate($this->request());
            self::fail('A provider 429 must be reported as a rate limit.');
        } catch (AiRateLimitedException $exception) {
            self::assertSame(429, $exception->getStatusCode());
            self::assertSame(42, $exception->getRetryAfter());
        }

        self::assertCount(1, $transport->calls);
    }

    public function testUsesASaneDefaultWhenRetryAfterIsMissing(): void
    {
        $transport = new RecordingChatCompletionTransport();
        $transport->queueResponse(429, '{"error":"quota"}');

        try {
            $this->provider($transport)->generate($this->request());
            self::fail('A provider 429 must be reported as a rate limit.');
        } catch (AiRateLimitedException $exception) {
            self::assertSame(60, $exception->getRetryAfter());
        }
    }

    public function testRetriesOnceOnAServerError(): void
    {
        $transport = new RecordingChatCompletionTransport();
        $transport->queueResponse(500, '{"error":"upstream"}');
        $transport->queueResponse(200, $this->envelope(self::CONTENT));

        $document = $this->provider($transport)->generate($this->request());

        self::assertSame(self::CONTENT, $document);
        self::assertCount(2, $transport->calls);
    }

    public function testGivesUpAfterTwoServerErrors(): void
    {
        $transport = new RecordingChatCompletionTransport();
        $transport->queueResponse(500, '{"error":"upstream"}');
        $transport->queueResponse(502, '{"error":"upstream"}');

        $this->expectException(AiProviderFailureException::class);
        try {
            $this->provider($transport)->generate($this->request());
        } finally {
            self::assertCount(2, $transport->calls);
        }
    }

    public function testDoesNotRetryAClientError(): void
    {
        $transport = new RecordingChatCompletionTransport();
        $transport->queueResponse(401, '{"error":"bad key"}');

        $this->expectException(AiProviderFailureException::class);
        try {
            $this->provider($transport)->generate($this->request());
        } finally {
            self::assertCount(1, $transport->calls);
        }
    }

    public function testMapsTransportTimeoutsAndGivesUpAfterTheSecondAttempt(): void
    {
        $transport = new RecordingChatCompletionTransport();
        $transport->queueFailure(new AiProviderTimeoutException('attempt 1 timed out.'));
        $transport->queueFailure(new AiProviderTimeoutException('attempt 2 timed out.'));

        $this->expectException(AiProviderTimeoutException::class);
        try {
            $this->provider($transport)->generate($this->request());
        } finally {
            self::assertCount(2, $transport->calls);
        }
    }

    public function testRejectsAnEnvelopeWithoutContent(): void
    {
        $transport = new RecordingChatCompletionTransport();
        $transport->queueResponse(200, '{"choices":[]}');

        $this->expectException(AiProviderFailureException::class);
        $this->provider($transport)->generate($this->request());
    }

    public function testRejectsANonJsonEnvelope(): void
    {
        $transport = new RecordingChatCompletionTransport();
        $transport->queueResponse(200, '<html>gateway error</html>');

        $this->expectException(AiProviderFailureException::class);
        $this->provider($transport)->generate($this->request());
    }

    private function provider(
        RecordingChatCompletionTransport $transport,
        bool $enabled = true,
        string $slug = 'openai-compatible',
        string $baseUrl = 'https://api.example.test/v1',
        string $model = 'example-model',
        string $apiKey = 'test-key',
        int $timeout = 20,
    ): OpenAiCompatibleBrainstormingProvider {
        return new OpenAiCompatibleBrainstormingProvider(
            $transport,
            new BrainstormingPromptBuilder(),
            $enabled,
            $slug,
            $baseUrl,
            $model,
            $apiKey,
            $timeout,
        );
    }

    /** @param array<string, mixed> $overrides */
    private function request(array $overrides = []): BrainstormingRequest
    {
        return BrainstormingRequest::fromPayload(array_merge([
            'topic' => 'Bike sharing in Tunis',
            'objective' => 'Find the first launch steps',
            'language' => 'en',
            'detailLevel' => 'concise',
        ], $overrides));
    }

    private function envelope(string $content): string
    {
        return json_encode(['choices' => [['message' => ['role' => 'assistant', 'content' => $content]]]], \JSON_THROW_ON_ERROR);
    }
}
