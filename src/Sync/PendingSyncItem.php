<?php

declare(strict_types=1);

namespace Integrations\Sync;

/**
 * An item an adapter handed to `SyncSession::dispatch()`: the event to fire,
 * the checkpoint value it represents, and an optional external id. The
 * framework turns these into `integration_sync_items` rows and queued
 * `ProcessSyncItem` jobs; adapter tests can also inspect them via
 * `FakeSyncSession`.
 */
class PendingSyncItem
{
    public function __construct(
        public readonly SyncItemEvent $event,
        public readonly mixed $checkpointValue,
        public readonly ?string $externalId,
    ) {}
}
