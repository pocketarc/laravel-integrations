# Health monitoring

Integration health is tracked automatically based on request outcomes. It shares its definition of "failure" with the [circuit breaker](/advanced/circuit-breaker): both are driven by the same [`FailureClassifier`](/advanced/circuit-breaker#what-counts-as-a-failure), so they never disagree about whether an integration is broken. The two are otherwise distinct — health is a durable, per-integration record (Healthy → Degraded → Failing → Disabled) that drives [sync backoff](/features/scheduled-syncs#health-aware-backoff) and auto-disabling, while the breaker is transient, cache-backed, and gates individual requests.

## How it works

Each successful request resets `consecutive_failures` to 0 and sets `health_status` to `healthy`. Each **upstream** failure increments `consecutive_failures` and updates `last_error_at`.

Only `FailureClass::Upstream` (5xx except 501, connection errors, timeouts) counts toward health. A 429 throttle, a 4xx client error, or an unrecognised SDK exception leaves `consecutive_failures` untouched — a misbehaving caller spraying 404s can't degrade or auto-disable an integration for everyone sharing it. See [What counts as a failure](/advanced/circuit-breaker#what-counts-as-a-failure) for the full classification.

| Consecutive Failures | Status     | Default Threshold |
|----------------------|------------|-------------------|
| 0                    | `healthy`  | --                |
| 5+                   | `degraded` | `health.degraded_after` |
| 20+                  | `failing`  | `health.failing_after`  |
| 50+                  | `disabled` | `health.disabled_after` |

Any subsequent success resets back to `healthy`.

Disabled integrations stop syncing entirely and require manual re-enabling. Set `health.disabled_after` to `null` to disable automatic disabling.

## Events

Every health transition dispatches an `IntegrationHealthChanged` event with the previous and new status. When an integration is auto-disabled, an `IntegrationDisabled` event is also dispatched.

```php
use Integrations\Events\IntegrationHealthChanged;

class NotifyOnHealthDegradation
{
    public function handle(IntegrationHealthChanged $event): void
    {
        if ($event->newStatus->value !== 'healthy') {
            // Notify the team
        }
    }
}
```

## Failure summary {#failure-summary}

`Integration::failureSummary(CarbonInterface $since)` returns a report over a window — the same shape `integrations:health` and `integrations:stats` render, so a status page and the CLI agree on the numbers:

```php
$summary = $integration->failureSummary(now()->subDay());

$summary->failureRate();          // failed share of all requests (0–100)
$summary->byFailureClass;         // ['upstream' => 12, 'throttle' => 3, 'client' => 1, 'unknown' => 0]
$summary->byStatus;               // ['5xx' => 12, '4xx' => 1, '429' => 3, 'other' => 0]
$summary->topErrors;              // list<TopError> (message + count), highest first
$summary->lastErrorMessage;       // most recent error string
$summary->operations['sync'];     // OperationFailureBreakdown: total / successful / partial / failed / distinctItems
```

The headline `failureRate()` counts every failed request; use `byFailureClass` to derive an upstream-only rate, since only `FailureClass::Upstream` degrades health. The breakdown draws from `integration_requests` (HTTP-level failures) and `integration_logs` (operation-level outcomes). `failure_class` is persisted on each request row, so the per-class breakdown is queryable without re-classifying.

## Sync staleness {#sync-staleness}

`health_status` reflects whether the provider's API is answering, which is not the same as whether syncing is happening. An integration can fetch successfully and then fail while processing what it fetched. Every pass calls `recordSuccess()`, so `consecutive_failures` sits at zero and `health_status` stays `healthy` while nothing syncs. Health alone never reports that condition, however long it lasts.

Staleness is the separate signal for that. An active integration is stale when its last clean sync is older than `sync_interval_minutes` multiplied by [`sync.stale_after_intervals`](/reference/configuration#sync). For an integration that has never synced, the age is measured from when it was created, so a newly connected one is not stale before its first run.

```php
$integration->isSyncStale();    // bool
$integration->syncStaleness();  // ?int, seconds since the last clean sync
```

`integrations:health` and `integrations:list` both flag it, and `integrations:sync` emits [`SyncBecameStale`](/reference/events#syncbecamestale) once per episode, with [`SyncStalenessRecovered`](/reference/events#syncstalenessrecovered) when a clean sync resolves it. The open/closed state is stored in a `sync_stale_alerted_at` column rather than a cache entry, so nothing expires or is flushed between the two events.

Staleness deliberately stays out of the `HealthStatus` enum. `recordSuccess()` sets `health_status` to `Healthy` on every successful request, so a staleness degradation written there would be overwritten within seconds. Folding the two together would also blur two failures that need different responses: the provider is failing, or your own code is failing on data the provider returned correctly.

## Incident history {#incident-history}

Health transitions and circuit trips are events, and circuit state is cache-only and lost on `cache:clear`. The package records a durable `integration_incidents` audit from its own `IntegrationHealthChanged`, `CircuitOpened`, `CircuitClosed`, `IntegrationDisabled`, `SyncBecameStale`, and `SyncStalenessRecovered` events.

There's **one open incident per integration**: health degradation, circuit trips, and sync staleness fold into the same row. The row tracks the worst severity reached (`peak_severity`) and records which signal opened it in `source` (`health`, `circuit`, or `sync`). It closes only when every signal is clear: health back to `Healthy` and the sync no longer stale. Health alone is not enough, because a stuck integration keeps making successful requests. Operator overrides (`forced_open` / `forced_closed`) are deliberate actions, not detected failures, so they neither open nor close an incident.

```php
$integration->has_open_incident;       // bool (accessor; reads the loaded incidents relation)
$integration->current_incident;        // ?IntegrationIncident
$integration->incidents()->since(now()->subWeek())->get();
```

`integrations:prune` sweeps closed incidents (`pruning.incidents_days`, default 365) and, as a safety net, auto-closes incidents left open past `pruning.incidents_stale_after_days` for an integration that's currently healthy. A sync-stale integration is exempt from that sweep: it counts as healthy by `health_status`, and staleness can outlast the threshold, so closing its incident on age would hide a condition that is still current. Turn the whole audit off with `observability.incidents_enabled`.

## Health checks

Providers that implement `HasHealthCheck` can be probed without running a full sync:

```php
use Integrations\Contracts\HasHealthCheck;

interface HasHealthCheck
{
    public function healthCheck(Integration $integration): bool;
}
```

```php
class GitHubProvider implements IntegrationProvider, HasHealthCheck
{
    public function healthCheck(Integration $integration): bool
    {
        try {
            $integration
                ->at('/user')
                ->as(UserResponse::class)
                ->get(fn () => Http::withHeaders([
                    'Authorization' => 'Bearer '.$integration->credentialsArray()['token'],
                ])->get('https://api.github.com/user'));
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
```

Run health checks from the CLI:

```bash
php artisan integrations:test
```

`integrations:health` and `integrations:list` also surface each integration's live circuit-breaker state (or active override) alongside its health status.

## Querying by health

```php
Integration::where('health_status', 'failing')->get();
Integration::where('health_status', 'degraded')->get();
```

## Effect on sync scheduling

Health status affects sync frequency. See [Scheduled Syncs](/features/scheduled-syncs#health-aware-backoff) for the backoff multiplier table.

## Configuration

```php
// config/integrations.php
'health' => [
    'degraded_after' => 5,    // consecutive failures -> degraded
    'failing_after' => 20,    // consecutive failures -> failing
    'disabled_after' => 50,   // consecutive failures -> disabled (null = never)
    'degraded_backoff' => 2,  // sync interval multiplier when degraded
    'failing_backoff' => 10,  // sync interval multiplier when failing
],
```
