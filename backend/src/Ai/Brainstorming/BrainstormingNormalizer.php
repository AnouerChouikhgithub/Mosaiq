<?php

declare(strict_types=1);

namespace App\Ai\Brainstorming;

/**
 * Mechanical cleanup of untrusted model strings (contract §10.5).
 *
 * It only trims, strips control characters, collapses whitespace, clamps and
 * relabels — it never invents content, so a normalised proposal is always a
 * subset of what the model produced.
 */
final class BrainstormingNormalizer
{
    private const IDENTIFIER_PATTERN = '/^[a-z0-9][a-z0-9-]{0,31}$/D';

    /** Single-line plain text: no control characters, no repeated whitespace. */
    public function text(string $value): string
    {
        $stripped = (string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value);

        return trim((string) preg_replace('/\s+/u', ' ', $stripped));
    }

    public function clamp(string $value, int $maxLength): string
    {
        if (mb_strlen($value, 'UTF-8') <= $maxLength) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $maxLength, 'UTF-8'));
    }

    /** Unknown labels are normalised to the closest allowed value, never kept. */
    public function noteType(?string $value): string
    {
        $normalized = is_string($value) ? strtolower(trim($value)) : '';

        return in_array($normalized, BrainstormingLimits::NOTE_TYPES, true) ? $normalized : 'idea';
    }

    public function sectionCategory(?string $value): string
    {
        $normalized = is_string($value) ? strtolower(trim($value)) : '';

        return in_array($normalized, BrainstormingLimits::SECTION_CATEGORIES, true) ? $normalized : 'ideas';
    }

    public function identifier(?string $value): ?string
    {
        if (!is_string($value) || 1 !== preg_match(self::IDENTIFIER_PATTERN, $value)) {
            return null;
        }

        return $value;
    }
}
