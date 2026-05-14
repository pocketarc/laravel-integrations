<?php

declare(strict_types=1);

namespace Integrations\Sync;

use Integrations\Models\Integration;

/**
 * Passed to a provider's `sync()` / `syncIncremental()`. The provider
 * enumerates the items to sync and hands each one to `dispatch()`; the
 * framework turns the accumulated items into `integration_sync_items` rows
 * and a `Bus::batch` of `ProcessSyncItem` jobs.
 *
 * The provider does not touch the cursor, dispatch events directly, or
 * track success/failure; the framework owns all of that. The provider
 * reads the incoming cursor via `cursor()` and, when it can fetch only
 * changed items, uses it to scope the upstream request.
 *
 * `FakeSyncSession` (shipped under `Integrations\Testing`) extends this for
 * adapter tests: it captures dispatched items so a test can assert on them
 * without running the queue.
 */
class SyncSession
{
    /** @var list<PendingSyncItem> */
    private array $items = [];

    public function __construct(
        private readonly Integration $integration,
        private readonly ?int $syncLogId = null,
    ) {}

    public function integration(): Integration
    {
        return $this->integration;
    }

    /**
     * The integration's current sync cursor: the value to scope the
     * upstream fetch by. `null` on the first sync.
     */
    public function cursor(): mixed
    {
        return $this->integration->sync_cursor;
    }

    /**
     * The parent `integration_logs` row id for this sync run, if one was
     * opened. Recorded on each `integration_sync_items` row.
     */
    public function syncLogId(): ?int
    {
        return $this->syncLogId;
    }

    /**
     * Hand an item to the framework for syncing. `$checkpointValue` is the
     * cursor token this item represents (e.g. its `updated_at`); the
     * framework reduces the run's completed checkpoints into the next
     * `sync_cursor` via the provider's `reduceCheckpoints()`. `$externalId`
     * is optional and only used for ops/debugging visibility.
     */
    public function dispatch(SyncItemEvent $event, mixed $checkpointValue = null, ?string $externalId = null): void
    {
        $this->items[] = new PendingSyncItem($event, $checkpointValue, $externalId);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * @return list<PendingSyncItem>
     *
     * @internal Consumed by `SyncIntegration` to build the batch.
     */
    public function pendingItems(): array
    {
        return $this->items;
    }
}
