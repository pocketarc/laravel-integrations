# Scheduled syncs

Providers that implement `HasScheduledSync` get automated sync scheduling with health-aware backoff. The framework owns the hard parts: it wraps each synced item in a queued job, tracks per-item completion, and only advances the cursor past items whose listeners finished.

## The HasScheduledSync interface

```php
use Integrations\Contracts\DeclaresRateLimit;
use Integrations\Contracts\HasScheduledSync;
use Integrations\Sync\SyncSession;

interface HasScheduledSync extends DeclaresRateLimit
{
    public function sync(Integration $integration, SyncSession $session): void;
    public function defaultSyncInterval(): int;     // minutes
    public function reduceCheckpoints(array $checkpoints): mixed;
}
```

`HasScheduledSync` extends [`DeclaresRateLimit`](/reference/contracts#declaresratelimit), so a sync provider also implements `defaultRateLimit(): ?RateLimit` (null = unlimited) to declare its [rate budget](/core-concepts/rate-limiting).

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
use Integrations\RateLimit;
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

    public function defaultRateLimit(): ?RateLimit
    {
        return RateLimit::perHour(5000); // GitHub's authenticated budget
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

Once a run is reconciled, the two sync timestamps move independently:

- `next_sync_at` advances on every finalised run, clean or not. `dueForSync()` matches `next_sync_at <= now()`, so a run that leaves it where it was makes the integration due on every scheduler tick.
- `last_synced_at` advances only on a run where every item succeeded, so it records the last time the integration synced cleanly. [Sync staleness](/core-concepts/health-monitoring#sync-staleness) is measured from it.

## When runs keep failing {#failure-backoff}

`consecutive_sync_failures` counts runs that finalised with at least one failed item, and resets to zero on the first clean run. While it is above zero, `next_sync_at` is pushed out by doubling:

| Consecutive failed runs | Interval multiplier | Example (15-min base) |
|-------------------------|---------------------|-----------------------|
| 0 (clean)               | 1x                  | Every 15 minutes      |
| 1                       | 1x                  | Every 15 minutes      |
| 2                       | 2x                  | Every 30 minutes      |
| 3                       | 4x                  | Every hour            |
| 5+                      | Capped at 16x       | Every 4 hours         |

The cap comes from [`sync.failure_backoff_max_multiplier`](/reference/configuration#sync). The first failure gets the plain interval, so an isolated bad run costs nothing.

The cursor cannot advance past an unresolved item, so each further run re-enumerates a window that keeps growing. Without the backoff, one permanently-failing item leaves the integration due on every scheduler tick, and the same failing sync repeats against the provider's API until the item is resolved.

## Health-aware backoff {#health-aware-backoff}

The sync scheduler respects health status. Degraded integrations sync at a reduced frequency, and failing integrations back off heavily:

| Health Status | Interval Multiplier | Example (5-min base)      |
|---------------|---------------------|---------------------------|
| Healthy       | 1x                  | Every 5 minutes           |
| Degraded      | 2x (configurable)   | Every 10 minutes          |
| Failing       | 10x (configurable)  | Every 50 minutes          |
| Disabled      | Not synced           | Requires manual re-enable |

This backoff is independent of the failure backoff above. A run starts only once both intervals have elapsed, so the later of the two applies, and the two never compound. Health-aware backoff is measured from `last_synced_at`, which a failing run does not move, so it has no effect while runs keep failing. The `next_sync_at` backoff covers that case.

## Recovering failed items

When a per-item job exhausts its retries, its row is marked `failed`, the underlying job lands in Laravel's `failed_jobs` table, and a [`SyncItemFailed`](/reference/events#syncitemfailed) event fires. The cursor stays put: the run can't advance past an unresolved item.

::: tip Alerting on terminal failures
`SyncItemFailed` fires exactly once, only on genuine exhaustion — so it's the event to forward to Sentry or Slack. A listener that logs `failed` and re-throws fires [`OperationFailed`](/reference/events#operationfailed) on *every* attempt by design; alert on that and a transient hiccup that recovers on retry still pages you. See [terminal vs transient failures](/core-concepts/logging#terminal-vs-transient-failures).
:::

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

`integrations:list-failed-items` shows the sync-item rows. `php artisan queue:failed` shows the underlying jobs, with the exception and stack trace behind each failure. Use `queue:failed` to find the UUID for `queue:retry` and to read the exception.

A row at `processing` is not necessarily failed. It may be a rate-limited item that the queue deferred, or one whose job is still behind a backlog. Both clear on their own. For a row whose job no longer exists, see [abandoned items](#abandoned-items) below.

## When one item keeps failing {#stuck-items}

An item that fails in run after run blocks everything behind it, because the cursor cannot advance past it. [`SyncItemStuck`](/reference/events#syncitemstuck) fires once an external ID has failed [`sync.stuck_item_after_runs`](/reference/configuration#sync) runs in a row with no success in between. It carries the item, so an alert can name the record to fix.

Use `SyncItemFailed` for a single failed attempt, and `SyncItemStuck` for the record that blocks the cursor. The streak is counted across runs rather than from the row's `attempts` column: each run inserts fresh rows, so `attempts` never accumulates. A later success or skip resets the streak, and the event can fire again after that.

An item with no external ID never contributes to a streak, because nothing identifies it across runs.

## Abandoned items {#abandoned-items}

An item is abandoned when its row is still marked as in flight but no queue job remains to run it. A queue worker restarted mid-batch leaves rows in that state. The preflight check finds such a row on every later run, so the integration stops syncing until the row reaches a terminal state.

The next run reclaims abandoned rows. Rows still in flight past [`sync.item_reclaim_after`](/reference/configuration#sync) are marked `failed`, with an error message that records the reclaim, and their run is re-finalised. That closes the run log and lets the schedule move on. The cursor stays put, so the following run re-enumerates the same window and redoes the work.

::: warning Do not skip a reclaimed item
A reclaimed row never ran, so `integrations:skip-sync-item` would advance the cursor past work that has not happened. The row's `error` column records the reclaim. Leave the row alone and let the next run re-enumerate it.
:::

The threshold is long, and never applies below `item_retry_window` plus an hour, because a rate-limited item is released back onto the queue and sits in flight for hours by design. To reclaim the rows now rather than waiting the threshold out:

```bash
php artisan integrations:advance-cursor <integration> --reclaim-stale
```

## Duplicate items in one run {#duplicate-items}

A provider that pages an inclusive cursor (one page's end time becomes the next page's start time) re-emits the record on that boundary. `SyncSession::dispatch()` keeps the first copy and drops the repeat, so one record produces one job.

Deduplication keys on the external ID and the event class together, so a provider that emits genuinely different work for one record still produces both items. Deduplication needs an `$externalId`. An item dispatched without one is never deduplicated.

The count of dropped repeats is recorded on the run's log as `metadata['duplicates_dropped']` and logged as a warning. Both are diagnostics, not a fix: the duplicate fetch still happened upstream, and correcting that belongs in the provider's paging.

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
    'item_tries' => 5,              // genuine listener exceptions before an item is marked failed
    'item_backoff' => [10, 30, 120, 300, 900], // seconds between item retries
    'item_retry_window' => 21600,   // absolute retry window per item, incl. rate-limit deferrals (6h)
    'max_items_per_batch' => 10000, // soft cap; a larger run logs a warning
    'item_reclaim_after' => 43200,  // in-flight time before an item is presumed abandoned (12h)
    'failure_backoff_max_multiplier' => 16, // ceiling on the next_sync_at backoff
    'stuck_item_after_runs' => 5,   // consecutive failed runs before SyncItemStuck fires
    'stale_after_intervals' => 10,  // missed intervals before an integration counts as stale
],
```

Completed `integration_sync_items` rows (`success` / `skipped`) are pruned by `integrations:prune` after `pruning.sync_items_days` (default 30). `failed` rows are kept until you resolve them.
