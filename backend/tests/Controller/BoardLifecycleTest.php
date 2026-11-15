<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Board;
use App\Entity\User;
use App\Entity\Workspace;

final class BoardLifecycleTest extends ApiFunctionalTestCase
{
    public function testOwnerCanArchiveAndRestoreWithoutLosingContent(): void
    {
        $elementId = $this->createElement($this->editorToken, 'Persisted before archive');

        $this->requestAs($this->ownerToken, 'POST', '/api/boards/'.$this->board->getId().'/archive');
        self::assertResponseIsSuccessful();
        self::assertSame('archived', $this->jsonResponse()['status']);
        self::assertNotNull($this->jsonResponse()['archivedAt']);

        $this->requestAs($this->ownerToken, 'GET', '/api/boards');
        self::assertSame([], $this->jsonResponse()['boards']);

        $this->requestAs($this->ownerToken, 'GET', '/api/boards?status=archived');
        self::assertCount(1, $this->jsonResponse()['boards']);
        self::assertSame($this->board->getId(), $this->jsonResponse()['boards'][0]['id']);

        $this->requestAs($this->ownerToken, 'GET', '/api/boards/'.$this->board->getId());
        self::assertResponseIsSuccessful();
        self::assertSame('archived', $this->jsonResponse()['status']);

        $this->requestAs($this->editorToken, 'GET', '/api/boards/'.$this->board->getId());
        self::assertResponseIsSuccessful();

        $this->requestAs($this->ownerToken, 'PATCH', '/api/boards/'.$this->board->getId(), ['title' => 'Archived edit']);
        self::assertResponseStatusCodeSame(403);
        $this->requestAs($this->editorToken, 'PATCH', '/api/boards/'.$this->board->getId(), ['title' => 'Archived edit']);
        self::assertResponseStatusCodeSame(403);

        $this->requestAs($this->ownerToken, 'POST', '/api/boards/'.$this->board->getId().'/restore');
        self::assertResponseIsSuccessful();
        self::assertSame('active', $this->jsonResponse()['status']);
        self::assertNull($this->jsonResponse()['archivedAt']);

        $this->requestAs($this->ownerToken, 'GET', '/api/boards');
        self::assertCount(1, $this->jsonResponse()['boards']);

        $this->requestAs($this->ownerToken, 'GET', '/api/boards/'.$this->board->getId().'/elements');
        self::assertResponseIsSuccessful();
        $elements = $this->jsonResponse()['elements'];
        self::assertCount(1, $elements);
        self::assertSame($elementId, $elements[0]['id']);

        $this->requestAs($this->ownerToken, 'GET', '/api/boards/'.$this->board->getId().'/timeline');
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, count($this->jsonResponse()['timeline']));
    }

    public function testTrashIsRecoverableAndHidesContentUntilRestored(): void
    {
        $this->createElement($this->editorToken, 'Persisted before trash');

        $this->requestAs($this->ownerToken, 'POST', '/api/boards/'.$this->board->getId().'/trash');
        self::assertResponseIsSuccessful();
        self::assertSame('trashed', $this->jsonResponse()['status']);
        self::assertNotNull($this->jsonResponse()['trashedAt']);

        $this->requestAs($this->ownerToken, 'GET', '/api/boards');
        self::assertSame([], $this->jsonResponse()['boards']);
        $this->requestAs($this->ownerToken, 'GET', '/api/boards?status=archived');
        self::assertSame([], $this->jsonResponse()['boards']);

        $this->requestAs($this->ownerToken, 'GET', '/api/boards?status=trashed');
        self::assertCount(1, $this->jsonResponse()['boards']);
        $trashed = $this->jsonResponse()['boards'][0];
        self::assertSame('trashed', $trashed['status']);
        self::assertArrayNotHasKey('elements', $trashed);
        self::assertArrayNotHasKey('comments', $trashed);
        self::assertArrayNotHasKey('timeline', $trashed);

        $this->requestAs($this->ownerToken, 'GET', '/api/boards/'.$this->board->getId());
        self::assertResponseStatusCodeSame(403);
        $this->requestAs($this->ownerToken, 'GET', '/api/boards/'.$this->board->getId().'/elements');
        self::assertResponseStatusCodeSame(403);

        $this->requestAs($this->ownerToken, 'POST', '/api/boards/'.$this->board->getId().'/restore');
        self::assertResponseIsSuccessful();
        self::assertSame('active', $this->jsonResponse()['status']);

        $this->requestAs($this->ownerToken, 'GET', '/api/boards');
        self::assertCount(1, $this->jsonResponse()['boards']);
        $this->requestAs($this->ownerToken, 'GET', '/api/boards/'.$this->board->getId().'/elements');
        self::assertCount(1, $this->jsonResponse()['elements']);
    }

    public function testArchivedBoardCanBeTrashedDirectlyAndRestored(): void
    {
        $this->requestAs($this->ownerToken, 'POST', '/api/boards/'.$this->board->getId().'/archive');
        self::assertResponseIsSuccessful();

        $this->requestAs($this->ownerToken, 'POST', '/api/boards/'.$this->board->getId().'/trash');
        self::assertResponseIsSuccessful();
        self::assertSame('trashed', $this->jsonResponse()['status']);
        self::assertNull($this->jsonResponse()['archivedAt']);

        $this->requestAs($this->ownerToken, 'GET', '/api/boards?status=archived');
        self::assertSame([], $this->jsonResponse()['boards']);
        $this->requestAs($this->ownerToken, 'GET', '/api/boards?status=trashed');
        self::assertCount(1, $this->jsonResponse()['boards']);
    }

    public function testInvalidTransitionsAreRejectedAndRestoreIsIdempotent(): void
    {
        $this->requestAs($this->ownerToken, 'POST', '/api/boards/'.$this->board->getId().'/archive');
        self::assertResponseIsSuccessful();
        $this->requestAs($this->ownerToken, 'POST', '/api/boards/'.$this->board->getId().'/archive');
        self::assertResponseStatusCodeSame(409);

        $this->requestAs($this->ownerToken, 'POST', '/api/boards/'.$this->board->getId().'/restore');
        self::assertResponseIsSuccessful();
        $this->requestAs($this->ownerToken, 'POST', '/api/boards/'.$this->board->getId().'/restore');
        self::assertResponseIsSuccessful();

        $this->requestAs($this->ownerToken, 'POST', '/api/boards/'.$this->board->getId().'/trash');
        self::assertResponseIsSuccessful();
        $this->requestAs($this->ownerToken, 'POST', '/api/boards/'.$this->board->getId().'/trash');
        self::assertResponseStatusCodeSame(409);
    }

    public function testLifecycleOperationsAreWorkspaceAndRoleScoped(): void
    {
        $this->requestAs($this->editorToken, 'POST', '/api/boards/'.$this->board->getId().'/archive');
        self::assertResponseStatusCodeSame(403);
        $this->requestAs($this->viewerToken, 'POST', '/api/boards/'.$this->board->getId().'/trash');
        self::assertResponseStatusCodeSame(403);
        $this->requestAs($this->outsiderToken, 'POST', '/api/boards/'.$this->board->getId().'/trash');
        self::assertResponseStatusCodeSame(403);
        $this->requestAs(null, 'POST', '/api/boards/'.$this->board->getId().'/archive');
        self::assertResponseStatusCodeSame(401);

        $this->entityManager->clear();
        $workspace = $this->entityManager->find(Workspace::class, $this->otherWorkspace->getId());
        $outsider = $this->entityManager->find(User::class, $this->outsider->getId());
        self::assertInstanceOf(Workspace::class, $workspace);
        self::assertInstanceOf(User::class, $outsider);
        $otherBoard = new Board($workspace, 'Other workspace board');
        $otherBoard->addMember($outsider, 'Owner');
        $this->entityManager->persist($otherBoard);
        $this->entityManager->flush();

        $this->requestAs($this->ownerToken, 'POST', '/api/boards/'.$otherBoard->getId().'/archive');
        self::assertResponseStatusCodeSame(403);
        $this->requestAs($this->outsiderToken, 'POST', '/api/boards/'.$otherBoard->getId().'/archive');
        self::assertResponseIsSuccessful();

        $this->requestAs($this->ownerToken, 'GET', '/api/boards?status=archived');
        self::assertSame([], $this->jsonResponse()['boards']);
        $this->requestAs($this->outsiderToken, 'GET', '/api/boards?status=archived');
        self::assertCount(1, $this->jsonResponse()['boards']);

        $this->requestAs($this->ownerToken, 'GET', '/api/boards?status=deleted');
        self::assertResponseStatusCodeSame(422);
        $this->requestAs($this->ownerToken, 'POST', '/api/boards/00000000-0000-0000-0000-000000000000/archive');
        self::assertResponseStatusCodeSame(404);
    }

    public function testTrashedPublicBoardCannotBeReadAnonymously(): void
    {
        $this->requestAs($this->ownerToken, 'PATCH', '/api/boards/'.$this->board->getId().'/access', ['publicAccess' => true]);
        self::assertResponseIsSuccessful();

        $this->requestAs(null, 'GET', '/api/boards/'.$this->board->getId());
        self::assertResponseIsSuccessful();

        $this->requestAs($this->ownerToken, 'POST', '/api/boards/'.$this->board->getId().'/trash');
        self::assertResponseIsSuccessful();

        $this->requestAs(null, 'GET', '/api/boards/'.$this->board->getId());
        self::assertResponseStatusCodeSame(403);
        $this->requestAs(null, 'GET', '/api/boards/'.$this->board->getId().'/timeline');
        self::assertResponseStatusCodeSame(403);
    }
}
