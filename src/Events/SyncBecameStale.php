<?php

declare(strict_types=1);

namespace Integrations\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Integrations\Models\Integration;

/**
 * Fired when an integration first goes too long without a clean sync. One
 * event per episode: it fires again only after a clean sync ends the episode
 * ({@see SyncStalenessRecovered}) and the integration goes stale afresh.
 */
class SyncBecameStale
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Integration $integration,
        /** Seconds since the last clean sync, or since the integration was created. */
        public readonly int $secondsSinceLastCleanSync,
    ) {}
}
