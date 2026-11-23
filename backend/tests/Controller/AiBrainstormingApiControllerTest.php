<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Ai\Exception\AiProviderTimeoutException;
use App\Ai\Exception\AiProviderUnavailableException;
use App\Tests\Support\FakeBrainstormingProvider;

/**
 * Contract-level tests for POST /api/ai/brainstorming/generate.
 *
 * The provider is the scripted fake from config/services_test.yaml, so every case
 * is deterministic and no test needs an API key or a network. The suite proves
 * the response contract, the request limits, the validation of model output, the
 * provider error mapping, the quota, and that generation writes nothing.
 */
final class AiBrainstormingApiControllerTest extends ApiFunctionalTestCase
{
    private const ENDPOINT = '/api/ai/brainstorming/generate';

    protected function setUp(): void
    {
        parent::setUp();
        FakeBrainstormingProvider::reset();
    }

    public function testGeneratesAValidatedProposal(): void
    {
        FakeBrainstormingProvider::queueDocument($this->validDocument());

        $this->generate($this->validPayload());

        self::assertResponseStatusCodeSame(200);
        $body = $this->jsonResponse();
        self::assertSame('1.0', $body['schemaVersion']);
        self::assertSame('fr', $body['language']);
        self::assertSame('balanced', $body['detailLevel']);
        self::assertFalse($body['truncated']);
        self::assertSame('Location de vélos à Tunis', $body['proposal']['title']);
        self::assertCount(3, $body['proposal']['sections']);
        self::assertSame('s1', $body['proposal']['sections'][0]['id']);
        self::assertSame('ideas', $body['proposal']['sections'][0]['category']);
        self::assertSame('s1-n1', $body['proposal']['sections'][0]['notes'][0]['id']);
        self::assertSame('idea', $body['proposal']['sections'][0]['notes'][0]['type']);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        // The provider receives the normalised request, not the raw payload.
        $requests = FakeBrainstormingProvider::receivedRequests();
        self::assertCount(1, $requests);
        self::assertSame('Location de vélos à Tunis', $requests[0]->topic);
        self::assertNull($requests[0]->context);
    }

    public function testRequiresAuthentication(): void
    {
        $this->generateWithoutToken($this->validPayload());

        self::assertResponseStatusCodeSame(401);
        self::assertCount(0, FakeBrainstormingProvider::receivedRequests());
    }

    public function testRejectsMissingRequiredFields(): void
    {
        $this->generate(['language' => 'fr', 'detailLevel' => 'balanced']);

        self::assertResponseStatusCodeSame(422);
        $body = $this->jsonResponse();
        self::assertSame('ai.invalid_request', $body['code']);
        self::assertContains('topic', $this->detailFields($body));
        self::assertContains('objective', $this->detailFields($body));
        self::assertCount(0, FakeBrainstormingProvider::receivedRequests());
    }

    public function testRejectsUnsupportedLanguage(): void
    {
        $this->generate($this->validPayload(['language' => 'de']));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('ai.invalid_request', $this->jsonResponse()['code']);
        self::assertContains('language', $this->detailFields($this->jsonResponse()));
    }

    public function testRejectsExcessivePromptLength(): void
    {
        $this->generate($this->validPayload(['topic' => str_repeat('a', 281)]));

        self::assertResponseStatusCodeSame(422);
        $body = $this->jsonResponse();
        self::assertContains('topic', $this->detailFields($body));
        self::assertSame('"topic" must be at most 280 characters.', $body['error']);
    }

    public function testRejectsExcessiveContextLength(): void
    {
        $this->generate($this->validPayload(['context' => str_repeat('b', 2001)]));

        self::assertResponseStatusCodeSame(422);
        self::assertContains('context', $this->detailFields($this->jsonResponse()));
    }

    public function testRejectsNonStringTopic(): void
    {
        $this->generate($this->validPayload(['topic' => ['not', 'a', 'string']]));

        self::assertResponseStatusCodeSame(422);
        self::assertContains('topic', $this->detailFields($this->jsonResponse()));
    }

    public function testRejectsUnknownField(): void
    {
        $this->generate($this->validPayload(['tone' => 'playful']));

        self::assertResponseStatusCodeSame(422);
        self::assertContains('tone', $this->detailFields($this->jsonResponse()));
    }

    public function testRejectsUnsupportedSchemaVersion(): void
    {
        $this->generate($this->validPayload(['schemaVersion' => '2.0']));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('ai.unsupported_schema_version', $this->jsonResponse()['code']);
    }

    public function testRejectsMalformedJsonBody(): void
    {
        $this->requestWithRawBody('POST', self::ENDPOINT, '{"topic":', $this->editorToken);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('ai.malformed_json', $this->jsonResponse()['code']);
    }

    public function testRejectsNonObjectJsonBody(): void
    {
        $this->requestWithRawBody('POST', self::ENDPOINT, '["topic","objective"]', $this->editorToken);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('ai.malformed_json', $this->jsonResponse()['code']);
    }

    public function testRejectsOversizedRequestBody(): void
    {
        $this->generate($this->validPayload(['context' => str_repeat('c', 9000)]));

        self::assertResponseStatusCodeSame(422);
        $body = $this->jsonResponse();
        self::assertSame('ai.invalid_request', $body['code']);
        self::assertContains('body', $this->detailFields($body));
        self::assertCount(0, FakeBrainstormingProvider::receivedRequests());
    }

    public function testCapsExcessiveSectionCount(): void
    {
        FakeBrainstormingProvider::queueDocument($this->document([
            $this->section(1, 3),
            $this->section(2, 3),
            $this->section(3, 3),
            $this->section(4, 3),
            $this->section(5, 3),
            $this->section(6, 3),
        ]));

        $this->generate($this->validPayload());

        self::assertResponseStatusCodeSame(200);
        $body = $this->jsonResponse();
        self::assertTrue($body['truncated']);
        self::assertCount(5, $body['proposal']['sections']);
    }

    public function testCapsExcessiveNoteCount(): void
    {
        FakeBrainstormingProvider::queueDocument($this->document([
            $this->section(1, 12),
            $this->section(2, 12),
            $this->section(3, 12),
        ]));

        $this->generate($this->validPayload());

        self::assertResponseStatusCodeSame(200);
        $body = $this->jsonResponse();
        self::assertTrue($body['truncated']);
        $notes = array_sum(array_map(static fn (array $section): int => count($section['notes']), $body['proposal']['sections']));
        self::assertSame(25, $notes);
        foreach ($body['proposal']['sections'] as $section) {
            self::assertLessThanOrEqual(10, count($section['notes']));
        }
    }

    public function testNormalisesUnexpectedNoteTypeAndCategory(): void
    {
        FakeBrainstormingProvider::queueDocument($this->document([
            ['id' => 's1', 'title' => 'Idées', 'category' => 'invented', 'notes' => [
                ['id' => 's1-n1', 'type' => 'todo', 'content' => 'A first concrete idea'],
                ['id' => 's1-n2', 'type' => 'question', 'content' => 'A second concrete idea'],
                ['id' => 's1-n3', 'type' => 'action', 'content' => 'A third concrete idea'],
            ]],
        ]));

        $this->generate($this->validPayload());

        self::assertResponseStatusCodeSame(200);
        $section = $this->jsonResponse()['proposal']['sections'][0];
        self::assertSame('ideas', $section['category']);
        self::assertSame('idea', $section['notes'][0]['type']);
    }

    public function testDropsEmptyAndDuplicateNotes(): void
    {
        FakeBrainstormingProvider::queueDocument($this->document([
            ['id' => 's1', 'title' => 'Idées', 'category' => 'ideas', 'notes' => [
                ['id' => 'a', 'type' => 'idea', 'content' => 'Repeated idea'],
                ['id' => 'b', 'type' => 'idea', 'content' => 'repeated idea'],
                ['id' => 'c', 'type' => 'idea', 'content' => '   '],
                ['id' => 'd', 'type' => 'idea', 'content' => 'Distinct idea'],
                ['id' => 'e', 'type' => 'idea', 'content' => 'Third distinct idea'],
            ]],
        ]));

        $this->generate($this->validPayload());

        self::assertResponseStatusCodeSame(200);
        $notes = $this->jsonResponse()['proposal']['sections'][0]['notes'];
        self::assertCount(3, $notes);
        self::assertSame('Repeated idea', $notes[0]['content']);
    }

    public function testRejectsWhitespaceOnlyProviderOutput(): void
    {
        FakeBrainstormingProvider::queueDocument('   ');

        $this->generate($this->validPayload());

        self::assertResponseStatusCodeSame(502);
        self::assertSame('ai.provider_failed', $this->jsonResponse()['code']);
    }

    public function testRejectsMalformedProviderJson(): void
    {
        FakeBrainstormingProvider::queueDocument('not json at all');

        $this->generate($this->validPayload());

        self::assertResponseStatusCodeSame(502);
        self::assertSame('ai.provider_failed', $this->jsonResponse()['code']);
    }

    public function testRejectsStructurallyInvalidProposal(): void
    {
        FakeBrainstormingProvider::queueDocument('{"proposal":{"title":"No sections"}}');

        $this->generate($this->validPayload());

        self::assertResponseStatusCodeSame(502);
        self::assertSame('ai.provider_failed', $this->jsonResponse()['code']);
    }

    public function testRejectsRelationshipsUnsupportedByTheSchema(): void
    {
        $document = json_encode([
            'proposal' => [
                'title' => 'Board title',
                'objective' => 'Board objective',
                'relationships' => [['from' => 's1-n1', 'to' => 's1-n2']],
                'sections' => [$this->section(1, 3)],
            ],
        ], \JSON_THROW_ON_ERROR);
        FakeBrainstormingProvider::queueDocument($document);

        $this->generate($this->validPayload());

        self::assertResponseStatusCodeSame(502);
        self::assertSame('ai.provider_failed', $this->jsonResponse()['code']);
    }

    public function testMapsProviderTimeout(): void
    {
        FakeBrainstormingProvider::queueFailure(new AiProviderTimeoutException('Fake provider timeout.'));

        $this->generate($this->validPayload());

        self::assertResponseStatusCodeSame(504);
        self::assertSame('ai.provider_timeout', $this->jsonResponse()['code']);
    }

    public function testMapsProviderUnavailable(): void
    {
        FakeBrainstormingProvider::queueFailure(new AiProviderUnavailableException('Fake provider is not configured.'));

        $this->generate($this->validPayload());

        self::assertResponseStatusCodeSame(503);
        self::assertSame('ai.provider_unavailable', $this->jsonResponse()['code']);
    }

    public function testRateLimitBlocksTheSixthRequestWithoutCallingTheProvider(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            FakeBrainstormingProvider::queueDocument($this->validDocument());
        }

        for ($i = 0; $i < 5; ++$i) {
            $this->generate($this->validPayload());
            self::assertResponseStatusCodeSame(200);
        }

        $this->generate($this->validPayload());

        self::assertResponseStatusCodeSame(429);
        $body = $this->jsonResponse();
        self::assertSame('ai.rate_limited', $body['code']);
        self::assertGreaterThan(0, $body['retryAfter']);
        $retryAfter = $this->client->getResponse()->headers->get('Retry-After');
        self::assertNotNull($retryAfter);
        self::assertSame((string) $body['retryAfter'], $retryAfter);
        self::assertCount(5, FakeBrainstormingProvider::receivedRequests());
    }

    public function testGenerationPersistsNoBoardOrCanvasData(): void
    {
        $boards = $this->countRows('boards');
        $elements = $this->countRows('canvas_elements');
        $timeline = $this->countRows('timeline_entries');
        $threads = $this->countRows('comment_threads');
        FakeBrainstormingProvider::queueDocument($this->validDocument());

        $this->generate($this->validPayload());

        self::assertResponseStatusCodeSame(200);
        self::assertSame($boards, $this->countRows('boards'));
        self::assertSame($elements, $this->countRows('canvas_elements'));
        self::assertSame($timeline, $this->countRows('timeline_entries'));
        self::assertSame($threads, $this->countRows('comment_threads'));
        // Only the three quota counters are written, and they hold no prompt.
        self::assertSame(3, $this->countRows('ai_generation_usage'));
    }

    public function testRejectsInvalidRequestBeforeSpendingQuota(): void
    {
        $this->generate($this->validPayload(['language' => 'it']));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->countRows('ai_generation_usage'));
    }

    /** @param array<string, mixed> $payload */
    private function generate(array $payload): void
    {
        $this->requestAs($this->editorToken, 'POST', self::ENDPOINT, $payload);
    }

    /** @param array<string, mixed> $payload */
    private function generateWithoutToken(array $payload): void
    {
        $this->requestAs(null, 'POST', self::ENDPOINT, $payload);
    }

    /** @param array<string, mixed> $overrides */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'topic' => 'Location de vélos à Tunis',
            'objective' => 'Identifier les premiers quartiers et le budget',
            'language' => 'fr',
            'detailLevel' => 'balanced',
        ], $overrides);
    }

    /** @param list<array<string, mixed>> $sections */
    private function document(array $sections): string
    {
        return json_encode([
            'proposal' => [
                'title' => 'Location de vélos à Tunis',
                'objective' => 'Identifier les premiers quartiers et le budget',
                'sections' => $sections,
            ],
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
    }

    private function validDocument(): string
    {
        return $this->document([$this->section(1, 3), $this->section(2, 3), $this->section(3, 3)]);
    }

    /** @return array<string, mixed> */
    private function section(int $index, int $noteCount): array
    {
        $notes = [];
        for ($note = 1; $note <= $noteCount; ++$note) {
            $notes[] = [
                'id' => 's'.$index.'-n'.$note,
                'type' => 'idea',
                'content' => sprintf('Section %d idea %d about bike sharing', $index, $note),
            ];
        }

        return ['id' => 's'.$index, 'title' => 'Section '.$index, 'category' => 'ideas', 'notes' => $notes];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<string>
     */
    private function detailFields(array $body): array
    {
        $details = $body['details'] ?? [];
        self::assertIsArray($details);
        $fields = [];
        foreach ($details as $detail) {
            self::assertIsArray($detail);
            $fields[] = (string) ($detail['field'] ?? '');
        }

        return $fields;
    }
}
