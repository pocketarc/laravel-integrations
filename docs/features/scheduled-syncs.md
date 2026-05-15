# Scheduled syncs

Providers that implement `HasScheduledSync` get automated sync scheduling with health-aware backoff. The framework owns the hard parts: it wraps each synced item in a queued job, tracks per-item completion, and only advances the cursor past items whose listeners finished.

## The HasScheduledSync interface

```php
use Integrations\Contracts\HasScheduledSync;
use Integrations\Sync\SyncSession;

interface HasScheduledSync
{
    public function sync(Integration $integration, SyncSession $session): void;
    public function defaultSyncInterval(): int;  // minutes
    public function defaultRateLimit(): ?int;     // requests/minute, null = unlimited
    public function reduceCheckpoints(array $checkpoints): mixed;
}
```

A provider's `sync()` doesn't process items itself and doesn't return a result. It enumerates the items to sync and hands each one to `$session->dispatch()`. The framework turns those into `integration_sync_items` rows and a `Bus::batch` of `ProcessSyncItem` jobs, runs the listeners, and reconciles the run when the batch finishes.

## Setup

Add one line to your app's scheduler:

```php
// bootstrap/app.php (Laravel 11+)
Schedule::command('integrations:sync')->everyMinute();
```

The `integrations:sync` command finds all active integrations where `next_sync_at` has passed and dispatches a `SyncIntegration` job for each. Jobs use `WithoutOverlapping` to prevent concurrent syncs of the same integration, and a preflight check skips a run while a previous run's batch is still in flight.

::: warning v3 prerequisite
The sync flow dispatches a `Bus::batch`, which needs Laravel's `job_batches` table. If you haven't already, publish and run the framework's queue tables: `php artisan queue:batches-table` and `php artisan queue:failed-table`, then `php artisan migrate`.
:::

## Provider example

```php
use Integrations\Concerns\ReducesCheckpointsByMax;
use Integrations\Contracts\HasScheduledSync;
use Integrations\Contracts\IntegrationProvider;
use Integrations\Sync\SyncSession;

class GitHubProvider implements IntegrationProvider, HasScheduledSync
{
    use ReducesCheckpointsByMax;

    public function sync(Integration $integration, SyncSession $session): void
    {
        $meta = $integration->metadata;

        $issues = $integration
            ->at('/repos/{owner}/{repo}/issues')
            ->as(IssueListResponse::class)
            ->get(fn () => Http::withHeaders([
                'Authorization' => 'Bearer '.$integration->credentialsArray()['token'],
            ])->get("https://api.github.com/repos/{$meta['owner']}/{$meta['repo']}/issues"));

        foreach ($issues->issues as $issue) {
            $session->dispatch(
                new GitHubIssueSynced($integration, $issue),
                checkpointValue: $issue->updated_at,
                externalId: (string) $issue->id,
            );
        }
    }

    public function defaultSyncInterval(): int
    {
        return 5; // every 5 minutes
    }

    public function defaultRateLimit(): ?int
    {
        return 83; // ~5000 requests/hour (GitHub authenticated)
    }
}
```

`$session->dispatch()` takes the per-item event, the `checkpointValue` that item represents (its `updated_at`, an id, whatever your cursor is made of), and an optional `externalId` for operator-facing visibility. The provider never touches `sync_cursor` and never dispatches the event itself.

## What happens to each item

For every item handed to `$session->dispatch()`, the framework:

1. Inserts an `integration_sync_items` row (`status = pending`).
2. Adds a `ProcessSyncItem` job to the run's `Bus::batch`.
3. When the job runs, it invokes the event's listeners **synchronously** and marks the row `success` (or `failed` once its retries are exhausted).
4. When the whole batch finishes, `FinaliseSyncRun` reconciles: if every item succeeded it advances the cursor (via `reduceCheckpoints()`), marks the run's log `success`, and fires `SyncCompleted`. If anything failed, the cursor stays put and the log is marked `partial` (or `failed`).

Because the cursor only advances once the per-item jobs have completed, an item whose listener keeps failing can't be silently skipped: the cursor stops at it until it's resolved.

## Listeners must not be queued

Per-item events (anything extending `Integrations\Sync\SyncItemEvent`) are dispatched **inside** the framework's `ProcessSyncItem` job. That job is the queued unit; it provides the queueing, retries, and completion tracking. So your listeners for these events must run synchronously:

```php
// A plain listener. ProcessSyncItem runs it, retries it, tracks it.
class IngestGitHubIssue
{
    public function handle(GitHubIssueSynced $event): void
    {
        $issue = $event->issue;
        // upsert into the local DB...

        // Need heavier async work? Dispatch your own job from here.
        GenerateIssueSummary::dispatch($issue);
    }
}
```

Do not add `implements ShouldQueue` to a sync-item listener. If you do, `dispatch()` would return before the listener ran, and the run would report success before the work happened, which is the exact failure mode this design closes. `ProcessSyncItem` detects a queued listener and fails the item with `SyncListenerMustNotBeQueuedException` rather than letting it through.

Each attempt re-runs the listener, so listeners must be idempotent. The standard tools apply: [`upsertByExternalId()`](/features/id-mapping#upsert-by-external-id), `updateOrCreate()`, and so on.

## Cursor reduction

When a run finishes cleanly, the framework reduces the run's completed checkpoint values into the next `sync_cursor` by calling the provider's `reduceCheckpoints()`. Most providers want the maximum, which is what the `ReducesCheckpointsByMax` trait gives you (correct for ISO-8601 timestamps and lexicographically-ordered ids):

```php
use Integrations\Concerns\ReducesCheckpointsByMax;

class GitHubProvider implements IntegrationProvider, HasScheduledSync
{
    use ReducesCheckpointsByMax;
    // ...
}
```

Providers whose cursor isn't a comparable scalar (page tokens, structured cursors) implement `reduceCheckpoints(array $checkpoints): mixed` directly. The cursor advance is monotonic (the framework never moves `sync_cursor` backward), so a failed item inside an overlap window can't regress progress.

## Incremental sync

Providers that can fetch only the items changed since the last run implement `HasIncrementalSync`:

```php
use Integrations\Contracts\HasIncrementalSync;
use Integrations\Sync\SyncSession;

class GitHubProvider implements IntegrationProvider, HasIncrementalSync
{
    use ReducesCheckpointsByMax;

    public function syncIncremental(Integration $integration, SyncSession $session): void
    {
        $since = $session->cursor() ?? now()->subDay()->toIso8601String();
        $meta = $integration->metadata;

        $issues = $integration
            ->at('/repos/{owner}/{repo}/issues')
            ->as(IssueListResponse::class)
            ->get(fn () => Http::withHeaders([
                'Authorization' => 'Bearer '.$integration->credentialsArray()['token'],
            ])->get("https://api.github.com/repos/{$meta['owner']}/{$meta['repo']}/issues", [
                'since' => $since,
                'state' => 'all',
            ]));

        foreach ($issues->issues as $issue) {
            $session->dispatch(
                new GitHubIssueSynced($integration, $issue),
                checkpointValue: $issue->updated_at,
                externalId: (string) $issue->id,
            );
        }
    }

    // Also requires sync(), defaultSyncInterval(), defaultRateLimit() from HasScheduledSync.
}
```

Read the previous cursor with `$session->cursor()` (it's `null` on the first run) and scope the upstream request by it. When a provider implements `HasIncrementalSync`, the sync job calls `syncIncremental()` instead of `sync()`.

There is no longer any need to checkpoint the cursor mid-iteration. The framework's per-item tracking is the checkpoint: if the `SyncIntegration` job is SIGKILLed or times out while enumerating, the next run starts over from the unchanged cursor; once the batch is dispatched, the cursor advances per completed item regardless of what happens to the enumerating job.

## Per-integration intervals

Each integration can have its own sync frequency:

```php
$integration->update([
    'sync_interval_minutes' => 5,   // sync every 5 minutes
    'next_sync_at' => now(),         // start immediately
]);
```

If `sync_interval_minutes` is null, the provider's `defaultSyncInterval()` is used. If neither is set, the integration is not scheduled for sync.

After a run reconciles, `markSynced()` sets `last_synced_at` to now and computes the next `next_sync_at`.

## Health-aware backoff {#health-aware-backoff}

The sync scheduler respects health status. Degraded integrations sync at a reduced frequency, and failing integrations back off heavily:

| Health Status | Interval Multiplier | Example (5-min base)      |
|---------------|---------------------|---------------------------|
| Healthy       | 1x                  | Every 5 minutes           |
| Degraded      | 2x (configurable)   | Every 10 minutes          |
| Failing       | 10x (configurable)  | Every 50 minutes          |
| Disabled      | Not synced           | Requires manual re-enable |

## Recovering failed items

When a per-item job exhausts its retries, its row is marked `failed`, the underlying job lands in Laravel's `failed_jobs` table, and a [`SyncItemFailed`](/reference/events#sync) event fires. The cursor stays put: the run can't advance past an unresolved item.

To find and recover them:

```bash
# See what's failed and needs attention.
php artisan integrations:list-failed-items --integration=7

# Fix the cause, then retry the underlying queue job.
php artisan queue:retry <uuid>

# Or, if an item is genuinely unrecoverable, skip it so the cursor can move on.
php artisan integrations:skip-sync-item <id>
```

A retried item that succeeds, or an item that's skipped, lets the run reconcile and the cursor catch up automatically. `php artisan integrations:advance-cursor <integration>` re-runs the reconciliation for any sync run still stuck in `processing`, if you need to nudge it manually.

Items stuck at `processing` usually clear themselves once the queue's visibility timeout reclaims the job and a retry runs. If they don't, check `failed_jobs` with `queue:failed` and `queue:retry`; for an abandoned row, update its status to `failed` directly so `skip-sync-item` can take over.

## Sync timeline

The parent sync log records the API requests made while the provider was enumerating items:

```php
$syncLog = $integration->logs()->forOperation('sync')->latest()->first();
$requestIds = $syncLog->metadata['request_ids'] ?? [];
$requests = IntegrationRequest::whereIn('id', $requestIds)->get();
```

Its `metadata` also carries `success_count` and `failure_count` once the run reconciles.

## Configuration

```php
// config/integrations.php
'sync' => [
    'queue' => 'default',           // queue for the SyncIntegration job
    'queues' => [],                 // per-provider queue overrides
    'lock_ttl' => 1800,             // WithoutOverlapping lock TTL (must be >= job_timeout)
    'job_timeout' => 1800,          // SyncIntegration job timeout (30 min)
    'item_queue' => null,           // queue for ProcessSyncItem jobs (null = same as sync.queue)
    'item_tries' => 5,              // retries per item before it's marked failed
    'item_backoff' => [10, 30, 120, 300, 900], // seconds between item retries
    'max_items_per_batch' => 10000, // soft cap; a larger run logs a warning
],
```

Completed `integration_sync_items` rows (`success` / `skipped`) are pruned by `integrations:prune` after `pruning.sync_items_days` (default 30). `failed` rows are kept until you resolve them.
