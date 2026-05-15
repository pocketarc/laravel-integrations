<?php

declare(strict_types=1);

namespace Integrations\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Integrations\Models\Integration;
use Integrations\Sync\SyncResult;

/**
 * Fired once a sync run finishes, after every per-item job in the run has
 * reached a terminal state. `$result->hasFailures()` distinguishes a clean
 * run from a partial one.
 *
 * Replaces the per-adapter aggregate events (`ZendeskSyncCompleted`,
 * `GitHubSyncCompleted`).
 */
class SyncCompleted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Integration $integration,
        public readonly SyncResult $result,
    ) {}
}
