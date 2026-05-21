<?php

declare(strict_types=1);

namespace Integrations\Exceptions;

use Integrations\Models\Integration;
use Integrations\RateLimit;
use RuntimeException;

/**
 * Thrown when the local `RateLimiter` cannot grant capacity within the
 * configured max wait. `retryAfterSeconds` is when capacity is expected, so
 * a queued caller (`ProcessSyncItem`) can release the job and retry later
 * rather than failing it.
 *
 * Deliberately a plain `RuntimeException`, not a `RetryableException`: the
 * `RequestExecutor` retry loop must not retry this in-process; that would
 * sleep a worker for the whole window. It propagates to the caller instead.
 */
class RateLimitExceededException extends RuntimeException
{
    public function __construct(
        public readonly Integration $integration,
        public readonly int $retryAfterSeconds,
        public readonly ?RateLimit $rateLimit = null,
    ) {
        $detail = $rateLimit !== null
            ? sprintf(' (limit %d per %ds)', $rateLimit->limit, $rateLimit->windowSeconds)
            : '';

        parent::__construct(sprintf(
            "Rate limit exceeded for integration '%s'%s. Capacity expected in %ds.",
            $integration->name,
            $detail,
            $retryAfterSeconds,
        ));
    }
}
