<?php

declare(strict_types=1);

namespace Integrations\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Integrations\Models\Integration;

/**
 * Fired when an integration that had gone stale syncs cleanly again, closing
 * the episode that {@see SyncBecameStale} opened.
 */
class SyncStalenessRecovered
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Integration $integration,
    ) {}
}
