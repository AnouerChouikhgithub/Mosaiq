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
}
