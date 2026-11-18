<?php

declare(strict_types=1);

namespace App\Ai\Brainstorming;

use App\Ai\Exception\AiProviderFailureException;

/**
 * The only place that decides whether model output is a usable proposal
 * (contract §4, §5 and §10.5).
 *
 * Everything is checked server-side instead of being trusted from the prompt:
 * size, JSON object shape, required fields, enums, lengths, section/note counts,
 * duplicates and relationships. Anything structurally unusable becomes a
 * `502 ai.provider_failed`; content that can be repaired mechanically is
 * trimmed, clamped or dropped and reported through `truncated`.
 */
final class BrainstormingResponseValidator
{
    public function __construct(private readonly BrainstormingNormalizer $normalizer)
    {
    }

    public function validate(string $raw): BrainstormingProposal
    {
        if (strlen($raw) > BrainstormingLimits::MAX_PROPOSAL_BYTES) {
            throw new AiProviderFailureException(sprintf('Provider response exceeds %d bytes.', BrainstormingLimits::MAX_PROPOSAL_BYTES));
        }

        try {
            $decoded = json_decode($raw, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new AiProviderFailureException('Provider response is not valid JSON.');
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new AiProviderFailureException('Provider response is not a JSON object.');
        }

        $proposal = $decoded['proposal'] ?? null;
        if (!is_array($proposal) || array_is_list($proposal)) {
            throw new AiProviderFailureException('Provider response has no proposal object.');
        }

        // Relationships have no validated connector contract in schema 1.0.
        if (array_key_exists('relationships', $proposal)) {
            throw new AiProviderFailureException('Schema 1.0 does not support relationships.');
        }

        $truncated = false;
        $title = $this->requiredText($proposal, 'title', BrainstormingLimits::MIN_PROPOSAL_TITLE_LENGTH, BrainstormingLimits::MAX_PROPOSAL_TITLE_LENGTH, 'proposal title', $truncated);
        $objective = $this->requiredText($proposal, 'objective', BrainstormingLimits::MIN_PROPOSAL_OBJECTIVE_LENGTH, BrainstormingLimits::MAX_PROPOSAL_OBJECTIVE_LENGTH, 'proposal objective', $truncated);

        $rawSections = $proposal['sections'] ?? null;
        if (!is_array($rawSections) || !array_is_list($rawSections) || [] === $rawSections) {
            throw new AiProviderFailureException('Provider proposal has no sections.');
        }
        if (count($rawSections) > BrainstormingLimits::MAX_SECTIONS) {
            $rawSections = array_slice($rawSections, 0, BrainstormingLimits::MAX_SECTIONS);
            $truncated = true;
        }

        $sections = [];
        $totalNotes = 0;
        // Repetition is controlled across the whole proposal, not per section: the
        // same sticky note repeated in two sections is noise on the board.
        $seenContents = [];
        foreach ($rawSections as $rawSection) {
            $section = $this->buildSection($rawSection, count($sections) + 1, $totalNotes, $truncated, $seenContents);
            if (null === $section) {
                continue;
            }
            $sections[] = $section;
            $totalNotes += count($section->notes);
        }

        if ([] === $sections || $totalNotes < BrainstormingLimits::MIN_TOTAL_NOTES) {
            throw new AiProviderFailureException(sprintf('Provider proposal has fewer than %d usable notes.', BrainstormingLimits::MIN_TOTAL_NOTES));
        }

        return new BrainstormingProposal($title, $objective, $sections, $truncated);
    }

    /**
     * @param array<array-key, mixed> $proposal
     */
    private function requiredText(array $proposal, string $field, int $min, int $max, string $label, bool &$truncated): string
    {
        $raw = $proposal[$field] ?? null;
        if (!is_string($raw)) {
            throw new AiProviderFailureException(sprintf('Provider %s is missing.', $label));
        }

        $text = $this->normalizer->text($raw);
        $clamped = $this->normalizer->clamp($text, $max);
        if ($clamped !== $text) {
            $truncated = true;
        }
        if (mb_strlen($clamped, 'UTF-8') < $min) {
            throw new AiProviderFailureException(sprintf('Provider %s is too short.', $label));
        }

        return $clamped;
    }

    /**
     * @param array<string, bool> $seenContents normalized content already kept, shared by every section
     */
    private function buildSection(mixed $rawSection, int $sectionIndex, int $totalNotes, bool &$truncated, array &$seenContents): ?BrainstormingSection
    {
        if (!is_array($rawSection) || array_is_list($rawSection)) {
            return null;
        }

        $rawTitle = $rawSection['title'] ?? null;
        if (!is_string($rawTitle)) {
            return null;
        }
        $title = $this->normalizer->clamp($this->normalizer->text($rawTitle), BrainstormingLimits::MAX_SECTION_TITLE_LENGTH);
        if (mb_strlen($title, 'UTF-8') < BrainstormingLimits::MIN_SECTION_TITLE_LENGTH) {
            return null;
        }

        $rawCategory = $rawSection['category'] ?? null;
        $category = $this->normalizer->sectionCategory(is_string($rawCategory) ? $rawCategory : null);

        $rawNotes = $rawSection['notes'] ?? null;
        if (!is_array($rawNotes) || !array_is_list($rawNotes)) {
            return null;
        }

        $notes = [];
        foreach ($rawNotes as $rawNote) {
            // Reached only when another note exists and would be dropped, so a
            // section that exactly fills its budget does not report truncation.
            if (count($notes) >= BrainstormingLimits::MAX_NOTES_PER_SECTION
                || $totalNotes + count($notes) >= BrainstormingLimits::MAX_TOTAL_NOTES) {
                $truncated = true;
                break;
            }

            if (!is_array($rawNote) || array_is_list($rawNote)) {
                continue;
            }
            $rawContent = $rawNote['content'] ?? null;
            if (!is_string($rawContent)) {
                continue;
            }

            $content = $this->normalizer->text($rawContent);
            $clamped = $this->normalizer->clamp($content, BrainstormingLimits::MAX_NOTE_CONTENT_LENGTH);
            if ($clamped !== $content) {
                $truncated = true;
            }
            if (mb_strlen($clamped, 'UTF-8') < BrainstormingLimits::MIN_NOTE_CONTENT_LENGTH) {
                continue;
            }

            $dedupeKey = mb_strtolower($clamped, 'UTF-8');
            if (isset($seenContents[$dedupeKey])) {
                continue;
            }
            $seenContents[$dedupeKey] = true;

            $rawType = $rawNote['type'] ?? null;
            $notes[] = new BrainstormingNote(
                's'.$sectionIndex.'-n'.(count($notes) + 1),
                $this->normalizer->noteType(is_string($rawType) ? $rawType : null),
                $clamped,
            );
        }

        if ([] === $notes) {
            return null;
        }

        // Ids are stable per response and assigned here: a model cannot supply a
        // duplicate, a foreign key or a value outside the contract pattern.
        return new BrainstormingSection('s'.$sectionIndex, $title, $category, $notes);
    }
}
