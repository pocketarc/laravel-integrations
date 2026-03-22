<?php

declare(strict_types=1);

namespace Integrations\Exceptions;

use RuntimeException;
use Throwable;

class RetriesExhaustedException extends RuntimeException
{
    public function __construct(
        public readonly int $retriesMade,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            "All {$this->retriesMade} retries exhausted.",
            previous: $previous,
        );
    }
}
