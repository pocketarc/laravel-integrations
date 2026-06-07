<?php

declare(strict_types=1);

namespace Integrations\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Failure-rate tripping strategy for the circuit breaker. Tracks upstream
 * failures and total outcomes in per-window cache buckets — the same shape the
 * RateLimiter uses — and reports a trip once the failure rate over the window
 * crosses the threshold, provided a minimum number of requests has been seen.
 *
 * The buckets ({prefix}:breaker:{id}:win:{windowStart}:fail|:total) are
 * disjoint from the breaker's state key and probe slot, so the half-open
 * machinery is unaffected by the strategy in use.
 *
 * Like the RateLimiter, the read-then-increment is not atomic, so concurrent
 * workers can miscount by up to (workers - 1). That is acceptable for a
 * heuristic safety breaker.
 */
final class CircuitBreakerRateStrategy
{
    /**
     * Extra seconds beyond the window width added to each bucket's TTL, so a
     * request that reads just before the window edge and increments just after
     * still lands on a live key.
     */
    private const BUCKET_TTL_BUFFER = 10;

    /**
     * Record one request outcome. Every outcome moves the denominator; only an
     * upstream failure also moves the numerator, so a stream of healthy traffic
     * (or client errors the upstream handled fine) dilutes the failure rate.
     */
    public function recordOutcome(int $integrationId, bool $failed): void
    {
        $window = Config::circuitBreakerTimeWindow();
        $start = $this->windowStart($window);

        $this->bump($this->key($integrationId, $start, 'total'), $window);

        if ($failed) {
            $this->bump($this->key($integrationId, $start, 'fail'), $window);
        }
    }

    /**
     * Whether the failure rate over the current and previous window has crossed
     * the configured threshold, once the minimum-request floor is met.
     */
    public function isTripped(int $integrationId): bool
    {
        $window = Config::circuitBreakerTimeWindow();
        $start = $this->windowStart($window);

        // Sum the current and previous window so a burst straddling a boundary
        // still trips. Unlike the RateLimiter we don't weight-decay the
        // previous window: a safety breaker should be eager and predictable.
        $failures = $this->count($integrationId, $start, 'fail')
            + $this->count($integrationId, $start - $window, 'fail');
        $total = $this->count($integrationId, $start, 'total')
            + $this->count($integrationId, $start - $window, 'total');

        if ($total < Config::circuitBreakerMinimumRequests()) {
            return false;
        }

        return ($failures / max(1, $total)) * 100.0 >= Config::circuitBreakerFailureRateThreshold();
    }

    /**
     * The live failure rate (0–100) over the current and previous window, as
     * the breaker sees it. Null when no outcomes have been recorded yet (so a
     * caller can show "n/a" rather than a misleading 0%). This is the
     * breaker's upstream-only view; it does not apply the minimum-request floor.
     */
    public function currentFailureRate(int $integrationId): ?float
    {
        $window = Config::circuitBreakerTimeWindow();
        $start = $this->windowStart($window);

        $failures = $this->count($integrationId, $start, 'fail')
            + $this->count($integrationId, $start - $window, 'fail');
        $total = $this->count($integrationId, $start, 'total')
            + $this->count($integrationId, $start - $window, 'total');

        if ($total === 0) {
            return null;
        }

        return ($failures / $total) * 100.0;
    }

    /**
     * Clear the failure-rate buckets (current and previous window) so a closed
     * breaker starts its accounting fresh.
     */
    public function reset(int $integrationId): void
    {
        $window = Config::circuitBreakerTimeWindow();
        $start = $this->windowStart($window);

        foreach ([$start, $start - $window] as $bucketStart) {
            Cache::forget($this->key($integrationId, $bucketStart, 'fail'));
            Cache::forget($this->key($integrationId, $bucketStart, 'total'));
        }
    }

    private function bump(string $key, int $window): void
    {
        Cache::add($key, 0, $window + self::BUCKET_TTL_BUFFER);
        Cache::increment($key);
    }

    private function count(int $integrationId, int $start, string $kind): int
    {
        $raw = Cache::get($this->key($integrationId, $start, $kind), 0);

        return is_numeric($raw) ? (int) $raw : 0;
    }

    private function windowStart(int $window): int
    {
        return intdiv((int) now()->timestamp, $window) * $window;
    }

    private function key(int $integrationId, int $start, string $kind): string
    {
        return Config::cachePrefix().':breaker:'.$integrationId.':win:'.$start.':'.$kind;
    }
}
