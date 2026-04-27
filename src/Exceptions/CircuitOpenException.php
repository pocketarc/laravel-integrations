<?php

declare(strict_types=1);

namespace Integrations\Exceptions;

use Carbon\CarbonInterface;
use Integrations\Models\Integration;
use RuntimeException;

/**
 * Thrown when the circuit breaker for an integration is open and an
 * inbound request is short-circuited before reaching the provider. Not
 * retryable: there's no point hammering an integration we already know
 * is failing, which is the whole point of having a breaker.
 *
 * The breaker re-opens automatically after the cooldown elapses, at
 * which point a single half-open probe is allowed through to test the
 * waters; a successful probe closes the breaker for everyone.
 */
class CircuitOpenException extends RuntimeException
{
    public function __construct(
        public readonly Integration $integration,
        public readonly CarbonInterface $openedAt,
        public readonly int $cooldownSeconds,
    ) {
        $reopensAt = $openedAt->copy()->addSeconds($cooldownSeconds);

        parent::__construct(
            "Circuit breaker open for integration '{$integration->name}'. Will probe again at {$reopensAt->toDateTimeString()}.",
        );
    }
}
