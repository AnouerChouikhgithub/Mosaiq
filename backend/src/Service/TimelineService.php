<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Board;
use App\Entity\CanvasElement;
use App\Entity\TimelineEntry;
use App\Entity\User;
use App\Repository\CanvasElementRepository;
use App\Repository\TimelineEntryRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

final class TimelineService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CanvasElementRepository $elements,
        private readonly TimelineEntryRepository $entries,
        private readonly UserRepository $users,
        private readonly CanvasSerializer $serializer,
    ) {
    }

    public function record(Board $board, User $author, string $label, string $kind, ?array $reveals = null, ?array $snapshotOverride = null): TimelineEntry
    {
        if (!in_array($kind, TimelineEntry::KINDS, true)) {
            $kind = 'board';
        }
        $snapshot = $snapshotOverride ?? array_map(fn (CanvasElement $element): array => $this->serializer->element($element), $this->elements->findByBoard($board));
        $entry = new TimelineEntry($board, $author, new \DateTimeImmutable(), $label, $kind, $reveals, $snapshot);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    public function restore(Board $board, TimelineEntry $entry, User $actor): void
    {
        $snapshot = $entry->getSnapshot();
        if ($entry->getBoard()->getId() !== $board->getId() || null === $snapshot) {
            throw new \InvalidArgumentException('Timeline snapshot is unavailable.');
        }

        $restored = [];
        foreach ($snapshot as $saved) {
            if (!is_array($saved) || !isset($saved['author']['id'], $saved['type'], $saved['x'], $saved['y'], $saved['width'], $saved['height'], $saved['rotation'], $saved['createdAt'])) {
                throw new \InvalidArgumentException('Stored timeline snapshot is invalid.');
            }
            $author = $this->users->find((string) $saved['author']['id']);
            if (!$author instanceof User) {
                throw new \InvalidArgumentException('Snapshot author no longer exists.');
            }
            $restored[] = new CanvasElement(
                $board, $author, (string) $saved['type'], (float) $saved['x'], (float) $saved['y'],
                (float) $saved['width'], (float) $saved['height'], (float) $saved['rotation'],
                isset($saved['content']) ? (string) $saved['content'] : null,
                isset($saved['color']) ? (string) $saved['color'] : null,
                is_array($saved['metadata'] ?? null) ? $saved['metadata'] : null,
                new \DateTimeImmutable((string) $saved['createdAt']),
            );
        }

        $this->entityManager->wrapInTransaction(function () use ($board, $actor, $restored, $entry): void {
            foreach ($this->elements->findByBoard($board) as $element) {
                $this->entityManager->remove($element);
            }
            $this->entityManager->flush();
            foreach ($restored as $element) {
                $this->entityManager->persist($element);
            }
            $board->touch();
            $this->entityManager->flush();
            $this->record($board, $actor, sprintf('%s restored timeline step %s', $actor->getName(), $entry->getId()), 'board');
        });
    }

    /** @return list<TimelineEntry> */
    public function forBoard(Board $board): array
    {
        return $this->entries->findByBoard($board);
    }
}
