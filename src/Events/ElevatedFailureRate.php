<?php

declare(strict_types=1);

namespace Integrations\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Integrations\Enums\FailureClass;
use Integrations\Models\Integration;

/**
 * Fired by `integrations:evaluate-failures` when an integration's failure rate
 * over the configured window first crosses the threshold. One event per incident
 * (it won't fire again until the rate recovers, see {@see FailureRateRecovered}),
 * so a consumer can raise a single alert per incident rather than one per
 * failure. Where the alert goes (Sentry, Slack, …) is the consumer's call.
 */
class ElevatedFailureRate
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Integration $integration,
        /** Failed share of requests in the window, as a percentage (0–100). */
        public readonly float $failureRate,
        /** Rolling window size in minutes. */
        public readonly int $windowMinutes,
        /** Total requests observed in the window. */
        public readonly int $observedRequests,
        /** The FailureClass with the most failures in the window. */
        public readonly FailureClass $dominantClass,
    ) {}
}
