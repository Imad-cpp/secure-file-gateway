<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class IngestionException extends RuntimeException
{
    public function __construct(
        public readonly string $apiCode,
        public readonly int $status,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
