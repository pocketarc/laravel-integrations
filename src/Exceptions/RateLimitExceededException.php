<?php

declare(strict_types=1);

namespace Integrations\Exceptions;

use Integrations\Models\Integration;
use RuntimeException;

class RateLimitExceededException extends RuntimeException
{
    public function __construct(
        public readonly Integration $integration,
        public readonly int $requestsThisMinute,
        public readonly int $limit,
    ) {
        parent::__construct(
            "Rate limit exceeded for integration '{$integration->name}': {$this->requestsThisMinute}/{$this->limit} requests per minute.",
        );
    }
}
