# Scheduled syncs

Providers that implement `HasScheduledSync` get automated sync scheduling with health-aware backoff.

## The HasScheduledSync interface

```php
use Integrations\Contracts\HasScheduledSync;

interface HasScheduledSync
{
    public function sync(Integration $integration): SyncResult;
    public function defaultSyncInterval(): int;  // minutes
    public function defaultRateLimit(): ?int;     // requests/minute, null = unlimited
}
```

## Setup

Add one line to your app's scheduler:

```php
// bootstrap/app.php (Laravel 11+)
Schedule::command('integrations:sync')->everyMinute();
```

The `integrations:sync` command finds all active integrations where `next_sync_at` has passed and dispatches a `SyncIntegration` job for each. Jobs use `WithoutOverlapping` to prevent concurrent syncs of the same integration.

## Provider example

```php
class GitHubProvider implements IntegrationProvider, HasScheduledSync
{
    public function sync(Integration $integration): SyncResult
    {
        $meta = $integration->metadata;

        $issues = $integration->requestAs(
            endpoint: '/repos/{owner}/{repo}/issues',
            method: 'GET',
            responseClass: IssueListResponse::class,
            callback: fn () => Http::withHeaders([
                'Authorization' => 'Bearer '.$integration->credentialsArray()['token'],
            ])->get("https://api.github.com/repos/{$meta['owner']}/{$meta['repo']}/issues"),
        );

        $count = 0;
        foreach ($issues->issues as $issue) {
            // Process each issue...
            $count++;
        }

        return new SyncResult($count, 0, now());
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

## Per-integration intervals

Each integration can have its own sync frequency:

```php
$integration->update([
    'sync_interval_minutes' => 5,   // sync every 5 minutes
    'next_sync_at' => now(),         // start immediately
]);
```

If `sync_interval_minutes` is null, the provider's `defaultSyncInterval()` is used. If neither is set, the integration is not scheduled for sync.

After a successful sync, `markSynced()` sets `last_synced_at` to now and computes the next `next_sync_at`.

## Health-aware backoff {#health-aware-backoff}

The sync scheduler respects health status. Degraded integrations sync at a reduced frequency, and failing integrations back off heavily:

| Health Status | Interval Multiplier | Example (5-min base)      |
|---------------|---------------------|---------------------------|
| Healthy       | 1x                  | Every 5 minutes           |
| Degraded      | 2x (configurable)   | Every 10 minutes          |
| Failing       | 10x (configurable)  | Every 50 minutes          |
| Disabled      | Not synced           | Requires manual re-enable |

## Incremental sync

For providers that support fetching only changed records since a cursor or timestamp, implement `HasIncrementalSync`:

```php
use Integrations\Contracts\HasIncrementalSync;

class GitHubProvider implements IntegrationProvider, HasIncrementalSync
{
    public function syncIncremental(Integration $integration, mixed $cursor): SyncResult
    {
        $since = $cursor ?? now()->subDay()->toIso8601String();
        $meta = $integration->metadata;

        $issues = $integration->requestAs(
            endpoint: '/repos/{owner}/{repo}/issues',
            method: 'GET',
            responseClass: IssueListResponse::class,
            callback: fn () => Http::withHeaders([
                'Authorization' => 'Bearer '.$integration->credentialsArray()['token'],
            ])->get("https://api.github.com/repos/{$meta['owner']}/{$meta['repo']}/issues", [
                'since' => $since,
                'state' => 'all',
            ]),
        );

        // Process issues...

        return new SyncResult(
            successCount: count($issues->issues),
            failureCount: 0,
            safeSyncedAt: now(),
            cursor: now()->toIso8601String(), // stored for next sync
        );
    }

    // Also requires sync(), defaultSyncInterval(), defaultRateLimit() from HasScheduledSync
}
```

The cursor is stored as JSON in the `sync_cursor` column and passed to `syncIncremental()` on the next sync. When a provider implements `HasIncrementalSync`, the sync job calls `syncIncremental()` instead of `sync()`.

## Sync timeline

During a sync, all API requests made via the integration are tracked and their IDs stored in the parent sync log's metadata:

```php
$syncLog = $integration->logs()->forOperation('sync')->latest()->first();
$requestIds = $syncLog->metadata['request_ids'] ?? [];
$requests = IntegrationRequest::whereIn('id', $requestIds)->get();
```

## Configuration

```php
// config/integrations.php
'sync' => [
    'queue' => 'default',
    'queues' => [],      // per-provider queue overrides
    'lock_ttl' => 600,   // WithoutOverlapping lock TTL
],
```
