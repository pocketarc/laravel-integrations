<?php

declare(strict_types=1);

namespace Integrations\Enums;

/**
 * How a provider's upstream API enforces its rate limit, so the local
 * RateLimiter can model the same shape.
 */
enum RateLimitWindow: string
{
    /**
     * The budget resets on a hard window boundary (e.g. GitHub's hourly
     * quota, which resets at the `X-RateLimit-Reset` timestamp). Spending
     * the whole budget early in the window is allowed, so the limiter
     * permits bursts up to the full limit.
     */
    case Fixed = 'fixed';

    /**
     * The upstream caps requests in any rolling window of `windowSeconds`,
     * with no fixed reset point. The limiter smooths traffic across the
     * window rather than allowing a full-budget burst.
     */
    case Sliding = 'sliding';
}
