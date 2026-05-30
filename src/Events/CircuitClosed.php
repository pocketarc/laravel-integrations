<?php

declare(strict_types=1);

namespace Integrations\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Integrations\Models\Integration;

/**
 * Fired when an integration's circuit breaker transitions to closed — whether
 * a half-open probe succeeded or an operator forced it closed. Carries a
 * reason so listeners can tell the cases apart.
 */
class CircuitClosed
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  'half_open_probe_succeeded'|'forced_closed'  $reason
     */
    public function __construct(
        public readonly Integration $integration,
        public readonly string $reason,
    ) {}
}
