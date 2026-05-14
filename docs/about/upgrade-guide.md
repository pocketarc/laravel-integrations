# Upgrade guide

This project follows [Semantic Versioning](https://semver.org/). Minor and patch releases will never contain breaking changes.

## 2.x to 3.0

3.0 reworks how scheduled syncs advance the cursor: the framework now tracks per-item completion, so the cursor only moves past items whose listeners actually finished. This closes a silent-data-loss gap where a queued listener that exhausted its retries left the item in `failed_jobs` while the cursor had already moved on.

The full step-by-step migration (provider contract, per-item events, listeners, removed events) is in [`UPGRADE.md`](https://github.com/pocketarc/laravel-integrations/blob/main/UPGRADE.md) at the repository root. In short:

1. **Run migrations.** 3.0 adds the `integration_sync_items` table, and the sync flow dispatches a `Bus::batch`, so Laravel's `job_batches` table must exist (`php artisan queue:batches-table`).
2. **Update providers.** `sync()` / `syncIncremental()` take a `SyncSession` and return `void`. Hand each item to `$session->dispatch($event, $checkpointValue, $externalId)` instead of dispatching events and returning a `SyncResult`. Add `reduceCheckpoints()` (or `use ReducesCheckpointsByMax`).
3. **Update per-item events.** They must extend `Integrations\Sync\SyncItemEvent`.
4. **Make sync-item listeners synchronous.** Remove `implements ShouldQueue`; `ProcessSyncItem` is the queued unit now. Dispatch your own job from inside the listener if you need async follow-up.
5. **Replace removed events.** Per-adapter `*SyncCompleted` / `*SyncFailed` events are gone; use `Integrations\Events\SyncCompleted` and `SyncItemFailed`.

See [Scheduled syncs](/features/scheduled-syncs) for the new model and the recovery commands.
