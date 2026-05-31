<?php

declare(strict_types=1);

namespace Integrations\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Integrations\Models\Integration;

/**
 * Fired when an integration's circuit breaker transitions to open — whether
 * tripped automatically or forced open by an operator. Carries a reason so
 * listeners (alerting, dashboards) can tell the cases apart.
 */
class CircuitOpened
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  'threshold_reached'|'half_open_probe_failed'|'forced_open'  $reason
     */
    public function __construct(
        public readonly Integration $integration,
        public readonly string $reason,
    ) {}
}
