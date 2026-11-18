<?php

declare(strict_types=1);

namespace App\Ai\Brainstorming;

/**
 * The authoritative values of docs/architecture/AI_BRAINSTORMING_CONTRACT.md §5.
 *
 * The prompt and the validator both read these constants: the prompt asks the
 * model to respect them and the validator enforces them, so a change here can
 * never leave the instruction and the check out of sync.
 */
final class BrainstormingLimits
{
    public const SCHEMA_VERSION = '1.0';

    public const LANGUAGES = ['en', 'fr', 'ar'];
    public const DETAIL_LEVELS = ['concise', 'balanced', 'detailed'];
    public const NOTE_TYPES = ['idea', 'question', 'challenge', 'opportunity', 'action'];
    public const SECTION_CATEGORIES = ['goals', 'audience', 'ideas', 'risks', 'actions', 'questions'];

    /** Prompt guidance per detail level; the hard caps below still win. */
    public const DETAIL_LEVEL_NOTE_BUDGETS = ['concise' => 9, 'balanced' => 15, 'detailed' => 25];

    public const MIN_TOPIC_LENGTH = 3;
    public const MAX_TOPIC_LENGTH = 280;
    public const MIN_OBJECTIVE_LENGTH = 5;
    public const MAX_OBJECTIVE_LENGTH = 500;
    public const MAX_CONTEXT_LENGTH = 2000;

    public const MIN_SECTIONS = 1;
    public const MAX_SECTIONS = 5;
    public const MIN_SECTION_TITLE_LENGTH = 3;
    public const MAX_SECTION_TITLE_LENGTH = 80;

    public const MIN_NOTES_PER_SECTION = 1;
    public const MAX_NOTES_PER_SECTION = 10;
    public const MIN_TOTAL_NOTES = 3;
    public const MAX_TOTAL_NOTES = 25;
    public const MIN_NOTE_CONTENT_LENGTH = 3;
    public const MAX_NOTE_CONTENT_LENGTH = 280;

    public const MIN_PROPOSAL_TITLE_LENGTH = 3;
    public const MAX_PROPOSAL_TITLE_LENGTH = 120;
    public const MIN_PROPOSAL_OBJECTIVE_LENGTH = 5;
    public const MAX_PROPOSAL_OBJECTIVE_LENGTH = 300;

    public const MAX_REQUEST_BYTES = 8192;
    public const MAX_PROPOSAL_BYTES = 65536;

    public const MAX_PROVIDER_TIMEOUT_SECONDS = 20;
    public const TOTAL_PROVIDER_BUDGET_SECONDS = 25;
    public const MAX_PROVIDER_ATTEMPTS = 2;
    public const PROVIDER_RETRY_DELAY_MICROSECONDS = 500_000;
}
