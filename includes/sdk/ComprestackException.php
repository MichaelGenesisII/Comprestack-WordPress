<?php

declare(strict_types=1);

namespace Comprestack;

use Exception;
use Throwable;

final class ComprestackException extends Exception
{
    /** @param array<string, mixed>|null $body */
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly string $errorCode = 'server_error',
        public readonly ?array $body = null,
        public readonly ?float $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    /** Alias for the API error code (Exception::$code is reserved for integers). */
    public function apiCode(): string
    {
        return $this->errorCode;
    }
}
