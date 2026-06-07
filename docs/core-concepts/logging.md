# Logging

There are two logging layers: request-level logging (automatic) and operation-level logging (explicit).

## Request logging

Every API call made through `request()` or `requestAs()` is automatically logged as an `IntegrationRequest` record with:

- Endpoint, HTTP method, status code
- Full request and response data
- Duration (measured via `hrtime()`)
- Error details on failure
- Cache hit / stale hit counters
- `retry_of` pointer for retries
- Optional `relatedTo` model link

No configuration needed -- this happens automatically for every request through the integration.

## Operation logging

Log business-level operations (syncs, imports, webhooks) separately from individual API requests:

```php
$log = $integration->logOperation(
    operation: 'sync',
    direction: 'inbound',
    status: 'success',
    summary: 'Synced 42 tickets from Zendesk',
    metadata: ['ticket_count' => 42, 'new' => 12, 'updated' => 30],
    durationMs: 3200,
);
```

### Structured result data

Use `resultData` to store the structured output of an operation, separate from operational `metadata`:

```php
$log = $integration->logOperation(
    operation: 'issue.create',
    direction: 'outbound',
    status: 'success',
    summary: 'Created issue in GitHub',
    metadata: ['repo' => 'acme/api', 'labels' => ['bug']],
    resultData: ['issue_number' => 42, 'url' => 'https://github.com/acme/api/issues/42'],
    durationMs: 850,
);

$log->result_data['issue_number']; // 42
```

`metadata` is for operational context (configuration, counts, request IDs). `resultData` is for what the operation produced (references, created IDs, output values).

### Status vocabulary

`status` is a free string, so any value is accepted. Three drive events:

| Status | Event |
|--------|-------|
| `success` | `OperationCompleted` |
| `failed` | `OperationFailed` |
| `processing` | `OperationStarted` |

`partial` marks a sync run where some items failed, and `deferred` an expected wait; both are recorded without an event, as is any other custom status. The documented values are available as `IntegrationLog::STATUS_SUCCESS`, `STATUS_FAILED`, `STATUS_PROCESSING`, `STATUS_PARTIAL`, and `STATUS_DEFERRED`.

### Hierarchical logging

Use `parentId` for per-record granularity under a parent operation:

```php
$parentLog = $integration->logOperation(
    operation: 'sync',
    direction: 'inbound',
    status: 'success',
    summary: 'Full ticket sync',
);

foreach ($tickets as $ticket) {
    $integration->logOperation(
        operation: 'sync',
        direction: 'inbound',
        status: 'success',
        externalId: $ticket['id'],
        summary: "Imported ticket {$ticket['id']}",
        parentId: $parentLog->id,
    );
}
```

### Querying logs

```php
$integration->logs()->successful()->get();
$integration->logs()->failed()->forOperation('sync')->get();
$integration->logs()->topLevel()->recent(48)->get(); // top-level logs from last 48 hours
```

## Terminal vs transient failures {#terminal-vs-transient-failures}

A listener running inside a [sync item](/features/scheduled-syncs) (`event($this->event)` in `ProcessSyncItem`) often catches a sub-step error, logs `failed`, and re-throws so the queue retries the whole job. Because `logOperation(status: 'failed')` dispatches `OperationFailed` every time, the *same* condition re-fires the event on every attempt — even when the item succeeds on a later one. The retry state that would let you tell "failed this attempt" from "failed terminally" lives in the job, not the listener.

The package threads it through as ambient context, the same way `RequestContext` reaches a request closure. Inside a sync item, read it with `Integration::currentSyncAttempt()`:

```php
class IngestGitHubIssue
{
    public function handle(GitHubIssueSynced $event): void
    {
        try {
            // ... sub-step that can fail ...
        } catch (\Throwable $e) {
            $attempt = $event->integration->currentSyncAttempt();
            $event->integration->logOperation(
                operation: 'ingest',
                direction: 'inbound',
                status: 'failed',
                error: $e->getMessage(),
            );

            throw $e; // let the queue retry
        }
    }
}
```

When a failure is logged inside a sync item, `logOperation()` records the attempt on the new `integration_logs.attempt` / `max_attempts` columns and attaches a `SyncAttemptContext` to the [`OperationFailed`](/reference/events#operationfailed) event. A listener can read `$event->attempt?->isLikelyFinalAttempt()` to down-rank mid-retry noise.

::: warning isLikelyFinalAttempt() is a heuristic
"Final attempt" can't be known for certain at log time: the wall-clock [retry window](/features/scheduled-syncs#configuration) can cut retries short, and even the last attempt may still succeed. For terminal alerting that fires exactly once per dead item, route it to [`SyncItemFailed`](/reference/events#syncitemfailed) — dispatched from `ProcessSyncItem::failed()` on genuine exhaustion — and use the attempt context only as a best-effort filter for operation-granularity `OperationFailed` hooks.
:::

## Structured Laravel log context

During sync and webhook processing, the package automatically adds integration context to Laravel's shared log context:

```php
// Automatically added by SyncIntegration and ProcessWebhook jobs:
Log::shareContext([
    'integration_id' => 42,
    'integration_provider' => 'zendesk',
    'integration_name' => 'Production Zendesk',
    'integration_operation' => 'sync',
]);
```

Use `IntegrationContext` directly in your own code:

```php
use Integrations\Support\IntegrationContext;

IntegrationContext::push($integration, 'custom-operation');
// ... your code, all Log:: calls include the context ...
IntegrationContext::clear();
```

## Sync timeline

During a sync, all API requests are tracked and their IDs stored in the parent sync log's metadata:

```php
$syncLog = $integration->logs()->forOperation('sync')->latest()->first();
$requestIds = $syncLog->metadata['request_ids'] ?? [];
$requests = IntegrationRequest::whereIn('id', $requestIds)->get();
```
