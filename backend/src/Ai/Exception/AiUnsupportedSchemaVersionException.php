<?php

declare(strict_types=1);

namespace App\Ai\Exception;

/** The client asked for a contract version this release does not implement. */
final class AiUnsupportedSchemaVersionException extends AiGenerationException
{
    public function __construct(string $version)
    {
        parent::__construct(
            sprintf('Unsupported schema version "%s".', $version),
            422,
            'ai.unsupported_schema_version',
            [],
            null,
            sprintf('Unsupported schema version "%s".', $version),
        );
    }
}
