<?php

declare(strict_types=1);

namespace App\Ai\Brainstorming;

use App\Ai\Exception\AiRequestValidationException;
use App\Ai\Exception\AiUnsupportedSchemaVersionException;

/**
 * Validated input of one generation request (contract §3).
 *
 * Construction is the validation boundary: an instance can only exist with
 * trimmed, length-checked, UTF-8 values from the allowed enums, so nothing
 * downstream has to re-check the prompt.
 */
final class BrainstormingRequest
{
    private const FIELDS = ['schemaVersion', 'topic', 'objective', 'context', 'language', 'detailLevel'];

    private function __construct(
        public readonly string $topic,
        public readonly string $objective,
        public readonly ?string $context,
        public readonly string $language,
        public readonly string $detailLevel,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws AiRequestValidationException        when a field is missing, mistyped or out of range
     * @throws AiUnsupportedSchemaVersionException when the client asks for a schema this release does not speak
     */
    public static function fromPayload(array $payload): self
    {
        if (array_key_exists('schemaVersion', $payload)) {
            $version = $payload['schemaVersion'];
            if (!is_string($version)) {
                throw new AiRequestValidationException([['field' => 'schemaVersion', 'message' => 'must be a string.']]);
            }
            if (BrainstormingLimits::SCHEMA_VERSION !== $version) {
                throw new AiUnsupportedSchemaVersionException($version);
            }
        }

        $errors = [];
        foreach (array_keys($payload) as $field) {
            if (!in_array($field, self::FIELDS, true)) {
                $errors[] = ['field' => (string) $field, 'message' => 'is not a supported field.'];
            }
        }

        $topic = self::text($payload, 'topic', BrainstormingLimits::MIN_TOPIC_LENGTH, BrainstormingLimits::MAX_TOPIC_LENGTH, true, $errors);
        $objective = self::text($payload, 'objective', BrainstormingLimits::MIN_OBJECTIVE_LENGTH, BrainstormingLimits::MAX_OBJECTIVE_LENGTH, true, $errors);
        $context = self::text($payload, 'context', null, BrainstormingLimits::MAX_CONTEXT_LENGTH, false, $errors);
        $language = self::enum($payload, 'language', BrainstormingLimits::LANGUAGES, $errors);
        $detailLevel = self::enum($payload, 'detailLevel', BrainstormingLimits::DETAIL_LEVELS, $errors);

        if ([] !== $errors) {
            throw new AiRequestValidationException($errors);
        }

        \assert(null !== $topic && null !== $objective && null !== $language && null !== $detailLevel);

        return new self($topic, $objective, '' === $context ? null : $context, $language, $detailLevel);
    }

    /** Notes as the prompt sees them; no board, workspace or user data is included. */
    public function promptLength(): int
    {
        return mb_strlen($this->topic, 'UTF-8')
            + mb_strlen($this->objective, 'UTF-8')
            + mb_strlen($this->context ?? '', 'UTF-8');
    }

    /**
     * @param array<string, mixed>                        $payload
     * @param list<array{field: string, message: string}> $errors
     */
    private static function text(array $payload, string $field, ?int $min, int $max, bool $required, array &$errors): ?string
    {
        if (!array_key_exists($field, $payload) || null === $payload[$field]) {
            if ($required) {
                $errors[] = ['field' => $field, 'message' => 'is required.'];
            }

            return null;
        }

        $value = $payload[$field];
        if (!is_string($value)) {
            $errors[] = ['field' => $field, 'message' => 'must be a string.'];

            return null;
        }
        // A malformed byte sequence would otherwise reach the provider verbatim.
        if (1 !== preg_match('//u', $value)) {
            $errors[] = ['field' => $field, 'message' => 'must be valid UTF-8.'];

            return null;
        }
        if (1 === preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            $errors[] = ['field' => $field, 'message' => 'must not contain control characters.'];

            return null;
        }

        $normalized = trim((string) preg_replace('/\s+/u', ' ', $value));
        $length = mb_strlen($normalized, 'UTF-8');
        if (null !== $min && $length < $min) {
            $errors[] = ['field' => $field, 'message' => sprintf('must be between %d and %d characters.', $min, $max)];
        } elseif ($length > $max) {
            $errors[] = ['field' => $field, 'message' => sprintf('must be at most %d characters.', $max)];
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed>                        $payload
     * @param list<string>                                $allowed
     * @param list<array{field: string, message: string}> $errors
     */
    private static function enum(array $payload, string $field, array $allowed, array &$errors): ?string
    {
        if (!array_key_exists($field, $payload) || null === $payload[$field]) {
            $errors[] = ['field' => $field, 'message' => 'is required.'];

            return null;
        }

        $value = $payload[$field];
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            $errors[] = ['field' => $field, 'message' => sprintf('must be one of: %s.', implode(', ', $allowed))];

            return null;
        }

        return $value;
    }
}
