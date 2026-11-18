<?php

declare(strict_types=1);

namespace App\Ai\Exception;

/**
 * Base of every expected AI-generation failure.
 *
 * It carries the HTTP status and the stable machine code from contract §10.6,
 * so the controller maps exceptions without a chain of `instanceof` checks and
 * no provider or stack-trace detail reaches the client. `logDetail` is for the
 * server log only.
 */
class AiGenerationException extends \RuntimeException
{
    /**
     * @param list<array{field: string, message: string}> $details
     */
    public function __construct(
        string $message,
        private readonly int $statusCode,
        private readonly string $errorCode,
        private readonly array $details = [],
        private readonly ?int $retryAfter = null,
        private readonly ?string $logDetail = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** @return list<array{field: string, message: string}> */
    public function getDetails(): array
    {
        return $this->details;
    }

    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }

    public function getLogDetail(): string
    {
        return $this->logDetail ?? $this->getMessage();
    }
}
