# Response caching

Pass `cacheFor` to cache successful responses. Subsequent identical requests (matched by endpoint + method + request data hash) return the cached response without executing the callback.

## Basic usage

```php
$tickets = $integration
    ->at('/api/v2/tickets.json')
    ->as(TicketListResponse::class)
    ->withCache(3600, serveStale: true)
    ->get(fn () => Http::get($url));
```

The same options are available on the lower-level `request()` if you'd rather skip the builder:

```php
$tickets = $integration->request(
    endpoint: '/api/v2/tickets.json',
    method: 'GET',
    callback: fn () => Http::get($url),
    responseClass: TicketListResponse::class,
    cacheFor: now()->addHour(),
    serveStale: true, // fall back to expired cache if the live request fails
);
```

## How it works

Cache keys are composed from the integration ID, endpoint, HTTP method, and a hash of the request data. The same endpoint with different parameters produces separate cache entries.

When `->as(...)` is set, both live and cached paths reconstruct the response via `Data::from()`, so you receive the same typed Data object whether it came from cache or from a live call.

## Stale cache fallback

When `serveStale: true` is set and the live request fails, the package returns the expired cached response instead of throwing. This is useful for non-critical data that's better stale than missing.

## Tracking

Cache hits and stale hits are tracked per-response via `cache_hits` and `stale_hits` counters on the `IntegrationRequest` model.
