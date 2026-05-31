<?php

declare(strict_types=1);

namespace Integrations;

use Integrations\Enums\RateLimitWindow;
use InvalidArgumentException;

/**
 * A provider's API rate budget: a request count over a window of seconds,
 * plus the window strategy the upstream enforces. Returned by
 * `DeclaresRateLimit::defaultRateLimit()` and consumed by `RateLimiter`.
 *
 * The unit matters: an hourly budget and a per-minute budget are not
 * interchangeable, so the window is part of the value rather than an
 * assumption. Build one with the named constructors:
 *
 *   RateLimit::perHour(5000)                // fixed hourly budget
 *   RateLimit::perMinute(700)->sliding()    // rolling per-minute limit
 */
final readonly class RateLimit
{
    public function __construct(
        public int $limit,
        public int $windowSeconds,
        public RateLimitWindow $window = RateLimitWindow::Fixed,
    ) {
        if ($limit < 1) {
            throw new InvalidArgumentException("RateLimit limit must be at least 1, got {$limit}.");
        }

        if ($windowSeconds < 1) {
            throw new InvalidArgumentException("RateLimit windowSeconds must be at least 1, got {$windowSeconds}.");
        }
    }

    public static function perMinute(int $limit): self
    {
        return new self($limit, 60);
    }

    public static function perHour(int $limit): self
    {
        return new self($limit, 3600);
    }

    public static function perDay(int $limit): self
    {
        return new self($limit, 86_400);
    }

    public static function per(int $limit, int $windowSeconds): self
    {
        return new self($limit, $windowSeconds);
    }

    /**
     * A copy of this limit enforced as a sliding (rolling) window, for an
     * upstream that caps requests in any window of `windowSeconds`.
     */
    public function sliding(): self
    {
        return new self($this->limit, $this->windowSeconds, RateLimitWindow::Sliding);
    }

    /**
     * A copy of this limit enforced as a fixed window, for an upstream
     * whose budget resets on a hard boundary. This is already the default.
     */
    public function fixed(): self
    {
        return new self($this->limit, $this->windowSeconds, RateLimitWindow::Fixed);
    }
}
