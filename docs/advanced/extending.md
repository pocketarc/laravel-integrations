# Extending

## Programmatic registration

Register providers at runtime via the facade:

```php
use Integrations\Facades\Integrations;

Integrations::register('zendesk', ZendeskProvider::class);
```

This is useful for package providers or conditional registration based on environment.

## Listening to events

Build custom workflows by listening to the package's [events](/reference/events):

```php
use Integrations\Events\IntegrationHealthChanged;
use Integrations\Events\RequestFailed;
use Integrations\Events\IntegrationSynced;

// Alert on health degradation
Event::listen(IntegrationHealthChanged::class, function ($event) {
    if ($event->newStatus->value === 'failing') {
        // Page the on-call engineer
    }
});

// Track failed requests in your metrics system
Event::listen(RequestFailed::class, function ($event) {
    Metrics::increment('integration.request.failed', [
        'provider' => $event->integration->provider,
    ]);
});
```

## Custom query scopes

The `IntegrationLog` model provides scopes for common queries:

```php
$integration->logs()->successful()->forOperation('sync')->get();
$integration->logs()->failed()->recent(24)->get();
$integration->logs()->topLevel()->get();
```

## Using IntegrationContext

Add integration context to your own log statements:

```php
use Integrations\Support\IntegrationContext;

IntegrationContext::push($integration, 'custom-operation');
// All Log:: calls now include integration context
IntegrationContext::clear();
```

## Building a dashboard

Useful queries for a monitoring dashboard:

```php
// Active integrations with health status
Integration::where('is_active', true)
    ->select('id', 'name', 'provider', 'health_status', 'last_synced_at', 'consecutive_failures')
    ->get();

// Recent failures by provider
IntegrationRequest::where('status_code', '>=', 400)
    ->where('created_at', '>=', now()->subDay())
    ->join('integrations', 'integrations.id', '=', 'integration_requests.integration_id')
    ->groupBy('integrations.provider')
    ->selectRaw('integrations.provider, count(*) as failure_count')
    ->get();

// Sync history
IntegrationLog::forOperation('sync')
    ->where('integration_id', $id)
    ->recent(168) // last 7 days
    ->get();
```
