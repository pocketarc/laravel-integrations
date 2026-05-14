# Upgrade guide

## 2.x to 3.0

3.0 reworks how scheduled syncs advance the cursor. The provider contract changes, per-item events get a base class, and there's a new table. This guide covers every change you need to make.

### Why

In 2.x a sync dispatched an event per item and advanced the cursor as soon as the events were dispatched. If a consumer's listener was queued (`implements ShouldQueue`) and later exhausted its retries, the item sat in `failed_jobs` while the cursor had already moved past it. Once the item fell outside the overlap window, nothing re-fetched it: silent data loss.

In 3.0 the framework wraps each item in a queued `ProcessSyncItem` job, runs the listeners inside it, and only advances the cursor once every item's job has succeeded. A failed item stops the cursor at it until it's resolved.

### 1. Run the new migrations

3.0 adds the `integration_sync_items` table. It also dispatches a `Bus::batch`, which needs Laravel's `job_batches` table, and records exhausted item jobs in `failed_jobs`.

```bash
# If you don't already have them:
php artisan queue:batches-table
php artisan queue:failed-table

# Then publish + run this package's new migration:
php artisan vendor:publish --tag=integrations-migrations
php artisan migrate
```

### 2. Update your provider

`sync()` and `syncIncremental()` change signature: they take a `SyncSession` and return `void`. Instead of dispatching events and returning a `SyncResult`, the provider hands each item to `$session->dispatch()`.

**Before (2.x):**

```php
use Integrations\Sync\SyncResult;

public function syncIncremental(Integration $integration, mixed $cursor): SyncResult
{
    $since = $cursor ?? now()->subDay()->toIso8601String();
    $success = 0;
    $safeCursor = $since;

    foreach ($this->fetchItems($since) as $item) {
        try {
            ItemSynced::dispatch($integration, $item);
            $success++;
            $safeCursor = max($safeCursor, $item->updated_at);
        } catch (\Throwable $e) {
            ItemSyncFailed::dispatch($integration, $item, $e);
        }
    }

    return new SyncResult($success, 0, now(), $safeCursor);
}
```

**After (3.0):**

```php
use Integrations\Concerns\ReducesCheckpointsByMax;
use Integrations\Sync\SyncSession;

class MyProvider implements IntegrationProvider, HasIncrementalSync
{
    use ReducesCheckpointsByMax;

    public function syncIncremental(Integration $integration, SyncSession $session): void
    {
        $since = $session->cursor() ?? now()->subDay()->toIso8601String();

        foreach ($this->fetchItems($since) as $item) {
            $session->dispatch(
                new ItemSynced($integration, $item),
                checkpointValue: $item->updated_at,
                externalId: (string) $item->id,
            );
        }
    }
}
```

What changed:

- **No try/catch.** A listener failure is now the `ProcessSyncItem` job's failure; the framework retries it and records it. Don't catch it in the provider.
- **No counting, no `SyncResult`.** The framework derives success/failure counts from the `integration_sync_items` rows.
- **No cursor handling.** Pass each item's `checkpointValue`; the framework reduces the run's checkpoints into the next `sync_cursor` via `reduceCheckpoints()`.
- **`reduceCheckpoints()` is required.** `use ReducesCheckpointsByMax` for the common case (max of ISO-8601 timestamps / lexicographic ids), or implement it directly for non-comparable cursors.
- **`sync()` changes the same way:** `sync(Integration $integration, SyncSession $session): void`.

### 3. Update per-item events

Events handed to `$session->dispatch()` must extend `Integrations\Sync\SyncItemEvent`.

**Before:**

```php
use Illuminate\Foundation\Events\Dispatchable;

class ItemSynced
{
    use Dispatchable;

    public function __construct(
        public readonly Integration $integration,
        public readonly ItemData $item,
    ) {}
}
```

**After:**

```php
use Integrations\Sync\SyncItemEvent;

class ItemSynced extends SyncItemEvent
{
    public function __construct(
        public readonly Integration $integration,
        public readonly ItemData $item,
    ) {}
}
```

`SyncItemEvent` already pulls in `Dispatchable` and `SerializesModels`, so drop those traits from your event.

### 4. Make sync-item listeners synchronous

This is the consumer-facing change. A listener for a `SyncItemEvent` **must not implement `ShouldQueue`**. The `ProcessSyncItem` job is already the queued unit; a queued listener would let it report success before the listener ran. `ProcessSyncItem` rejects a queued listener with `SyncListenerMustNotBeQueuedException`.

**Before:**

```php
use Illuminate\Contracts\Queue\ShouldQueue;

class IngestItem implements ShouldQueue
{
    public function handle(ItemSynced $event): void
    {
        // heavy work...
    }
}
```

**After:**

```php
class IngestItem
{
    public function handle(ItemSynced $event): void
    {
        // The sync-critical work runs here, synchronously.
        $local = MyModel::updateOrCreate(/* ... */);

        // Genuinely async follow-up work? Dispatch your own job.
        GenerateSummary::dispatch($local);
    }
}
```

Each attempt re-runs the listener, so it must be idempotent: use `upsertByExternalId()` / `updateOrCreate()`.

### 5. Replace removed events

The per-adapter aggregate and failure events are gone. Switch to the canonical core events:

| 2.x | 3.0 |
|-----|-----|
| `{Adapter}SyncCompleted` | `Integrations\Events\SyncCompleted` |
| `{Adapter}ItemSyncFailed` | `Integrations\Events\SyncItemFailed` |

`SyncCompleted` carries the `Integration` and a `SyncResult`. `SyncItemFailed` carries the `Integration`, the `IntegrationSyncItem` row, and the `Throwable`.

### 6. `SyncResult` is now internal

`SyncResult` is `@internal` in 3.0: the framework builds it and hands it to the `SyncCompleted` event. If you constructed `SyncResult` directly (in tests, custom flows), read it off the `SyncCompleted` event instead.

### Recovery

Once on 3.0, a failed item stops the cursor until you resolve it:

```bash
php artisan integrations:list-failed-items     # see what needs attention
php artisan queue:retry <uuid>                 # fix the cause, retry the item
php artisan integrations:skip-sync-item <id>   # or skip an unrecoverable one
```

See [Scheduled syncs](https://laravel-integrations.pocketarc.com/features/scheduled-syncs) for the full picture.
