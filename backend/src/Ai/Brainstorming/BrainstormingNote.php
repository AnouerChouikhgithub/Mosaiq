<?php

declare(strict_types=1);

namespace App\Ai\Brainstorming;

/** One idea in a proposal (contract §4.3). */
final class BrainstormingNote
{
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $content,
    ) {
    }

    /** @return array{id: string, type: string, content: string} */
    public function toPayload(): array
    {
        return ['id' => $this->id, 'type' => $this->type, 'content' => $this->content];
    }
}
