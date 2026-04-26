<?php

declare(strict_types=1);

namespace Integrations;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Integrations\Exceptions\CircuitOpenException;
use Integrations\Exceptions\RetryableException;
use Integrations\Models\Integration;
use Integrations\Support\Config;
use Integrations\Support\ResponseHelper;
use Throwable;

/**
 * Per-integration circuit breaker. Sits next to the RateLimiter in the
 * request pipeline: enforce() runs before the user's callback, and the
 * executor calls recordSuccess() / recordFailure() afterwards.
 *
 * State machine:
 *   closed    -> fully open for traffic. Failures increment a counter; when
 *               the counter hits the threshold, transition to "open".
 *   open      -> all requests short-circuit with CircuitOpenException until
 *               the cooldown elapses, then transition to "half_open".
 *   half_open -> one probe request is allowed through. Success -> "closed".
 *               Failure -> "open" again, fresh cooldown.
 *
 * The state is stored in a single cache key per integration. We use
 * read-modify-write rather than locks; a tiny race window where two
 * concurrent failures both flip closed -> open is harmless (they both
 * write the same end state).
 *
 * Failures that count toward the threshold: 5xx responses, ConnectionExceptions,
 * RetryableException. Failures that do NOT count: 4xx (retrying won't help,
 * the caller has the wrong input), and a CircuitOpenException itself (we
 * already know the breaker is open).
 */
final class CircuitBreaker
{
    private const STATE_CLOSED = 'closed';

    private const STATE_OPEN = 'open';

    private const STATE_HALF_OPEN = 'half_open';

    public function __construct(
        private readonly Integration $integration,
    ) {}

    public function enforce(): void
    {
        if (! Config::circuitBreakerEnabled()) {
            return;
        }

        $state = $this->loadState();
        if ($state['state'] !== self::STATE_OPEN) {
            return;
        }

        $cooldown = Config::circuitBreakerCooldownSeconds();
        $openedAt = $state['opened_at'];
        if ($openedAt === null) {
            // Defensive: if "opened_at" was somehow lost, treat the breaker
            // as needing a half-open probe right now.
            $this->writeState(self::STATE_HALF_OPEN, 0, $openedAt);

            return;
        }

        $elapsed = (int) now()->timestamp - $openedAt;

        if ($elapsed >= $cooldown) {
            $this->writeState(self::STATE_HALF_OPEN, 0, $openedAt);

            return;
        }

        throw new CircuitOpenException(
            $this->integration,
            CarbonImmutable::createFromTimestamp($openedAt),
            $cooldown,
        );
    }

    public function recordSuccess(): void
    {
        if (! Config::circuitBreakerEnabled()) {
            return;
        }

        $state = $this->loadState();
        if ($state['state'] === self::STATE_CLOSED && $state['failures'] === 0) {
            // Hot path: nothing to update.
            return;
        }

        $this->writeState(self::STATE_CLOSED, 0, null);
    }

    public function recordFailure(Throwable $e): void
    {
        if (! Config::circuitBreakerEnabled()) {
            return;
        }

        if (! $this->shouldCount($e)) {
            return;
        }

        $state = $this->loadState();
        $threshold = Config::circuitBreakerThreshold();

        if ($state['state'] === self::STATE_HALF_OPEN) {
            // Half-open probe failed: back to fully open with a fresh
            // cooldown clock.
            $this->writeState(self::STATE_OPEN, $threshold, (int) now()->timestamp);

            return;
        }

        $failures = $state['failures'] + 1;

        if ($failures >= $threshold) {
            $this->writeState(self::STATE_OPEN, $failures, (int) now()->timestamp);

            return;
        }

        $this->writeState(self::STATE_CLOSED, $failures, null);
    }

    /**
     * Failures that count toward the threshold. 4xx responses (client
     * errors) don't count: retrying won't change the outcome, and one bad
     * request shouldn't pull the integration offline for everyone else.
     * 429 (rate limit) is the exception; it counts as a real failure.
     */
    private function shouldCount(Throwable $e): bool
    {
        if ($e instanceof CircuitOpenException) {
            return false;
        }

        $statusCode = ResponseHelper::extractStatusCode($e);

        if ($statusCode !== null && $statusCode >= 400 && $statusCode < 500 && $statusCode !== 429) {
            return false;
        }

        // Network errors, retryable exceptions, 429, 5xx all count.
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof RetryableException) {
                return true;
            }
        }

        return $statusCode === null || $statusCode === 429 || $statusCode >= 500;
    }

    /**
     * @return array{state: string, failures: int, opened_at: ?int}
     */
    private function loadState(): array
    {
        $raw = Cache::get($this->key());
        if (! is_array($raw)) {
            return ['state' => self::STATE_CLOSED, 'failures' => 0, 'opened_at' => null];
        }

        $state = is_string($raw['state'] ?? null) ? $raw['state'] : self::STATE_CLOSED;
        $failures = is_int($raw['failures'] ?? null) ? $raw['failures'] : 0;
        $openedAt = is_int($raw['opened_at'] ?? null) ? $raw['opened_at'] : null;

        return ['state' => $state, 'failures' => $failures, 'opened_at' => $openedAt];
    }

    private function writeState(string $state, int $failures, ?int $openedAt): void
    {
        // TTL = 2x cooldown so the entry naturally expires once it's no
        // longer relevant; on the closed state we keep it shorter to stop
        // stale failure counts from lingering after a quiet period.
        $ttl = $state === self::STATE_CLOSED
            ? Config::circuitBreakerCooldownSeconds()
            : Config::circuitBreakerCooldownSeconds() * 2;

        Cache::put($this->key(), [
            'state' => $state,
            'failures' => $failures,
            'opened_at' => $openedAt,
        ], $ttl);
    }

    private function key(): string
    {
        return Config::cachePrefix().':breaker:'.$this->integration->id;
    }
}
