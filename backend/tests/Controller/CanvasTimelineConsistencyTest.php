<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CanvasElement;
use App\Entity\TimelineEntry;

/**
 * Every canvas mutation and the timeline entry it produces must commit together:
 * a history write that fails has to roll that mutation back instead of leaving an
 * untracked canvas change behind.
 */
final class CanvasTimelineConsistencyTest extends ApiFunctionalTestCase
{
    public function testCreateCommitsTheElementAndItsTimelineEntryTogether(): void
    {
        $elementId = $this->createElement($this->editorToken, 'Atomic create');

        $this->entityManager->clear();
        $elements = $this->entityManager->getRepository(CanvasElement::class)->findAll();
        $entries = $this->entityManager->getRepository(TimelineEntry::class)->findAll();

        self::assertCount(1, $elements);
        self::assertSame($elementId, $elements[0]->getId());
        self::assertCount(1, $entries);
        self::assertSame('note', $entries[0]->getKind());
        self::assertSame([$elementId], array_column($entries[0]->getSnapshot() ?? [], 'id'));
    }

    public function testUpdateCommitsTheChangeAndItsTimelineEntryTogether(): void
    {
        $elementId = $this->createElement($this->editorToken, 'before');

        $this->requestAs($this->editorToken, 'PATCH', '/api/elements/'.$elementId, ['content' => 'after']);
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $element = $this->entityManager->find(CanvasElement::class, $elementId);
        self::assertInstanceOf(CanvasElement::class, $element);
        self::assertSame('after', $element->getContent());

        $updates = array_values(array_filter(
            $this->entityManager->getRepository(TimelineEntry::class)->findBy(['board' => $this->board]),
            static fn (TimelineEntry $entry): bool => str_contains($entry->getLabel(), 'updated'),
        ));
        self::assertCount(1, $updates);
        self::assertSame(['after'], array_column($updates[0]->getSnapshot() ?? [], 'content'));
    }

    public function testDeleteCommitsTheRemovalAndItsTimelineEntryTogether(): void
    {
        $elementId = $this->createElement($this->editorToken);

        $this->requestAs($this->editorToken, 'DELETE', '/api/elements/'.$elementId);
        self::assertResponseStatusCodeSame(204);

        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(CanvasElement::class, $elementId));

        $deletions = array_values(array_filter(
            $this->entityManager->getRepository(TimelineEntry::class)->findBy(['board' => $this->board]),
            static fn (TimelineEntry $entry): bool => str_contains($entry->getLabel(), 'deleted'),
        ));
        self::assertCount(1, $deletions);
        self::assertSame([], $deletions[0]->getSnapshot());
    }

    public function testFailedTimelineWriteRollsBackTheCanvasMutation(): void
    {
        // The failing request must reuse this entity manager so the injected
        // listener observes the flush it performs.
        $connection = $this->entityManager->getConnection();
        $this->failTimelinePersistence();

        $this->assertRequestFailedWithStorageError(function (): void {
            $this->requestAs($this->editorToken, 'POST', '/api/boards/'.$this->board->getId().'/elements', [
                'type' => 'note', 'x' => 10, 'y' => 20, 'width' => 220, 'height' => 140, 'content' => 'must roll back',
            ]);
        });

        // The canvas insert was flushed inside the transaction before the
        // timeline entry failed, so these counts prove the rollback is real.
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM canvas_elements'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM timeline_entries'));
    }

    public function testFailedTimelineWriteRestoresTheUpdatedElement(): void
    {
        $elementId = $this->persistElement('before');
        $connection = $this->entityManager->getConnection();
        $this->failTimelinePersistence();

        $this->assertRequestFailedWithStorageError(function () use ($elementId): void {
            $this->requestAs($this->editorToken, 'PATCH', '/api/elements/'.$elementId, [
                'content' => 'after', 'x' => 999,
            ]);
        });

        // The update was flushed before the timeline write failed, so the stored
        // row is the only proof that the transaction undid it.
        $stored = $connection->fetchAssociative('SELECT content, x FROM canvas_elements WHERE id = ?', [$elementId]);
        self::assertIsArray($stored);
        self::assertSame('before', $stored['content']);
        self::assertSame(120.0, (float) $stored['x']);
        self::assertSame(0, $this->countRows('timeline_entries'));
    }

    public function testFailedTimelineWriteRestoresTheDeletedElement(): void
    {
        $elementId = $this->persistElement('keep me');
        $connection = $this->entityManager->getConnection();
        $this->failTimelinePersistence();

        $this->assertRequestFailedWithStorageError(function () use ($elementId): void {
            $this->requestAs($this->editorToken, 'DELETE', '/api/elements/'.$elementId);
        });

        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM canvas_elements WHERE id = ?', [$elementId]));
        self::assertSame(0, $this->countRows('timeline_entries'));
    }

    /**
     * Stores an element without an HTTP request, so a failing test can keep its
     * one request for the mutation whose rollback it asserts.
     */
    private function persistElement(string $content): string
    {
        $element = new CanvasElement($this->board, $this->editor, 'note', 120.0, 130.0, 220.0, 150.0, 0.0, $content, 'amber');
        $this->entityManager->persist($element);
        $this->entityManager->flush();

        return $element->getId();
    }
}
