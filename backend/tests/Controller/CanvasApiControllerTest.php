<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CanvasElement;

final class CanvasApiControllerTest extends ApiFunctionalTestCase
{
    public function testCreateRejectsNonNumericGeometryInsteadOfStoringItAtTheOrigin(): void
    {
        $this->requestAs($this->editorToken, 'POST', '/api/boards/'.$this->board->getId().'/elements', [
            'type' => 'note', 'x' => 'left', 'y' => 130, 'width' => 220, 'height' => 150,
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => '"x" must be a finite number.'], $this->jsonResponse());

        $this->entityManager->clear();
        self::assertCount(0, $this->entityManager->getRepository(CanvasElement::class)->findAll());
    }

    public function testCreateRejectsNonObjectMetadataAndNonStringType(): void
    {
        $base = ['x' => 10, 'y' => 20, 'width' => 220, 'height' => 150];

        $this->requestAs($this->editorToken, 'POST', '/api/boards/'.$this->board->getId().'/elements', $base + [
            'type' => 'note', 'metadata' => ['fromId', 'toId'],
        ]);
        self::assertResponseStatusCodeSame(422);

        $this->requestAs($this->editorToken, 'POST', '/api/boards/'.$this->board->getId().'/elements', $base + [
            'type' => ['note'],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testUpdateRejectsInvalidGeometryAndKeepsTheStoredValue(): void
    {
        $elementId = $this->createElement($this->editorToken, 'stable');

        $this->requestAs($this->editorToken, 'PATCH', '/api/elements/'.$elementId, ['width' => 'wide']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => '"width" must be a finite number.'], $this->jsonResponse());

        $this->entityManager->clear();
        $stored = $this->entityManager->getRepository(CanvasElement::class)->find($elementId);
        self::assertInstanceOf(CanvasElement::class, $stored);
        self::assertSame(220.0, $stored->getWidth());
        self::assertSame('stable', $stored->getContent());
    }

    public function testActivePublicBoardExposesItsElementsToAnonymousReaders(): void
    {
        $elementId = $this->createElement($this->editorToken, 'Public canvas note');
        $this->board->setPublicAccess(true);
        $this->entityManager->flush();

        $this->requestAs(null, 'GET', '/api/boards/'.$this->board->getId().'/elements');

        self::assertResponseIsSuccessful();
        $elements = $this->jsonResponse()['elements'];
        self::assertCount(1, $elements);
        self::assertSame($elementId, $elements[0]['id']);
        self::assertSame('Public canvas note', $elements[0]['content']);
        self::assertSame('', $elements[0]['author']['id']);
    }

    public function testArchivedPublicBoardHidesItsElementsFromAnonymousReaders(): void
    {
        $this->createElement($this->editorToken, 'Archived canvas note');
        $this->board->setPublicAccess(true);
        $this->entityManager->flush();

        $this->requestAs($this->ownerToken, 'POST', '/api/boards/'.$this->board->getId().'/archive');
        self::assertResponseIsSuccessful();

        $this->requestAs(null, 'GET', '/api/boards/'.$this->board->getId().'/elements');
        self::assertResponseStatusCodeSame(403);

        // Restoring the board makes the public link readable again.
        $this->requestAs($this->ownerToken, 'POST', '/api/boards/'.$this->board->getId().'/restore');
        self::assertResponseIsSuccessful();
        $this->requestAs(null, 'GET', '/api/boards/'.$this->board->getId().'/elements');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->jsonResponse()['elements']);
    }

    public function testTrashedPublicBoardHidesItsElementsFromAnonymousReaders(): void
    {
        $this->createElement($this->editorToken, 'Trashed canvas note');
        $this->board->setPublicAccess(true);
        $this->entityManager->flush();

        $this->requestAs($this->ownerToken, 'POST', '/api/boards/'.$this->board->getId().'/trash');
        self::assertResponseIsSuccessful();

        $this->requestAs(null, 'GET', '/api/boards/'.$this->board->getId().'/elements');
        self::assertResponseStatusCodeSame(403);
        $this->requestAs(null, 'GET', '/api/boards/'.$this->board->getId().'/timeline');
        self::assertResponseStatusCodeSame(403);
    }

    public function testPrivateBoardCanvasStaysForbiddenForAnonymousReaders(): void
    {
        $this->createElement($this->editorToken);

        $this->requestAs(null, 'GET', '/api/boards/'.$this->board->getId().'/elements');
        self::assertResponseStatusCodeSame(403);
    }

    public function testBoardMembersKeepFullCanvasAccess(): void
    {
        $this->createElement($this->editorToken, 'Member canvas note');

        $this->requestAs($this->viewerToken, 'GET', '/api/boards/'.$this->board->getId().'/elements');
        self::assertResponseIsSuccessful();
        $elements = $this->jsonResponse()['elements'];
        self::assertCount(1, $elements);
        self::assertSame($this->editor->getId(), $elements[0]['author']['id']);
        self::assertSame($this->editor->getName(), $elements[0]['author']['name']);

        // Board membership is still required, even for the same workspace.
        $this->requestAs($this->outsiderToken, 'GET', '/api/boards/'.$this->board->getId().'/elements');
        self::assertResponseStatusCodeSame(403);

        // Archived boards stay writable-proof: the editor cannot mutate them.
        $this->requestAs($this->ownerToken, 'POST', '/api/boards/'.$this->board->getId().'/archive');
        self::assertResponseIsSuccessful();
        $this->requestAs($this->editorToken, 'POST', '/api/boards/'.$this->board->getId().'/elements', [
            'type' => 'note', 'x' => 1, 'y' => 1, 'width' => 10, 'height' => 10,
        ]);
        self::assertResponseStatusCodeSame(403);
    }
}
