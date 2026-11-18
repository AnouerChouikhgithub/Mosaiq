<?php

declare(strict_types=1);

namespace App\Ai\Brainstorming;

/**
 * Builds the two messages sent to the model (contract §10.4).
 *
 * The system message carries the schema, the enums and the limits; the user
 * message carries nothing but the user's own text. Prompt construction is
 * deterministic so a test can assert it without a network call, and it never
 * contains board, workspace, member or history data.
 */
final class BrainstormingPromptBuilder
{
    /** @var array<string, string> */
    private const LANGUAGE_NAMES = [
        'en' => 'English',
        'fr' => 'French',
        'ar' => 'Modern Standard Arabic',
    ];

    public function systemMessage(): string
    {
        $noteTypes = implode(', ', BrainstormingLimits::NOTE_TYPES);
        $categories = implode(', ', BrainstormingLimits::SECTION_CATEGORIES);
        $budgets = implode(', ', array_map(
            static fn (string $level): string => sprintf('%s: up to %d notes', $level, BrainstormingLimits::DETAIL_LEVEL_NOTE_BUDGETS[$level]),
            BrainstormingLimits::DETAIL_LEVELS,
        ));

        return implode("\n", [
            'You produce structured brainstorming proposals for a collaborative whiteboard.',
            'Return one JSON object and nothing else: no prose, no markdown, no code fence.',
            '',
            'Shape:',
            '{"proposal":{"title":"string","objective":"string","sections":[{"id":"s1","title":"string","category":"'.$categories.'","notes":[{"id":"s1-n1","type":"'.$noteTypes.'","content":"string"}]}]}}',
            '',
            'Hard limits:',
            '- sections: at least '.BrainstormingLimits::MIN_SECTIONS.', at most '.BrainstormingLimits::MAX_SECTIONS,
            '- notes per section: '.BrainstormingLimits::MIN_NOTES_PER_SECTION.' to '.BrainstormingLimits::MAX_NOTES_PER_SECTION,
            '- notes in total: '.BrainstormingLimits::MIN_TOTAL_NOTES.' to '.BrainstormingLimits::MAX_TOTAL_NOTES,
            '- note content: '.BrainstormingLimits::MIN_NOTE_CONTENT_LENGTH.' to '.BrainstormingLimits::MAX_NOTE_CONTENT_LENGTH.' characters',
            '- section title: '.BrainstormingLimits::MIN_SECTION_TITLE_LENGTH.' to '.BrainstormingLimits::MAX_SECTION_TITLE_LENGTH.' characters',
            '- objective: '.BrainstormingLimits::MIN_PROPOSAL_OBJECTIVE_LENGTH.' to '.BrainstormingLimits::MAX_PROPOSAL_OBJECTIVE_LENGTH.' characters',
            '- title: '.BrainstormingLimits::MIN_PROPOSAL_TITLE_LENGTH.' to '.BrainstormingLimits::MAX_PROPOSAL_TITLE_LENGTH.' characters',
            '',
            'Content rules:',
            '- Produce specific, useful, non-repetitive ideas. No generic filler such as "do more research".',
            '- One idea per note, short enough to read on a sticky note, no line breaks, no markdown, no bullets, no emoji.',
            '- Distinguish facts from assumptions: phrase an assumption or a guess as an assumption, and never present speculation as verified information.',
            '- Use the note type that fits: idea, question, challenge, opportunity or action.',
            '- Use the section category that fits the section.',
            '- Do not add relationships, links, ids from outside this schema, or any field not shown above.',
            '- Write every string in the requested language.',
            '',
            'Detail levels: '.$budgets.'.',
        ]);
    }

    public function userMessage(BrainstormingRequest $request): string
    {
        $lines = [
            'Topic: '.$request->topic,
            'Objective: '.$request->objective,
        ];
        if (null !== $request->context) {
            $lines[] = 'Context: '.$request->context;
        }
        $lines[] = 'Language: '.self::LANGUAGE_NAMES[$request->language];
        $lines[] = 'Detail level: '.$request->detailLevel.' (aim for at most '
            .BrainstormingLimits::DETAIL_LEVEL_NOTE_BUDGETS[$request->detailLevel].' notes in total)';
        if ('ar' === $request->language) {
            $lines[] = 'Use Modern Standard Arabic, without Latin transliteration or bidirectional control characters.';
        }
        $lines[] = 'Return only the JSON object.';

        return implode("\n", $lines);
    }
}
