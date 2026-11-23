<?php

declare(strict_types=1);

namespace App\Tests\Controller;

/**
 * Sprint 2 — the approved proposal import, exercised over the real HTTP API.
 *
 * The frontend import path performs exactly these calls: one `POST /api/boards`
 * and one idempotent `POST /api/boards/{id}/elements` per note. This test uses
 * deterministic coordinates from the frontend layout algorithm (3 sections × 3
 * notes → 220×140 notes, columns at x = 350/590/830, bands at y = 258/430/602)
 * and asserts the persistence, timeline, collaboration and rollback guarantees
 * without any AI provider call.
 */
final class AiProposalImportTest extends ApiFunctionalTestCase
{
    private const BOARD_TITLE = 'Vélos partagés à Tunis';

    private const NOTE_COLORS = ['amber', 'rose', 'sky', 'mint', 'lilac'];

    public function testApprovedProposalImportsIntoAnOrdinaryBoard(): void
    {
        $boardId = $this->createBoardAsOwner();

        foreach ($this->plan() as $note) {
            $this->postNote($boardId, $note);
        }

        $this->requestAs($this->ownerToken, 'GET', '/api/boards/'.$boardId);
        self::assertResponseStatusCodeSame(200);
        $board = $this->jsonResponse();
        self::assertSame(self::BOARD_TITLE, $board['title']);
        self::assertSame(self::NOTE_COLORS, $board['noteColors']);
        self::assertSame(9, $board['elementCount']);
        self::assertSame('active', $board['status']);

        $elements = $board['elements'];
        self::assertCount(9, $elements);

        $byContent = [];
        foreach ($elements as $element) {
            $byContent[$element['content']] = $element;
            self::assertSame('note', $element['type']);
            self::assertSame(0.0, (float) $element['rotation']);
            self::assertContains($element['color'], self::NOTE_COLORS);
            self::assertIsArray($element['metadata']);
            self::assertSame('none', $element['metadata']['stroke']);
            self::assertSame('m', $element['metadata']['textSize']);
            self::assertSame('left', $element['metadata']['align']);
        }

        // Section/note mapping survives persistence, including the semantic AI
        // metadata the canvas ignores.
        $secondSectionFirstNote = $byContent['Options to explore note 2'];
        self::assertSame('sky', $secondSectionFirstNote['color']);
        self::assertSame('question', $secondSectionFirstNote['metadata']['aiNoteType']);
        self::assertSame('s2', $secondSectionFirstNote['metadata']['aiSectionId']);
        self::assertSame('Options to explore', $secondSectionFirstNote['metadata']['aiSectionTitle']);

        // Exact coordinates, no overlaps, nothing outside the 1400×880 canvas.
        foreach ($this->plan() as $note) {
            $stored = $byContent[$note['content']];
            self::assertSame((float) $note['x'], (float) $stored['x']);
            self::assertSame((float) $note['y'], (float) $stored['y']);
            self::assertSame(220.0, (float) $stored['width']);
            self::assertSame(140.0, (float) $stored['height']);
            self::assertGreaterThanOrEqual(0, (float) $stored['x']);
            self::assertGreaterThanOrEqual(0, (float) $stored['y']);
            self::assertLessThanOrEqual(1400, (float) $stored['x'] + (float) $stored['width']);
            self::assertLessThanOrEqual(880, (float) $stored['y'] + (float) $stored['height']);
        }
        $this->assertNoOverlap($elements);

        // Timeline: the board entry plus one ordinary note entry per note.
        $this->requestAs($this->ownerToken, 'GET', '/api/boards/'.$boardId.'/timeline');
        self::assertResponseStatusCodeSame(200);
        $timeline = $this->jsonResponse()['timeline'];
        self::assertCount(10, $timeline);
        self::assertSame(1, count(array_filter($timeline, static fn (array $entry): bool => 'board' === $entry['kind'])));
        self::assertSame(9, count(array_filter($timeline, static fn (array $entry): bool => 'note' === $entry['kind'])));

        // Reopening returns the same board, notes and history.
        $this->requestAs($this->ownerToken, 'GET', '/api/boards/'.$boardId.'/elements');
        self::assertResponseStatusCodeSame(200);
        self::assertCount(9, $this->jsonResponse()['elements']);
    }

    public function testCollaboratorSeesAndEditsGeneratedNotes(): void
    {
        $boardId = $this->createBoardAsOwner();
        foreach ($this->plan() as $note) {
            $this->postNote($boardId, $note);
        }

        // Sharing uses the normal member endpoint; the generated board is an
        // ordinary board, so a collaborator joins it like any other.
        $this->requestAs($this->ownerToken, 'POST', '/api/boards/'.$boardId.'/members', [
            'email' => 'editor@example.test',
            'role' => 'Editor',
        ]);
        self::assertResponseIsSuccessful();

        $this->requestAs($this->editorToken, 'GET', '/api/boards/'.$boardId.'/elements');
        self::assertResponseStatusCodeSame(200);
        $elements = $this->jsonResponse()['elements'];
        self::assertCount(9, $elements);

        $target = $elements[0]['id'];
        $this->requestAs($this->editorToken, 'PATCH', '/api/elements/'.$target, [
            'content' => 'Edited by a collaborator',
        ]);
        self::assertResponseIsSuccessful();

        $this->requestAs($this->editorToken, 'GET', '/api/boards/'.$boardId);
        self::assertResponseStatusCodeSame(200);
        $board = $this->jsonResponse();
        // Import created 10 entries; the collaborator's edit adds an eleventh.
        self::assertCount(11, $board['timeline']);
        $contents = array_column($board['elements'], 'content');
        self::assertContains('Edited by a collaborator', $contents);
    }

    public function testRepeatedApprovalReplaysInsteadOfDuplicating(): void
    {
        $boardId = $this->createBoardAsOwner();
        $plan = $this->plan();

        foreach ($plan as $note) {
            $this->postNote($boardId, $note, 201);
        }

        // A retried approval (same mutation ids, same payloads) must replay the
        // stored elements instead of creating another board or more notes.
        foreach ($plan as $note) {
            $this->postNote($boardId, $note, 200);
        }

        $this->requestAs($this->ownerToken, 'GET', '/api/boards/'.$boardId);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(9, $this->jsonResponse()['elementCount']);
        // The fixture board from setUp plus the one created by the import.
        self::assertSame(2, $this->countRows('boards'));
        self::assertSame(9, $this->countRows('canvas_elements'));
        self::assertSame(10, $this->countRows('timeline_entries'));
    }

    public function testNonAdministratorCannotCreateTheBoard(): void
    {
        $boardsBefore = $this->countRows('boards');

        $this->requestAs($this->editorToken, 'POST', '/api/boards', [
            'title' => self::BOARD_TITLE,
            'workspaceId' => $this->workspace->getId(),
            'noteColors' => self::NOTE_COLORS,
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame($boardsBefore, $this->countRows('boards'));
        self::assertSame(0, $this->countRows('canvas_elements'));
    }

    public function testOutsiderCannotAddGeneratedNotes(): void
    {
        $boardId = $this->createBoardAsOwner();

        $this->requestAs($this->outsiderToken, 'POST', '/api/boards/'.$boardId.'/elements', [
            'mutationId' => $this->uuid('outsider-note'),
            'type' => 'note',
            'x' => 350,
            'y' => 258,
            'width' => 220,
            'height' => 140,
            'content' => 'Should never be stored',
            'color' => 'amber',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->countRows('canvas_elements'));
    }

    public function testRollbackLeavesNoActivePartialBoard(): void
    {
        $boardId = $this->createBoardAsOwner();
        $plan = array_slice($this->plan(), 0, 2);
        foreach ($plan as $note) {
            $this->postNote($boardId, $note);
        }

        // The frontend compensates a failed import with the existing endpoints:
        // delete the notes it created, then trash the board.
        $this->requestAs($this->ownerToken, 'GET', '/api/boards/'.$boardId.'/elements');
        $created = array_column($this->jsonResponse()['elements'], 'id');
        self::assertCount(2, $created);
        foreach ($created as $elementId) {
            $this->requestAs($this->ownerToken, 'DELETE', '/api/elements/'.$elementId);
            self::assertResponseStatusCodeSame(204);
        }
        $this->requestAs($this->ownerToken, 'POST', '/api/boards/'.$boardId.'/trash');
        self::assertResponseIsSuccessful();
        self::assertSame('trashed', $this->jsonResponse()['status']);

        // No active partial board and no orphan notes remain.
        $this->requestAs($this->ownerToken, 'GET', '/api/boards?status=active');
        self::assertResponseStatusCodeSame(200);
        self::assertNotContains($boardId, array_column($this->jsonResponse()['boards'], 'id'));
        self::assertSame(0, (int) $this->fetchColumn('SELECT COUNT(*) FROM canvas_elements WHERE board_id = ?', [$boardId]));

        $this->requestAs($this->ownerToken, 'GET', '/api/boards?status=trashed');
        self::assertResponseStatusCodeSame(200);
        self::assertContains($boardId, array_column($this->jsonResponse()['boards'], 'id'));
    }

    private function createBoardAsOwner(): string
    {
        $this->requestAs($this->ownerToken, 'POST', '/api/boards', [
            'title' => self::BOARD_TITLE,
            'workspaceId' => $this->workspace->getId(),
            'noteColors' => self::NOTE_COLORS,
        ]);
        self::assertResponseStatusCodeSame(201);

        return (string) $this->jsonResponse()['id'];
    }

    /**
     * @param array<string, mixed> $note
     */
    private function postNote(string $boardId, array $note, int $expectedStatus = 201): void
    {
        $this->requestAs($this->ownerToken, 'POST', '/api/boards/'.$boardId.'/elements', $note);
        self::assertResponseStatusCodeSame($expectedStatus);
        self::assertSame($note['content'], $this->jsonResponse()['content']);
    }

    /**
     * The approved proposal plan: 3 sections × 3 notes with the frontend's
     * deterministic coordinates and colour mapping.
     *
     * @return list<array<string, mixed>>
     */
    private function plan(): array
    {
        $sections = [
            ['id' => 's1', 'title' => 'Session goals', 'x' => 350, 'y' => 258],
            ['id' => 's2', 'title' => 'Options to explore', 'x' => 350, 'y' => 430],
            ['id' => 's3', 'title' => 'Next steps', 'x' => 350, 'y' => 602],
        ];
        $types = ['idea', 'question', 'action'];
        $colors = ['amber', 'sky', 'lilac'];

        $notes = [];
        foreach ($sections as $sectionIndex => $section) {
            foreach ($types as $noteIndex => $type) {
                $notes[] = [
                    'mutationId' => $this->uuid(sprintf('%d-%d', $sectionIndex, $noteIndex)),
                    'type' => 'note',
                    'x' => $section['x'] + $noteIndex * 240,
                    'y' => $section['y'],
                    'width' => 220,
                    'height' => 140,
                    'rotation' => 0,
                    'content' => sprintf('%s note %d', $section['title'], $noteIndex + 1),
                    'color' => $colors[$noteIndex],
                    'metadata' => [
                        'stroke' => 'none',
                        'textSize' => 'm',
                        'align' => 'left',
                        'aiNoteType' => $type,
                        'aiSectionId' => $section['id'],
                        'aiSectionTitle' => $section['title'],
                    ],
                ];
            }
        }

        return $notes;
    }

    /** Deterministic canonical UUIDs so the plan can be replayed. */
    private function uuid(string $seed): string
    {
        $hex = substr(hash('sha256', 'ai-import-'.$seed), 0, 32);

        return sprintf(
            '%s-%s-4%s-%s%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 13, 3),
            dechex((hexdec($hex[16]) & 0x3) | 0x8),
            substr($hex, 17, 3),
            substr($hex, 20, 12),
        );
    }

    /** @param list<array<string, mixed>> $elements */
    private function assertNoOverlap(array $elements): void
    {
        $count = count($elements);
        for ($first = 0; $first < $count; ++$first) {
            for ($second = $first + 1; $second < $count; ++$second) {
                $a = $elements[$first];
                $b = $elements[$second];
                $overlaps = (float) $a['x'] < (float) $b['x'] + (float) $b['width']
                    && (float) $b['x'] < (float) $a['x'] + (float) $a['width']
                    && (float) $a['y'] < (float) $b['y'] + (float) $b['height']
                    && (float) $b['y'] < (float) $a['y'] + (float) $a['height'];
                self::assertFalse($overlaps, sprintf('Notes %s and %s overlap.', $a['content'], $b['content']));
            }
        }
    }
}
