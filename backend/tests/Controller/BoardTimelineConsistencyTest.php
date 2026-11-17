<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Board;
use App\Entity\TimelineEntry;

/**
 * Creating or renaming a board writes a history entry in the same unit of work.
 * A failure of that write must leave the board exactly as it was.
 */
final class BoardTimelineConsistencyTest extends ApiFunctionalTestCase
{
    public function testBoardCreationPersistsTheBoardAndItsTimelineEntry(): void
    {
        $this->requestAs($this->ownerToken, 'POST', '/api/boards', ['title' => 'Atomic board']);
        self::assertResponseStatusCodeSame(201);
        $created = $this->jsonResponse();

        $this->entityManager->clear();
        $entries = $this->entityManager->getRepository(TimelineEntry::class)->findAll();

        self::assertSame('Atomic board', $created['title']);
        self::assertCount(1, $entries);
        self::assertSame('board', $entries[0]->getKind());
        self::assertSame($created['id'], $entries[0]->getBoard()->getId());
        self::assertSame(2, $this->countRows('boards'));
    }

    public function testFailedTimelineWriteRollsBackBoardCreation(): void
    {
        $this->failTimelinePersistence();

        $this->assertRequestFailedWithStorageError(function (): void {
            $this->requestAs($this->ownerToken, 'POST', '/api/boards', ['title' => 'Atomic board']);
        });

        // Only the fixture board remains: the created board did not survive
        // without the history entry that commit was supposed to write with it.
        self::assertSame(1, $this->countRows('boards'));
        self::assertSame(0, $this->countRows('timeline_entries'));
    }

    public function testRenamePersistsTheTitleAndItsTimelineEntry(): void
    {
        $this->requestAs($this->editorToken, 'PATCH', '/api/boards/'.$this->board->getId(), ['title' => 'Renamed board']);
        self::assertResponseIsSuccessful();
        self::assertSame('Renamed board', $this->jsonResponse()['title']);

        $this->entityManager->clear();
        $board = $this->entityManager->find(Board::class, $this->board->getId());
        self::assertNotNull($board);
        self::assertSame('Renamed board', $board->getTitle());

        $renames = array_values(array_filter(
            $this->entityManager->getRepository(TimelineEntry::class)->findAll(),
            static fn (TimelineEntry $entry): bool => 'rename' === $entry->getKind(),
        ));
        self::assertCount(1, $renames);
        self::assertStringContainsString('renamed the board', $renames[0]->getLabel());
    }

    public function testFailedTimelineWriteRollsBackTheRename(): void
    {
        $connection = $this->entityManager->getConnection();
        $this->failTimelinePersistence();

        $this->assertRequestFailedWithStorageError(function (): void {
            $this->requestAs($this->editorToken, 'PATCH', '/api/boards/'.$this->board->getId(), ['title' => 'Renamed board']);
        });

        // The title was flushed before the timeline write failed, so the stored
        // row proves the transaction restored it.
        self::assertSame('Authorized board', $connection->fetchOne('SELECT title FROM boards WHERE id = ?', [$this->board->getId()]));
        self::assertSame(0, $this->countRows('timeline_entries'));
    }

    public function testRenamingToTheSameTitleKeepsTheExistingContract(): void
    {
        $this->requestAs($this->editorToken, 'PATCH', '/api/boards/'.$this->board->getId(), ['title' => 'Authorized board']);
        self::assertResponseIsSuccessful();
        self::assertSame('Authorized board', $this->jsonResponse()['title']);

        // The endpoint historically records every rename request, including a
        // no-op one; this sprint preserves that contract rather than changing it.
        $this->entityManager->clear();
        self::assertSame(1, $this->countRows('timeline_entries'));
    }

    public function testAViewerCannotRenameTheBoard(): void
    {
        $connection = $this->entityManager->getConnection();

        $this->requestAs($this->viewerToken, 'PATCH', '/api/boards/'.$this->board->getId(), ['title' => 'Tampered']);
        self::assertResponseStatusCodeSame(403);

        self::assertSame('Authorized board', $connection->fetchOne('SELECT title FROM boards WHERE id = ?', [$this->board->getId()]));
        self::assertSame(0, $this->countRows('timeline_entries'));
    }
}
