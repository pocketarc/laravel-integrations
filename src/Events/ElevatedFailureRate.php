<?php

declare(strict_types=1);

namespace Integrations\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Integrations\Enums\FailureClass;
use Integrations\Models\Integration;

/**
 * Fired by `integrations:evaluate-failures` when an integration's failure rate
 * over the configured window crosses the threshold. Debounced to one event per
 * incident (until the rate recovers or the debounce window elapses), so a
 * consumer can raise a single alert per incident rather than one per failure.
 * Where the alert goes (Sentry, Slack, …) is the consumer's call.
 */
class ElevatedFailureRate
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Integration $integration,
        /** Failed share of requests in the window, as a percentage (0–100). */
        public readonly float $failureRate,
        public readonly int $windowMinutes,
        public readonly int $observedRequests,
        /** The FailureClass with the most failures in the window. */
        public readonly FailureClass $dominantClass,
    ) {}
}
