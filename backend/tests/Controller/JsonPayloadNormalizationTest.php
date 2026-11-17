<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CanvasElement;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `{}` and `[]` are different JSON values. The payload decoder keeps them apart
 * so an empty metadata object is accepted while an empty array is not, and the
 * stored document must still distinguish them after a reload: a nested `{}`
 * that comes back as `[]` is data loss, not a formatting detail.
 */
final class JsonPayloadNormalizationTest extends ApiFunctionalTestCase
{
    private function postRaw(string $body, ?string $token = null): void
    {
        $this->requestWithRawBody(
            'POST',
            '/api/boards/'.$this->board->getId().'/elements',
            $body,
            $token ?? $this->editorToken,
        );
    }

    private function elementBody(string $metadataJson, string $content = 'Normalised note'): string
    {
        return sprintf(
            '{"type":"note","x":10,"y":10,"width":220,"height":140,"content":"%s","metadata":%s}',
            $content,
            $metadataJson,
        );
    }

    private function responseBody(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    private function createdElementId(): string
    {
        $id = $this->jsonResponse()['id'] ?? null;
        if (!is_string($id)) {
            throw new \RuntimeException('The create response has no element id.');
        }

        return $id;
    }

    /** The metadata column as stored, read over a connection of its own. */
    private function storedMetadata(string $elementId): string
    {
        $stored = $this->fetchColumn('SELECT metadata FROM canvas_elements WHERE id = ?', [$elementId]);
        if (!is_string($stored)) {
            throw new \RuntimeException('The stored element has no metadata value.');
        }

        return $stored;
    }

    /**
     * Reads the canvas back through the real GET endpoint with a freshly booted
     * kernel, so the answer comes from the database and not from the identity
     * map of the request that wrote it.
     */
    private function reloadThroughApi(): string
    {
        $this->entityManager->clear();
        $this->ensureKernelShutdown();
        $this->client = self::createClient();
        $this->client->request('GET', '/api/boards/'.$this->board->getId().'/elements', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->editorToken,
        ]);
        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    public function testEmptyTopLevelObjectIsTreatedAsAnObject(): void
    {
        $this->postRaw('{}');

        // Reaching field validation proves the empty document decoded as an
        // object instead of being rejected as a non-object body.
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'Missing "type" for the canvas element.'], $this->jsonResponse());
    }

    public function testEmptyNestedObjectIsAcceptedAsMetadata(): void
    {
        $this->postRaw($this->elementBody('{}'));

        self::assertResponseStatusCodeSame(201);
        // The wire shape stays an object: `[]` here would be the array bug.
        self::assertStringContainsString('"metadata":{}', $this->responseBody());

        $this->entityManager->clear();
        $elements = $this->entityManager->getRepository(CanvasElement::class)->findAll();
        self::assertCount(1, $elements);
        self::assertSame([], $elements[0]->getMetadata());
    }

    public function testEmptyNestedArrayIsRejectedAsMetadata(): void
    {
        $this->postRaw($this->elementBody('[]'));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'Element metadata must be an object.'], $this->jsonResponse());
        self::assertSame(0, $this->countRows('canvas_elements'));
    }

    public function testNonEmptyNestedObjectIsAccepted(): void
    {
        $this->postRaw($this->elementBody('{"stroke":"thin","align":"center"}'));

        self::assertResponseStatusCodeSame(201);

        $this->entityManager->clear();
        $element = $this->entityManager->getRepository(CanvasElement::class)->findAll()[0];
        self::assertSame(['stroke' => 'thin', 'align' => 'center'], $element->getMetadata());
    }

    public function testNonEmptyNestedArrayIsRejectedAsMetadata(): void
    {
        $this->postRaw($this->elementBody('["stroke"]'));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'Element metadata must be an object.'], $this->jsonResponse());
        self::assertSame(0, $this->countRows('canvas_elements'));
    }

    /**
     * The defect: the write was correct, the read was not. The document is
     * created, the identity map is dropped, the entity is reloaded and the API
     * GET has to answer the nested `{}` that was stored.
     */
    public function testNestedEmptyObjectSurvivesPersistenceAndTheApiGet(): void
    {
        $this->postRaw($this->elementBody('{"stroke":"thin","layout":{}}'));
        self::assertResponseStatusCodeSame(201);
        $elementId = $this->createdElementId();

        self::assertSame('{"stroke":"thin","layout":{}}', $this->storedMetadata($elementId));

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(CanvasElement::class, $elementId);
        self::assertInstanceOf(CanvasElement::class, $reloaded);
        $metadata = $reloaded->getMetadata() ?? [];
        self::assertInstanceOf(\stdClass::class, $metadata['layout'] ?? null);
        self::assertStringContainsString('"layout":{}', (string) json_encode($metadata));

        $body = $this->reloadThroughApi();
        self::assertStringContainsString('"metadata":{"stroke":"thin","layout":{}}', $body);
        self::assertStringNotContainsString('"layout":[]', $body);
    }

    public function testNestedNonEmptyObjectsSurviveTheRoundTrip(): void
    {
        $this->postRaw($this->elementBody('{"layout":{"align":"left","gaps":[4,8]},"stroke":"thin"}'));
        self::assertResponseStatusCodeSame(201);

        $body = $this->reloadThroughApi();
        self::assertStringContainsString('"metadata":{"layout":{"align":"left","gaps":[4,8]},"stroke":"thin"}', $body);
    }

    /**
     * The fix must not turn empty arrays into objects: only a value that was a
     * JSON object becomes one again, and arrays stay arrays.
     */
    public function testNestedArraysStayArraysIncludingEmptyOnes(): void
    {
        $this->postRaw($this->elementBody('{"tags":[],"layers":[{"id":1}],"emptyObject":{}}'));
        self::assertResponseStatusCodeSame(201);

        $body = $this->reloadThroughApi();
        self::assertStringContainsString('"metadata":{"tags":[],"layers":[{"id":1}],"emptyObject":{}}', $body);
        self::assertStringNotContainsString('"tags":{}', $body);
    }

    public function testRootEmptyObjectStaysAnObjectThroughTheApi(): void
    {
        $this->postRaw($this->elementBody('{}'));
        self::assertResponseStatusCodeSame(201);

        self::assertStringContainsString('"metadata":{}', $this->reloadThroughApi());
    }

    public function testRootEmptyArrayIsRejectedWithoutStoringAnything(): void
    {
        $this->postRaw($this->elementBody('[]'));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->countRows('canvas_elements'));
        self::assertSame(0, $this->countRows('timeline_entries'));
    }

    public function testUpdateThenGetKeepsTheNestedEmptyObject(): void
    {
        $this->postRaw($this->elementBody('{"stroke":"thin"}'));
        self::assertResponseStatusCodeSame(201);
        $elementId = $this->createdElementId();

        $this->requestWithRawBody(
            'PATCH',
            '/api/elements/'.$elementId,
            '{"metadata":{"stroke":"bold","layout":{}}}',
            $this->editorToken,
        );

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('"metadata":{"stroke":"bold","layout":{}}', $this->responseBody());
        self::assertStringContainsString(
            '"metadata":{"stroke":"bold","layout":{}}',
            $this->reloadThroughApi(),
        );
    }

    public function testEmptyMetadataIsAcceptedOnUpdate(): void
    {
        $elementId = $this->createElement($this->editorToken, 'Styled note');

        $this->requestWithRawBody(
            'PATCH',
            '/api/elements/'.$elementId,
            '{"metadata":{}}',
            $this->editorToken,
        );

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('"metadata":{}', $this->responseBody());
        self::assertStringContainsString('"metadata":{}', $this->reloadThroughApi());
    }

    /** @return iterable<string, array{string}> */
    public static function nonObjectBodies(): iterable
    {
        yield 'empty array' => ['[]'];
        yield 'array with a value' => ['["unexpected"]'];
        yield 'string' => ['"text"'];
        yield 'number' => ['123'];
        yield 'boolean' => ['true'];
        yield 'null' => ['null'];
    }

    #[DataProvider('nonObjectBodies')]
    public function testNonObjectBodiesAreRejected(string $body): void
    {
        $this->postRaw($body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'Request body must be a JSON object.'], $this->jsonResponse());
        self::assertSame(0, $this->countRows('canvas_elements'));
    }

    public function testMalformedJsonIsRejectedAsABadRequest(): void
    {
        $this->postRaw('{"type":');

        self::assertResponseStatusCodeSame(400);
        self::assertSame(['error' => 'Malformed JSON payload.'], $this->jsonResponse());
        self::assertSame(0, $this->countRows('canvas_elements'));
    }
}
