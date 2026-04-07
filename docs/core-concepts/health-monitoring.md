# Health monitoring

Integration health is tracked automatically based on request outcomes, using a circuit breaker pattern.

## How it works

Each successful request resets `consecutive_failures` to 0 and sets `health_status` to `healthy`. Each failure increments `consecutive_failures` and updates `last_error_at`.

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
            $integration->requestAs(
                endpoint: '/user',
                method: 'GET',
                responseClass: UserResponse::class,
                callback: fn () => Http::withHeaders([
                    'Authorization' => 'Bearer '.$integration->credentialsArray()['token'],
                ])->get('https://api.github.com/user'),
            );
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
