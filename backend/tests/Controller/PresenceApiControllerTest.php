<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Component\HttpFoundation\StreamedResponse;

final class PresenceApiControllerTest extends ApiFunctionalTestCase
{
    public function testStreamRequiresBoardMembershipAndEmitsPresenceAndCanvasEvents(): void
    {
        $this->requestAs(null, 'GET', '/api/boards/'.$this->board->getId().'/stream?ticks=1');
        self::assertResponseStatusCodeSame(401);

        $this->requestAs($this->outsiderToken, 'GET', '/api/boards/'.$this->board->getId().'/stream?ticks=1');
        self::assertResponseStatusCodeSame(403);

        $this->requestAs($this->ownerToken, 'GET', '/api/boards/'.$this->board->getId().'/stream?ticks=1');
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/event-stream', (string) $this->client->getResponse()->headers->get('Content-Type'));

        $response = $this->client->getResponse();
        self::assertInstanceOf(StreamedResponse::class, $response);
        $callback = $response->getCallback();
        self::assertNotNull($callback);
        ob_start();
        $callback();
        $body = (string) ob_get_clean();
        self::assertStringContainsString('retry: 1000', $body);
        self::assertStringContainsString('event: ready', $body);
        self::assertStringContainsString('event: presence', $body);
        self::assertStringContainsString('event: canvas', $body);
        self::assertStringContainsString('"boardId":"'.$this->board->getId().'"', $body);
    }

    public function testPresenceRequiresBoardAccessAndUsesAuthenticatedUser(): void
    {
        $this->requestAs(null, 'POST', '/api/boards/'.$this->board->getId().'/presence', ['x' => 42, 'y' => 75, 'tool' => 'comment']);
        self::assertResponseStatusCodeSame(401);

        $this->requestAs($this->viewerToken, 'POST', '/api/boards/'.$this->board->getId().'/presence', ['x' => 42, 'y' => 75, 'tool' => 'comment']);
        self::assertResponseIsSuccessful();

        $this->requestAs($this->ownerToken, 'GET', '/api/boards/'.$this->board->getId().'/presence');
        self::assertResponseIsSuccessful();
        $presence = $this->jsonResponse()['presence'];
        self::assertCount(1, $presence);
        self::assertSame($this->viewer->getId(), $presence[0]['user']['id']);
        self::assertEquals(42.0, $presence[0]['x']);

        $this->requestAs($this->outsiderToken, 'GET', '/api/boards/'.$this->board->getId().'/presence');
        self::assertResponseStatusCodeSame(403);
    }
}
