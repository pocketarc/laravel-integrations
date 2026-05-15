# Events

All events use Laravel's `Dispatchable` and `SerializesModels` traits.

## Integration lifecycle

| Event | Payload | When |
|-------|---------|------|
| `IntegrationCreated` | `$integration` | An integration is created |
| `IntegrationSynced` | `$integration` | `markSynced()` is called |
| `IntegrationHealthChanged` | `$integration`, `$previousStatus`, `$newStatus` | Health status transitions |
| `IntegrationDisabled` | `$integration` | Integration auto-disabled after threshold |

## Requests

| Event | Payload | When |
|-------|---------|------|
| `RequestCompleted` | `$integration`, `$request` | An API request succeeds |
| `RequestFailed` | `$integration`, `$request` | An API request fails |

## Operations

| Event                | Payload                | When                                            |
|----------------------|------------------------|-------------------------------------------------|
| `OperationStarted`   | `$integration`, `$log` | An operation is logged with status `processing` |
| `OperationCompleted` | `$integration`, `$log` | An operation is logged with status `success`    |
| `OperationFailed`    | `$integration`, `$log` | An operation is logged with status `failed`     |

## Sync

| Event | Payload | When |
|-------|---------|------|
| `SyncCompleted` | `$integration`, `$result` | A sync run finishes reconciling, with every per-item job in a terminal state. `$result->hasFailures()` distinguishes a clean run from a partial one. |
| `SyncItemFailed` | `$integration`, `$item`, `$exception` | A per-item `ProcessSyncItem` job exhausts its retries. The `IntegrationSyncItem` row is already marked `failed`. |

These are the canonical sync events; adapters no longer ship their own per-completion or per-failure events. The per-item "synced" event (e.g. `ZendeskTicketSynced`) is still the adapter's own; it extends `SyncItemEvent` and its listeners run synchronously inside `ProcessSyncItem`. See [Scheduled syncs](/features/scheduled-syncs).

## OAuth

| Event | Payload | When |
|-------|---------|------|
| `OAuthCompleted` | `$integration` | OAuth2 authorization completes |
| `OAuthRevoked` | `$integration` | OAuth2 authorization is revoked |

## Webhooks

| Event | Payload | When |
|-------|---------|------|
| `WebhookReceived` | `$integration`, `$provider` | A webhook arrives |

## Listening for events

Listen with attribute-based listeners or in your `EventServiceProvider`. See [Health Monitoring](/core-concepts/health-monitoring) for a listener example.
