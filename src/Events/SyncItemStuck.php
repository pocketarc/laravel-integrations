<?php

declare(strict_types=1);

namespace Integrations\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationSyncItem;

/**
 * Fired when one item has failed across several consecutive sync runs with no
 * success in between: a single upstream record the sync cannot get past,
 * rather than an item that failed once ({@see SyncItemFailed} covers that).
 *
 * The cursor cannot advance past the item until it succeeds or is skipped, so
 * every later run re-enumerates the same window.
 */
class SyncItemStuck
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Integration $integration,
        public readonly IntegrationSyncItem $item,
        public readonly int $consecutiveFailedRuns,
    ) {}
}
