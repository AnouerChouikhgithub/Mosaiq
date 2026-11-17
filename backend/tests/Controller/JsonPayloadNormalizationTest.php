<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `{}` and `[]` are different JSON values. The payload decoder must keep them
 * apart so an empty metadata object is accepted while an empty array is not.
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
        $elements = $this->entityManager->getRepository(\App\Entity\CanvasElement::class)->findAll();
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
        $element = $this->entityManager->getRepository(\App\Entity\CanvasElement::class)->findAll()[0];
        self::assertSame(['stroke' => 'thin', 'align' => 'center'], $element->getMetadata());
    }

    public function testNonEmptyNestedArrayIsRejectedAsMetadata(): void
    {
        $this->postRaw($this->elementBody('["stroke"]'));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'Element metadata must be an object.'], $this->jsonResponse());
        self::assertSame(0, $this->countRows('canvas_elements'));
    }

    public function testEmptyObjectInsideMetadataSurvivesTheRoundTrip(): void
    {
        $this->postRaw($this->elementBody('{"stroke":"thin","layout":{}}'));

        self::assertResponseStatusCodeSame(201);
        self::assertStringContainsString('"layout":{}', $this->responseBody());

        $this->entityManager->clear();
        $element = $this->entityManager->getRepository(\App\Entity\CanvasElement::class)->findAll()[0];
        self::assertSame(['stroke' => 'thin', 'layout' => []], $element->getMetadata());
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
