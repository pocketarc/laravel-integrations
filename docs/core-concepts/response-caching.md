# Response caching

Pass `cacheFor` to cache successful responses. Subsequent identical requests (matched by endpoint + method + request data hash) return the cached response without executing the callback.

## Basic usage

```php
$tickets = $integration->requestAs(
    endpoint: '/api/v2/tickets.json',
    method: 'GET',
    responseClass: TicketListResponse::class,
    callback: fn () => Http::get($url),
    cacheFor: now()->addHour(),
    serveStale: true, // fall back to expired cache if the live request fails
);
```

Or with the fluent builder:

```php
$tickets = $integration->toAs('/api/v2/tickets.json', TicketListResponse::class)
    ->withCache(3600, serveStale: true)
    ->get(fn () => Http::get($url));
```

## How it works

Cache keys are composed from the integration ID, endpoint, HTTP method, and a hash of the request data. This means the same endpoint with different parameters produces separate cache entries.

With `requestAs()`, both live and cached paths reconstruct the response via `Data::from()`, so you always receive the same typed Data object regardless of whether it was a cache hit or a live call.

## Stale cache fallback

When `serveStale: true` is set and the live request fails, the package returns the expired cached response instead of throwing. This is useful for non-critical data that's better stale than missing.

## Tracking

Cache hits and stale hits are tracked per-response via `cache_hits` and `stale_hits` counters on the `IntegrationRequest` model.
