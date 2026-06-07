<?php

declare(strict_types=1);

namespace Integrations\Reporting;

/**
 * One row of the "most frequent error messages" breakdown: an upstream/SDK
 * error message and how many times it occurred in the window.
 */
final readonly class TopError
{
    public function __construct(
        public string $message,
        public int $count,
    ) {}
}
