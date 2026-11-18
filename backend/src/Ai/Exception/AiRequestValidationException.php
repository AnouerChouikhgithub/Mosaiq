<?php

declare(strict_types=1);

namespace App\Ai\Exception;

/** One or more request fields are missing, mistyped or outside the contract limits. */
final class AiRequestValidationException extends AiGenerationException
{
    /**
     * @param list<array{field: string, message: string}> $details
     */
    public function __construct(array $details)
    {
        $first = $details[0] ?? ['field' => 'body', 'message' => 'is invalid.'];
        $logged = array_map(
            static fn (array $detail): string => $detail['field'].' '.$detail['message'],
            $details,
        );

        parent::__construct(
            sprintf('"%s" %s', $first['field'], $first['message']),
            422,
            'ai.invalid_request',
            $details,
            null,
            'Request validation failed: '.implode(' ', $logged),
        );
    }
}
