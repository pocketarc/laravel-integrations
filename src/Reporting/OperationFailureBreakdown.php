<?php

declare(strict_types=1);

namespace Integrations\Reporting;

/**
 * Per-operation outcome counts over a window, derived from the top-level
 * integration_logs rows for one operation (e.g. "sync", "issue.create").
 * Rates are computed from the raw counts rather than stored, so they can't
 * drift out of sync.
 */
final readonly class OperationFailureBreakdown
{
    public function __construct(
        public string $operation,
        public int $total,
        public int $successful,
        public int $partial,
        public int $failed,
        public int $distinctItems,
    ) {}

    /** Failed share of this operation's logged outcomes, as a percentage (0–100). */
    public function failureRate(): float
    {
        return $this->total > 0 ? ($this->failed / $this->total) * 100 : 0.0;
    }
}
