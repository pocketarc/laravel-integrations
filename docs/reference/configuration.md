# Configuration reference

Full reference for `config/integrations.php`. Publish with:

```bash
php artisan vendor:publish --tag=integrations-config
```

## General

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `table_prefix` | `string` | `'integration'` | Prefix for all database tables |
| `cache_prefix` | `string` | `'integrations'` | Prefix for all cache keys |

## Webhook

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `webhook.prefix` | `string` | `'integrations'` | URL prefix: `POST /{prefix}/{provider}/webhook` |
| `webhook.queue` | `string` | `'default'` | Queue for `ProcessWebhook` jobs |
| `webhook.max_payload_bytes` | `int` | `1048576` | Reject payloads larger than this (1MB) |
| `webhook.processing_timeout` | `int` | `1800` | Seconds before a processing webhook is stale (30 min) |
| `webhook.middleware` | `array` | `[]` | Additional middleware for webhook routes |

## OAuth

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `oauth.route_prefix` | `string` | `'integrations'` | URL prefix for OAuth routes |
| `oauth.middleware` | `array` | `['web']` | Middleware for authorize + revoke routes |
| `oauth.callback_middleware` | `array` | `['web']` | Middleware for callback route |
| `oauth.success_redirect` | `string` | `'/integrations'` | Redirect after OAuth completes |
| `oauth.state_ttl` | `int` | `600` | State token validity in seconds (10 min) |
| `oauth.refresh_lock_ttl` | `int` | `30` | Cache lock TTL for token refresh |
| `oauth.refresh_lock_wait` | `int` | `15` | Max wait for refresh lock in seconds |

## Mappings

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `mappings.lock_ttl` | `int` | `10` | Cache lock TTL in seconds while `upsertByExternalId()` creates a model and claims its external ID |
| `mappings.lock_wait` | `int` | `10` | Max wait for that lock in seconds |
| `mappings.collation` | `?string` | `null` | Collation for `integration_mappings`' string columns. MySQL/MariaDB only; `null` inherits the connection default. Set it to match your own tables when comparing `internal_id` against your primary keys fails with `Illegal mix of collations`. |

The lock only serialises across processes on a shared cache driver. See [ID mapping](/features/id-mapping#concurrency).

## Sync

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `sync.queue` | `string` | `'default'` | Default queue for the `SyncIntegration` job |
| `sync.queues` | `array` | `[]` | Per-provider queue overrides (key = provider, value = queue) |
| `sync.lock_ttl` | `int` | `1800` | `WithoutOverlapping` lock TTL in seconds (must be ≥ `job_timeout`) |
| `sync.job_timeout` | `int` | `1800` | `SyncIntegration` job timeout in seconds (30 min). `integrations:sync` reads this on dispatch; direct callers can override via the constructor. |
| `sync.item_queue` | `?string` | `null` | Queue for the per-item `ProcessSyncItem` jobs. `null` = same as `sync.queue`. |
| `sync.item_tries` | `int` | `5` | Genuine listener exceptions before an item is marked `failed` and lands in `failed_jobs`. Transient rate-limit deferrals are not counted. |
| `sync.item_backoff` | `array` | `[10, 30, 120, 300, 900]` | Seconds between item retries |
| `sync.item_retry_window` | `int` | `21600` | Absolute seconds an item may keep retrying, including rate-limit deferrals (6h) |
| `sync.max_items_per_batch` | `int` | `10000` | Soft cap; a run with more items still processes as one batch but logs a warning |

## Retry

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `retry.retry_after_max_seconds` | `int` | `600` | Cap `Retry-After` header values (10 min) |

## Rate Limiting

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `rate_limiting.max_wait_seconds` | `int` | `10` | Max seconds to sleep waiting for capacity before throwing (0 = immediate). Applies independently to the suppression gate and the window bucket. |
| `rate_limiting.overrides_enabled` | `bool` | `true` | Honour per-integration rate-limit overrides. `false` ignores the override columns and falls back to the provider's `defaultRateLimit()`. |

## Circuit breaker

See [Circuit breaker](/advanced/circuit-breaker) for the full state machine, strategies, and runtime overrides.

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `circuit_breaker.enabled` | `bool` | `true` | Master switch. `false` disables breaker entirely. |
| `circuit_breaker.strategy` | `string` | `'rate'` | `'rate'` (failure % over a window) or `'count'` (consecutive failures). |
| `circuit_breaker.time_window` | `int` | `60` | Rate strategy: rolling failure window in seconds. |
| `circuit_breaker.failure_rate_threshold` | `int` | `50` | Rate strategy: failure percentage (1-100) that opens the breaker. |
| `circuit_breaker.minimum_requests` | `int` | `10` | Rate strategy: requests in the window before it can trip. |
| `circuit_breaker.threshold` | `int` | `5` | Count strategy: consecutive upstream failures before the breaker opens. |
| `circuit_breaker.cooldown_seconds` | `int` | `60` | Seconds to stay open before allowing a half-open probe. |
| `circuit_breaker.overrides_enabled` | `bool` | `true` | Honour per-integration circuit overrides. `false` ignores the override columns. |

## Observability

Failure-anomaly evaluation and the incident audit. See [the anomaly signal](/advanced/circuit-breaker#anomaly-signal) and [incident history](/core-concepts/health-monitoring#incident-history).

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `observability.anomaly_enabled` | `bool` | `true` | Master switch for `integrations:evaluate-failures`. `false` makes it a no-op. |
| `observability.anomaly_window_minutes` | `int` | `15` | Rolling window the failure rate is measured over. |
| `observability.anomaly_failure_rate_threshold` | `int` | `25` | Failure percentage (1-100) at or above which an anomaly fires. |
| `observability.anomaly_minimum_requests` | `int` | `20` | Requests in the window before the rate can fire. |
| `observability.incidents_enabled` | `bool` | `true` | Master switch for the durable incident audit (`integration_incidents`). |

## Health

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `health.degraded_after` | `int` | `5` | Consecutive failures before `degraded` |
| `health.failing_after` | `int` | `20` | Consecutive failures before `failing` |
| `health.disabled_after` | `?int` | `50` | Consecutive failures before `disabled` (null = never) |
| `health.degraded_backoff` | `int` | `2` | Sync interval multiplier when degraded |
| `health.failing_backoff` | `int` | `10` | Sync interval multiplier when failing |

## Logging

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `logging.max_response_bytes` | `?int` | `null` | Cap on a stored response body (null = store whole; values under 1024 are ignored) |

Cached and idempotent-write responses are stored whole regardless, since the package reads those back. See [Request logging](/core-concepts/logging#request-logging).

## Pruning

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `pruning.requests_days` | `int` | `90` | Retention for `integration_requests` |
| `pruning.logs_days` | `int` | `365` | Retention for `integration_logs` |
| `pruning.idempotency_keys_days` | `int` | `90` | Retention for `integration_idempotency_keys`. Set comfortably longer than your longest queue retry window. |
| `pruning.sync_items_days` | `int` | `30` | Retention for completed (`success` / `skipped`) `integration_sync_items`. `failed` rows are kept until resolved. |
| `pruning.incidents_days` | `int` | `365` | Retention for closed `integration_incidents` (by `closed_at`). Open incidents are never pruned. |
| `pruning.incidents_stale_after_days` | `int` | `7` | Auto-close incidents left open longer than this for a currently-healthy integration. |
| `pruning.chunk_size` | `int` | `1000` | Rows per delete batch |

## Providers

```php
'providers' => [
    'zendesk' => App\Integrations\ZendeskProvider::class,
    'github'  => App\Integrations\GitHubProvider::class,
],
```

Keys are provider identifiers (stored in the `Integration` model's `provider` column). Values are fully-qualified class names implementing `IntegrationProvider`. Can also be registered programmatically via `Integrations::register()`.
