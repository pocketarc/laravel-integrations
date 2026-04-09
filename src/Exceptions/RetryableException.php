<?php

declare(strict_types=1);

namespace Integrations\Exceptions;

use RuntimeException;
use Throwable;

class RetryableException extends RuntimeException
{
    public function __construct(
        string $message = '',
        public readonly ?int $retryAfterSeconds = null,
        public readonly ?int $maxAttempts = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
