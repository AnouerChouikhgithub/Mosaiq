<?php

declare(strict_types=1);

namespace App\Ai\Brainstorming;

/**
 * A validated brainstorming proposal (contract §4).
 *
 * It is response data only: it carries no board, workspace or element id, and
 * nothing in the backend persists it. `truncated` reports that the validator
 * had to drop or shorten model output to fit the contract limits.
 */
final class BrainstormingProposal
{
    /**
     * @param list<BrainstormingSection> $sections
     */
    public function __construct(
        public readonly string $title,
        public readonly string $objective,
        public readonly array $sections,
        public readonly bool $truncated = false,
    ) {
    }

    /** @return array{title: string, objective: string, sections: list<array{id: string, title: string, category: string, notes: list<array{id: string, type: string, content: string}>}>} */
    public function toPayload(): array
    {
        return [
            'title' => $this->title,
            'objective' => $this->objective,
            'sections' => array_map(static fn (BrainstormingSection $section): array => $section->toPayload(), $this->sections),
        ];
    }

    public function noteCount(): int
    {
        return array_sum(array_map(static fn (BrainstormingSection $section): int => count($section->notes), $this->sections));
    }
}
