<?php

declare(strict_types=1);

namespace Integrations\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationSyncItem;
use Throwable;

/**
 * Fired when a per-item sync job exhausts its retries. The
 * `IntegrationSyncItem` row is already marked `failed` by the time this
 * fires, and the underlying job is in Laravel's `failed_jobs` table.
 *
 * Replaces the per-adapter failure events (`ZendeskTicketSyncFailed`,
 * `GitHubIssueSyncFailed`). Hook this to push to Sentry/Slack/etc.
 */
class SyncItemFailed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Integration $integration,
        public readonly IntegrationSyncItem $item,
        public readonly Throwable $exception,
    ) {}
}
