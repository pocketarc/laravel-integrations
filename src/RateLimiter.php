<?php

declare(strict_types=1);

namespace Integrations;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Integrations\Contracts\HasScheduledSync;
use Integrations\Exceptions\RateLimitExceededException;
use Integrations\Models\Integration;
use Integrations\Support\Config;

final class RateLimiter
{
    public function __construct(
        private readonly Integration $integration,
    ) {}

    public function enforce(): void
    {
        $maxWait = Config::rateLimitMaxWaitSeconds();

        // Provider-fed suppression takes priority over the local bucket:
        // we already know we're over budget regardless of what the bucket
        // says. Wait it out (or throw) before checking the bucket.
        $waited = $this->awaitSuppressionLift($maxWait);

        $limit = $this->resolveLimit();
        if ($limit === null) {
            return;
        }

        while (true) {
            $estimate = $this->estimateCurrentRate();

            if ($estimate < $limit) {
                Cache::increment($this->key(now()));

                return;
            }

            if ($waited >= $maxWait) {
                throw new RateLimitExceededException($this->integration, $estimate, $limit);
            }

            sleep(1);
            $waited++;
        }
    }

    /**
     * Sleep until any provider-fed suppression has expired, or throw if we
     * exceed the configured max wait. Returns the number of seconds spent
     * waiting so the caller can carry it into the bucket-based wait loop.
     */
    private function awaitSuppressionLift(int $maxWait): int
    {
        $waited = 0;

        while (($suppressedUntil = $this->suppressedUntil()) !== null) {
            $remaining = max(0, $suppressedUntil - (int) now()->timestamp);

            if ($remaining === 0) {
                Cache::forget($this->suppressKey());

                return $waited;
            }

            if ($waited >= $maxWait) {
                throw new RateLimitExceededException($this->integration, $remaining, 0);
            }

            sleep(1);
            $waited++;
        }

        return $waited;
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

    private function resolveLimit(): ?int
    {
        $provider = $this->integration->provider();

        return $provider instanceof HasScheduledSync ? $provider->defaultRateLimit() : null;
    }

    private function estimateCurrentRate(): int
    {
        $now = now();
        $currentKey = $this->key($now);
        $previousKey = $this->key($now->copy()->subMinute());

        $elapsedFraction = ((int) $now->format('s') + (int) $now->format('u') / 1_000_000) / 60.0;

        Cache::add($currentKey, 0, 120);
        Cache::add($previousKey, 0, 120);

        $rawCurrent = Cache::get($currentKey, 0);
        $rawPrevious = Cache::get($previousKey, 0);
        $currentCount = is_numeric($rawCurrent) ? (int) $rawCurrent : 0;
        $previousCount = is_numeric($rawPrevious) ? (int) $rawPrevious : 0;

        return (int) ceil($currentCount + $previousCount * (1.0 - $elapsedFraction));
    }

    private function key(CarbonInterface $time): string
    {
        return Config::cachePrefix().':rate:'.$this->integration->id.':'.$time->format('Y-m-d-H-i');
    }
}
