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

## Sync

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `sync.queue` | `string` | `'default'` | Default queue for sync jobs |
| `sync.queues` | `array` | `[]` | Per-provider queue overrides (key = provider, value = queue) |
| `sync.lock_ttl` | `int` | `600` | `WithoutOverlapping` lock TTL in seconds |

## Retry

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `retry.retry_after_max_seconds` | `int` | `600` | Cap `Retry-After` header values (10 min) |

## Rate Limiting

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `rate_limiting.max_wait_seconds` | `int` | `10` | Wait for capacity before throwing (0 = immediate) |

## Circuit breaker

See [Circuit breaker](/advanced/circuit-breaker) for the full state machine.

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `circuit_breaker.enabled` | `bool` | `true` | Master switch. `false` disables breaker entirely. |
| `circuit_breaker.threshold` | `int` | `5` | Consecutive failures before the breaker opens. |
| `circuit_breaker.cooldown_seconds` | `int` | `60` | Seconds to stay open before allowing a half-open probe. |

## Health

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `health.degraded_after` | `int` | `5` | Consecutive failures before `degraded` |
| `health.failing_after` | `int` | `20` | Consecutive failures before `failing` |
| `health.disabled_after` | `?int` | `50` | Consecutive failures before `disabled` (null = never) |
| `health.degraded_backoff` | `int` | `2` | Sync interval multiplier when degraded |
| `health.failing_backoff` | `int` | `10` | Sync interval multiplier when failing |

## Pruning

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `pruning.requests_days` | `int` | `90` | Retention for `integration_requests` |
| `pruning.logs_days` | `int` | `365` | Retention for `integration_logs` |
| `pruning.reservations_days` | `int` | `90` | Retention for `integration_idempotency_reservations`. Set comfortably longer than your longest queue retry window. |
| `pruning.chunk_size` | `int` | `1000` | Rows per delete batch |

## Providers

```php
'providers' => [
    'zendesk' => App\Integrations\ZendeskProvider::class,
    'github'  => App\Integrations\GitHubProvider::class,
],
```

Keys are provider identifiers (stored in the `Integration` model's `provider` column). Values are fully-qualified class names implementing `IntegrationProvider`. Can also be registered programmatically via `Integrations::register()`.
