<?php

declare(strict_types=1);

namespace App\Ai\Brainstorming;

/** One themed group of notes in a proposal (contract §4.2). */
final class BrainstormingSection
{
    /**
     * @param list<BrainstormingNote> $notes
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $category,
        public readonly array $notes,
    ) {
    }

    /** @return array{id: string, title: string, category: string, notes: list<array{id: string, type: string, content: string}>} */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'category' => $this->category,
            'notes' => array_map(static fn (BrainstormingNote $note): array => $note->toPayload(), $this->notes),
        ];
    }
}
