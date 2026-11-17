<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CanvasElement;
use App\Entity\TimelineEntry;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * The canvas mutation and the timeline entry it produces must commit together:
 * a history write that fails has to roll the canvas change back instead of
 * leaving an untracked element behind.
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
        $this->entityManager->getEventManager()->addEventListener([Events::onFlush], new class {
            public function onFlush(OnFlushEventArgs $args): void
            {
                foreach ($args->getObjectManager()->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
                    if ($entity instanceof TimelineEntry) {
                        throw new \RuntimeException('Simulated timeline storage failure.');
                    }
                }
            }
        });

        $failed = false;
        try {
            $this->requestAs($this->editorToken, 'POST', '/api/boards/'.$this->board->getId().'/elements', [
                'type' => 'note', 'x' => 10, 'y' => 20, 'width' => 220, 'height' => 140, 'content' => 'must roll back',
            ]);
            $failed = $this->client->getResponse()->getStatusCode() >= 500;
        } catch (\RuntimeException $exception) {
            $failed = str_contains($exception->getMessage(), 'Simulated timeline storage failure.');
        }

        self::assertTrue($failed, 'The timeline failure must surface instead of being swallowed.');

        // The canvas insert was flushed inside the transaction before the
        // timeline entry failed, so these counts prove the rollback is real.
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM canvas_elements'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM timeline_entries'));
    }
}
