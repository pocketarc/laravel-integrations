<?php

declare(strict_types=1);

namespace Integrations\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Integrations\Models\Integration;

/**
 * Fired by `integrations:evaluate-failures` when an integration that previously
 * had an elevated failure rate drops back below the threshold. The mirror of
 * {@see ElevatedFailureRate}, so a consumer can resolve the alert it raised and
 * the next incident alerts immediately rather than staying debounced.
 */
class FailureRateRecovered
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Integration $integration,
    ) {}
}
