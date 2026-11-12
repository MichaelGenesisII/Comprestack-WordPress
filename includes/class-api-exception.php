<?php

declare(strict_types=1);

final class Comprestack_Api_Exception extends Exception
{
    /** @param array<string, mixed>|null $body */
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly string $error_code = 'server_error',
        public readonly ?array $body = null,
        public readonly ?float $retry_after = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }
}
