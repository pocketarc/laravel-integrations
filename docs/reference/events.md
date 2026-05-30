# Events

All events live in the `Integrations\Events` namespace and use Laravel's `Dispatchable` trait.

## Integration lifecycle

### IntegrationCreated

Dispatched when a new integration is created.

| Property | Type | Description |
|----------|------|-------------|
| `integration` | `Integration` | The newly created integration |

### IntegrationHealthChanged

Dispatched when an integration's health status changes.

| Property | Type | Description |
|----------|------|-------------|
| `integration` | `Integration` | The integration |
| `previousStatus` | `HealthStatus` | Status before the change |
| `newStatus` | `HealthStatus` | Status after the change |

### IntegrationDisabled

Dispatched when an integration is automatically disabled after too many failures.

| Property | Type | Description |
|----------|------|-------------|
| `integration` | `Integration` | The disabled integration |

## Requests

### RequestCompleted

Dispatched after a successful API request.

| Property | Type | Description |
|----------|------|-------------|
| `integration` | `Integration` | The integration |
| `request` | `IntegrationRequest` | The logged request record |

### RequestFailed

Dispatched after a failed API request.

| Property | Type | Description |
|----------|------|-------------|
| `integration` | `Integration` | The integration |
| `request` | `IntegrationRequest` | The logged request record |

## Circuit breaker

### CircuitOpened

Dispatched when an integration's [circuit breaker](/advanced/circuit-breaker) transitions to open — whether tripped automatically or forced open by an operator. Fires once per transition, not per request.

| Property | Type | Description |
|----------|------|-------------|
| `integration` | `Integration` | The integration |
| `reason` | `string` | `threshold_reached`, `half_open_probe_failed`, or `forced_open` |

### CircuitClosed

Dispatched when the breaker transitions to closed — whether a half-open probe succeeded or an operator forced it closed.

| Property | Type | Description |
|----------|------|-------------|
| `integration` | `Integration` | The integration |
| `reason` | `string` | `half_open_probe_succeeded` or `forced_closed` |

A publishable `SendCircuitNotification` listener can turn these into notifications — see [Notifications](/advanced/notifications#circuit-breaker-notifications).

## Operations

### OperationStarted

Dispatched when an operation is logged with status `processing`.

| Property | Type | Description |
|----------|------|-------------|
| `integration` | `Integration` | The integration |
| `log` | `IntegrationLog` | The operation log record |

### OperationCompleted

Dispatched when an operation completes successfully.

| Property | Type | Description |
|----------|------|-------------|
| `integration` | `Integration` | The integration |
| `log` | `IntegrationLog` | The operation log record |

### OperationFailed

Dispatched when an operation fails.

| Property | Type | Description |
|----------|------|-------------|
| `integration` | `Integration` | The integration |
| `log` | `IntegrationLog` | The operation log record |

## Sync

### IntegrationSynced

Dispatched after sync completes (legacy aggregate event).

| Property | Type | Description |
|----------|------|-------------|
| `integration` | `Integration` | The integration |
| `result` | `SyncResult` | Sync outcome (created/updated/failed counts) |

### SyncCompleted

Dispatched once a sync run reconciles.

| Property | Type | Description |
|----------|------|-------------|
| `integration` | `Integration` | The integration |
| `result` | `SyncResult` | Aggregated run outcome |

### SyncItemFailed

Dispatched when a sync item exhausts its retries.

| Property | Type | Description |
|----------|------|-------------|
| `integration` | `Integration` | The integration |
| `item` | `IntegrationSyncItem` | The failed item |
| `exception` | `Throwable` | The failure cause |

## Webhooks

### WebhookReceived

Dispatched when a webhook is received and verified.

| Property | Type | Description |
|----------|------|-------------|
| `integration` | `Integration` | The integration |
| `event` | `string` | The webhook event type |

## Listening to events

Register listeners in your `EventServiceProvider`:

```php
use Integrations\Events\IntegrationSynced;
use App\Listeners\HandleIntegrationSync;

protected $listen = [
    IntegrationSynced::class => [
        HandleIntegrationSync::class,
    ],
];
```
