<?php

declare(strict_types=1);

namespace Integrations\Reporting;

use Carbon\CarbonInterface;

/**
 * A point-in-time failure report for one integration over a window, computed
 * by {@see FailureReporter}. It draws from integration_requests (HTTP-level
 * failures: rate, last error, FailureClass, status buckets, top errors) and
 * integration_logs (operation-level outcomes).
 *
 * The headline failure rate is on response_success (every failed request),
 * matching what `integrations:stats` reports; use byFailureClass to derive an
 * upstream-only rate, since only FailureClass::Upstream degrades health.
 */
final readonly class FailureSummary
{
    /**
     * @param  array<string, int>  $byFailureClass  keyed by FailureClass value; all four cases present
     * @param  array<int|string, int>  $byStatus  keyed by '5xx' | '4xx' | '429' | 'other' (429 as an int key)
     * @param  list<TopError>  $topErrors  most frequent error messages, highest first
     * @param  array<string, OperationFailureBreakdown>  $operations  keyed by operation name
     */
    public function __construct(
        public CarbonInterface $since,
        public int $totalRequests,
        public int $failedRequests,
        public int $distinctEndpoints,
        public ?float $avgSuccessfulDurationMs,
        public ?CarbonInterface $lastErrorAt,
        public ?string $lastErrorMessage,
        public array $byFailureClass,
        public array $byStatus,
        public array $topErrors,
        public array $operations,
    ) {}

    /** Failed share of all requests in the window, as a percentage (0–100). */
    public function failureRate(): float
    {
        return $this->totalRequests > 0 ? ($this->failedRequests / $this->totalRequests) * 100 : 0.0;
    }
}
