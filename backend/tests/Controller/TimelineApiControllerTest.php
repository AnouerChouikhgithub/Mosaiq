<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CanvasElement;
use App\Entity\TimelineEntry;

final class TimelineApiControllerTest extends ApiFunctionalTestCase
{
    public function testCreateAndUpdateRecordSnapshotsAndRestoreHistoricalCanvas(): void
    {
        $elementId = $this->createElement($this->editorToken, 'before');
        $this->requestAs($this->editorToken, 'PATCH', '/api/elements/'.$elementId, ['content' => 'after']);
        self::assertResponseIsSuccessful();

        $this->requestAs($this->editorToken, 'GET', '/api/boards/'.$this->board->getId().'/timeline');
        self::assertResponseIsSuccessful();
        $timeline = $this->jsonResponse()['timeline'];
        self::assertGreaterThanOrEqual(2, count($timeline));
        $firstSnapshot = null;
        foreach ($timeline as $entry) {
            if (isset($entry['snapshot'][0]['content']) && 'before' === $entry['snapshot'][0]['content']) {
                $firstSnapshot = $entry;
                break;
            }
        }
        self::assertNotNull($firstSnapshot);

        $this->requestAs($this->editorToken, 'POST', '/api/boards/'.$this->board->getId().'/restore/'.$firstSnapshot['id']);
        self::assertResponseIsSuccessful();
        self::assertSame('before', $this->jsonResponse()['elements'][0]['content']);

        $this->entityManager->clear();
        $entries = $this->entityManager->getRepository(TimelineEntry::class)->findBy(['board' => $this->board]);
        self::assertNotEmpty($entries);
        self::assertNotEmpty(array_filter($entries, static fn (TimelineEntry $item): bool => str_contains($item->getLabel(), 'restored')));
    }

    public function testDeleteAlsoRecordsSnapshotBeforeElementRemoval(): void
    {
        $elementId = $this->createElement($this->editorToken);
        $this->requestAs($this->editorToken, 'DELETE', '/api/elements/'.$elementId);
        self::assertResponseStatusCodeSame(204);
        $this->entityManager->clear();
        self::assertNull($this->entityManager->getRepository(CanvasElement::class)->find($elementId));
        $entries = $this->entityManager->getRepository(TimelineEntry::class)->findBy(['board' => $this->board]);
        self::assertNotEmpty($entries);
        $deletion = array_values(array_filter($entries, static fn (TimelineEntry $item): bool => str_contains($item->getLabel(), 'deleted')));
        self::assertCount(1, $deletion);
        self::assertSame([], $deletion[0]->getSnapshot());
    }
}
