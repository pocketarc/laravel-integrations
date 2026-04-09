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
