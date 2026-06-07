<?php

declare(strict_types=1);

namespace Integrations\Reporting;

use Integrations\Enums\FailureClass;

/**
 * The failure rate for one integration over a rolling window, computed from
 * persisted requests by {@see FailureReporter::windowFailureRate()}. Drives the
 * anomaly evaluator's alerting decision.
 */
final readonly class FailureRateSnapshot
{
    public function __construct(
        /** Failed share of requests in the window, as a percentage (0–100). */
        public float $rate,
        /** Total requests observed in the window. */
        public int $observedRequests,
        /** The FailureClass with the most failures in the window. */
        public FailureClass $dominantClass,
        /** Rolling window size in minutes. */
        public int $windowMinutes,
    ) {}
}
