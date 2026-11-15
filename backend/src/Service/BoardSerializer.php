<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Board;
use App\Entity\CanvasElement;
use App\Entity\CommentThread;
use App\Entity\TimelineEntry;

final class BoardSerializer
{
    public function __construct(private readonly CanvasSerializer $canvas)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(Board $board): array
    {
        return [
            'id' => $board->getId(),
            'title' => $board->getTitle(),
            'starred' => $board->isStarred(),
            'isTemplate' => $board->isTemplate(),
            'status' => $board->getLifecycleStatus(),
            'archivedAt' => $board->getArchivedAt()?->format(\DATE_ATOM),
            'trashedAt' => $board->getTrashedAt()?->format(\DATE_ATOM),
            'publicAccess' => $board->hasPublicAccess(),
            'noteColors' => $board->getNoteColors(),
            'workspace' => [
                'id' => $board->getWorkspace()->getId(),
                'name' => $board->getWorkspace()->getName(),
            ],
            'members' => array_map(
                fn ($membership): array => $this->canvas->user($membership->getUser()) + ['boardRole' => $membership->getRole()],
                $board->getMemberships()->toArray(),
            ),
            'elementCount' => $board->getElements()->count(),
            'createdAt' => $board->getCreatedAt()->format(\DATE_ATOM),
            'updatedAt' => $board->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(Board $board): array
    {
        return $this->summary($board) + [
            'elements' => array_map(
                fn (CanvasElement $element): array => $this->canvas->element($element),
                $board->getElements()->toArray(),
            ),
            'comments' => array_map(
                fn (CommentThread $thread): array => $this->canvas->thread($thread),
                $board->getCommentThreads()->toArray(),
            ),
            'timeline' => array_map(
                fn (TimelineEntry $entry): array => [
                    'id' => $entry->getId(),
                    'at' => $entry->getAt()->format(\DATE_ATOM),
                    'label' => $entry->getLabel(),
                    'kind' => $entry->getKind(),
                    'reveals' => $entry->getReveals(),
                    'author' => $this->canvas->user($entry->getAuthor()),
                ],
                $board->getTimelineEntries()->toArray(),
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function publicDetail(Board $board): array
    {
        return [
            'id' => $board->getId(),
            'title' => $board->getTitle(),
            'starred' => false,
            'isTemplate' => $board->isTemplate(),
            'status' => $board->getLifecycleStatus(),
            'archivedAt' => null,
            'trashedAt' => null,
            'publicAccess' => true,
            'members' => [],
            'noteColors' => $board->getNoteColors(),
            'workspace' => ['id' => '', 'name' => ''],
            'elementCount' => $board->getElements()->count(),
            'createdAt' => $board->getCreatedAt()->format(\DATE_ATOM),
            'updatedAt' => $board->getUpdatedAt()->format(\DATE_ATOM),
            'elements' => array_map(
                fn (CanvasElement $element): array => $this->canvas->publicElement($element),
                $board->getElements()->toArray(),
            ),
        ];
    }
}
