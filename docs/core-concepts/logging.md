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
