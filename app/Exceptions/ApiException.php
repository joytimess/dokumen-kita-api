<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A business-rule failure with a stable machine-readable code for the frontend.
 */
class ApiException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function conflict(string $code, string $message): self
    {
        return new self(409, $code, $message);
    }
}
