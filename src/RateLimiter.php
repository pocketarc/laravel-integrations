<?php

declare(strict_types=1);

namespace Integrations;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Integrations\Enums\RateLimitWindow;
use Integrations\Exceptions\RateLimitExceededException;
use Integrations\Models\Integration;
use Integrations\Support\Config;

final class RateLimiter
{
    /**
     * Extra seconds added to a window bucket's cache TTL beyond the window
     * width, so a request that reads the bucket just before the window edge
     * and increments just after still lands on a live key.
     */
    private const BUCKET_TTL_BUFFER = 10;

    public function __construct(
        private readonly Integration $integration,
    ) {}

    public function enforce(): void
    {
        $maxWait = Config::rateLimitMaxWaitSeconds();

        // Provider-fed suppression takes priority over the local bucket:
        // we already know we're over budget regardless of what the bucket
        // says. Wait it out (or throw) before checking the bucket.
        $this->awaitSuppressionLift($maxWait);

        $limit = $this->resolveLimit();
        if ($limit === null) {
            return;
        }

        while (true) {
            $nowTs = (int) now()->timestamp;
            $windowStart = intdiv($nowTs, $limit->windowSeconds) * $limit->windowSeconds;

            if ($this->currentUsage($limit, $windowStart) < $limit->limit) {
                $this->recordRequest($limit, $windowStart);

                return;
            }

            $retryAfter = $this->secondsUntilCapacity($limit, $windowStart, $nowTs);

            if ($retryAfter > $maxWait) {
                throw new RateLimitExceededException($this->integration, $retryAfter, $limit);
            }

            // Sleep until capacity is expected, then re-check. retryAfter is
            // always at least 1, so the loop makes progress; a window that
            // stays saturated pushes it past maxWait and throws.
            sleep($retryAfter);
        }
    }

    /**
     * Sleep until any provider-fed suppression has expired, or throw if it
     * would outlast the configured max wait.
     *
     * The suppression gate and the bucket gate in enforce() each get their
     * own max-wait budget; they guard different conditions, so in the worst
     * case a worker can sleep up to 2x max wait across both.
     */
    private function awaitSuppressionLift(int $maxWait): void
    {
        while (($suppressedUntil = $this->suppressedUntil()) !== null) {
            $remaining = max(0, $suppressedUntil - (int) now()->timestamp);

            if ($remaining === 0) {
                Cache::forget($this->suppressKey());

                return;
            }

            if ($remaining > $maxWait) {
                throw new RateLimitExceededException($this->integration, $remaining);
            }

            sleep($remaining);
        }
    }

    /**
     * Feed the limiter response-side rate-limit signals from the adapter
     * (Retry-After seconds, X-RateLimit-Remaining: 0 + reset-at). The next
     * enforce() will suppress requests until the window clears, regardless
     * of the local bucket count. No-op when the context didn't report
     * anything actionable.
     */
    public function recordUsage(RequestContext $ctx): void
    {
        $retryAfter = $ctx->retryAfterSeconds();
        if ($retryAfter !== null && $retryAfter > 0) {
            $this->suppressUntil(now()->addSeconds($retryAfter));

            return;
        }

        $remaining = $ctx->rateLimitRemaining();
        $resetAt = $ctx->rateLimitResetAt();
        if ($remaining !== null && $remaining <= 0 && $resetAt !== null) {
            $this->suppressUntil($resetAt);
        }
    }

    private function suppressUntil(CarbonInterface $until): void
    {
        // Storing as Unix timestamp keeps the read-side simple (and avoids
        // serializing Carbon objects across cache drivers that may not
        // round-trip them faithfully).
        $untilTs = (int) $until->timestamp;
        $secondsFromNow = max(1, $untilTs - (int) now()->timestamp);
        Cache::put($this->suppressKey(), $untilTs, $secondsFromNow);
    }

    private function suppressedUntil(): ?int
    {
        $value = Cache::get($this->suppressKey());

        return is_numeric($value) ? (int) $value : null;
    }

    private function suppressKey(): string
    {
        return Config::cachePrefix().':rate:suppress:'.$this->integration->id;
    }

    private function resolveLimit(): ?RateLimit
    {
        // Precedence (override beats provider), expiry, and the global toggle
        // all live in the model so the breaker, limiter, and CLI agree.
        return $this->integration->effectiveRateLimit();
    }

    /**
     * Estimated request count in the current window. A fixed window counts
     * the current bucket alone; a sliding window weights the previous
     * bucket by the fraction of the current window still ahead (the
     * standard sliding-window counter).
     */
    private function currentUsage(RateLimit $limit, int $windowStart): int
    {
        $current = $this->bucketCount($this->bucketKey($windowStart));

        return match ($limit->window) {
            RateLimitWindow::Fixed => $current,
            RateLimitWindow::Sliding => $this->slidingEstimate($current, $windowStart, $limit->windowSeconds),
        };
    }

    private function slidingEstimate(int $current, int $windowStart, int $windowSeconds): int
    {
        $previous = $this->bucketCount($this->bucketKey($windowStart - $windowSeconds));
        $elapsedFraction = $this->elapsedFraction($windowStart, $windowSeconds);

        return (int) ceil($current + $previous * (1.0 - $elapsedFraction));
    }

    /**
     * Fraction of the current window that has elapsed, from 0.0 to 1.0,
     * with sub-second precision so the sliding estimate decays smoothly.
     */
    private function elapsedFraction(int $windowStart, int $windowSeconds): float
    {
        $now = now();
        $elapsed = ((int) $now->timestamp - $windowStart) + ((int) $now->format('u') / 1_000_000);

        return min(1.0, max(0.0, $elapsed / $windowSeconds));
    }

    /**
     * Seconds until the window is expected to have capacity again. A fixed
     * window only frees at its boundary; a sliding window frees gradually
     * as the previous window's contribution decays, so it can reopen well
     * before the boundary.
     */
    private function secondsUntilCapacity(RateLimit $limit, int $windowStart, int $nowTs): int
    {
        $untilBoundary = max(1, $windowStart + $limit->windowSeconds - $nowTs);

        if ($limit->window === RateLimitWindow::Fixed) {
            return $untilBoundary;
        }

        $current = $this->bucketCount($this->bucketKey($windowStart));
        $previous = $this->bucketCount($this->bucketKey($windowStart - $limit->windowSeconds));

        // The current window alone is already at the limit (it only frees
        // once it ages past the boundary), or there is no previous window
        // to decay: fall back to the boundary.
        if ($current >= $limit->limit - 1 || $previous <= 0) {
            return $untilBoundary;
        }

        // currentUsage() compares ceil(estimate) to the limit, so capacity
        // opens once the estimate decays to limit - 1. Solve
        // current + previous * (1 - f) = limit - 1 for the elapsed fraction
        // f, then convert the fraction still to run into seconds.
        $targetFraction = 1.0 - ($limit->limit - 1 - $current) / $previous;
        $remaining = ($targetFraction - $this->elapsedFraction($windowStart, $limit->windowSeconds)) * $limit->windowSeconds;

        return max(1, min($untilBoundary, (int) ceil($remaining)));
    }

    /**
     * Count this request against the current window bucket.
     *
     * Cache::add seeds the key only when it is absent: the database cache
     * store's increment() is a no-op on a missing key, and add() pins the
     * TTL to the window the key belongs to rather than sliding it on every
     * hit. The read in currentUsage() and the increment here are not a
     * single atomic operation, so concurrent workers can overshoot the
     * limit by up to (workers - 1). That is the predictive bucket's
     * accepted imprecision, with the provider-fed suppression path as the
     * exact backstop.
     */
    private function recordRequest(RateLimit $limit, int $windowStart): void
    {
        $key = $this->bucketKey($windowStart);

        Cache::add($key, 0, $limit->windowSeconds + self::BUCKET_TTL_BUFFER);
        Cache::increment($key);
    }

    private function bucketCount(string $key): int
    {
        $raw = Cache::get($key, 0);

        return is_numeric($raw) ? (int) $raw : 0;
    }

    private function bucketKey(int $windowStart): string
    {
        return Config::cachePrefix().':rate:'.$this->integration->id.':'.$windowStart;
    }
}
