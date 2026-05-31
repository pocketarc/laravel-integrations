<?php

declare(strict_types=1);

namespace Integrations\Concerns;

use Carbon\CarbonInterface;
use Integrations\CircuitBreaker;
use Integrations\Contracts\DeclaresRateLimit;
use Integrations\Enums\CircuitOverride;
use Integrations\Enums\RateLimitWindow;
use Integrations\Events\CircuitClosed;
use Integrations\Events\CircuitOpened;
use Integrations\Models\Integration;
use Integrations\RateLimit;
use Integrations\Support\Config;

/**
 * Runtime, no-redeploy overrides for an integration's circuit breaker and rate
 * limit, stored on the model so they survive a cache flush. Operators set them
 * via these helpers (or the integrations:circuit / integrations:rate-limit
 * commands); the breaker and rate limiter read the "effective" values, which
 * honour expiry and the global config toggles.
 *
 * @mixin Integration
 */
trait ManagesResilienceOverrides
{
    /**
     * Force the circuit breaker open (block all requests) until $until, or
     * indefinitely. Survives a cache flush; clear with clearCircuitOverride().
     */
    public function forceCircuitOpen(?CarbonInterface $until = null): void
    {
        $this->update([
            'circuit_override' => CircuitOverride::ForcedOpen,
            'circuit_override_until' => $until,
        ]);

        CircuitOpened::dispatch($this, 'forced_open');
    }

    /**
     * Force the circuit breaker closed (always allow, never trip) until $until,
     * or indefinitely.
     */
    public function forceCircuitClosed(?CarbonInterface $until = null): void
    {
        $this->update([
            'circuit_override' => CircuitOverride::ForcedClosed,
            'circuit_override_until' => $until,
        ]);

        CircuitClosed::dispatch($this, 'forced_closed');
    }

    /**
     * Disable the circuit breaker entirely (no short-circuiting, no
     * accounting) until $until, or indefinitely.
     */
    public function disableCircuit(?CarbonInterface $until = null): void
    {
        $this->update([
            'circuit_override' => CircuitOverride::Disabled,
            'circuit_override_until' => $until,
        ]);
    }

    /**
     * Return to automatic breaker behaviour and reset its cached state so a
     * stale OPEN entry can't immediately short-circuit the next request.
     */
    public function clearCircuitOverride(): void
    {
        $this->update([
            'circuit_override' => null,
            'circuit_override_until' => null,
        ]);

        (new CircuitBreaker($this))->reset();
    }

    /**
     * Override the rate limit for this integration, taking precedence over the
     * provider's defaultRateLimit(), until $until or indefinitely.
     *
     * Deliberately does NOT flush the window buckets or the provider-fed
     * suppression key: buckets are window-scoped and self-heal within one
     * window, and the suppression key reflects an upstream Retry-After that is
     * independent of the configured limit.
     */
    public function overrideRateLimit(RateLimit $limit, ?CarbonInterface $until = null): void
    {
        $this->update([
            'rate_limit_override' => [
                'limit' => $limit->limit,
                'windowSeconds' => $limit->windowSeconds,
                'window' => $limit->window->value,
            ],
            'rate_limit_override_until' => $until,
        ]);
    }

    public function clearRateLimitOverride(): void
    {
        $this->update([
            'rate_limit_override' => null,
            'rate_limit_override_until' => null,
        ]);
    }

    /**
     * The active circuit override, honouring expiry and the global toggle.
     * Returns null (auto) when overrides are disabled, none is set, or it has
     * expired (in which case the expired row is cleared best-effort).
     */
    public function effectiveCircuitOverride(): ?CircuitOverride
    {
        if (! Config::circuitOverridesEnabled()) {
            return null;
        }

        $override = $this->circuit_override;
        if ($override === null) {
            return null;
        }

        $until = $this->circuit_override_until;
        if ($until !== null && $until->isPast()) {
            $this->forgetExpiredCircuitOverride();

            return null;
        }

        return $override;
    }

    /**
     * The effective rate limit: a live override (honouring expiry and the
     * global toggle) takes precedence over the provider's defaultRateLimit().
     * A malformed override falls through to the provider rather than throwing.
     */
    public function effectiveRateLimit(): ?RateLimit
    {
        $override = $this->rate_limit_override;
        $until = $this->rate_limit_override_until;
        $expired = $until !== null && $until->isPast();

        if (Config::rateLimitOverridesEnabled() && is_array($override) && ! $expired) {
            $limit = self::rateLimitFromArray($override);
            if ($limit !== null) {
                return $limit;
            }
        } elseif (is_array($override) && $expired) {
            $this->forgetExpiredRateLimitOverride();
        }

        $provider = $this->provider();

        return $provider instanceof DeclaresRateLimit ? $provider->defaultRateLimit() : null;
    }

    private function forgetExpiredCircuitOverride(): void
    {
        $this->circuit_override = null;
        $this->circuit_override_until = null;

        if ($this->exists) {
            // Conditional update so a just-set override (written by another
            // process after our read) is never clobbered. Use the instance's
            // own query builder so it reuses this model's connection.
            $this->newQuery()
                ->whereKey($this->getKey())
                ->where('circuit_override_until', '<=', now())
                ->update(['circuit_override' => null, 'circuit_override_until' => null]);
            $this->syncOriginal();
        }
    }

    private function forgetExpiredRateLimitOverride(): void
    {
        $this->rate_limit_override = null;
        $this->rate_limit_override_until = null;

        if ($this->exists) {
            $this->newQuery()
                ->whereKey($this->getKey())
                ->where('rate_limit_override_until', '<=', now())
                ->update(['rate_limit_override' => null, 'rate_limit_override_until' => null]);
            $this->syncOriginal();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function rateLimitFromArray(array $data): ?RateLimit
    {
        $limit = $data['limit'] ?? null;
        $windowSeconds = $data['windowSeconds'] ?? null;
        $window = $data['window'] ?? null;

        if (! is_int($limit) || ! is_int($windowSeconds) || ! is_string($window)) {
            return null;
        }

        $windowEnum = RateLimitWindow::tryFrom($window);
        if ($windowEnum === null || $limit < 1 || $windowSeconds < 1) {
            return null;
        }

        return new RateLimit($limit, $windowSeconds, $windowEnum);
    }
}
