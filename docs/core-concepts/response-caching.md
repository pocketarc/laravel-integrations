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

Cache keys are composed from the integration ID, endpoint, HTTP method, and a hash of the request data. The same endpoint with different parameters produces separate cache entries. The package stores only the first 65530 bytes of the request data in the `request_data` column. It calculates the hash from all of the request data.

When `->as(...)` is set, both live and cached paths reconstruct the response via `Data::from()`, so you receive the same typed Data object whether it came from cache or from a live call.

## Requests that are never cached

A request whose body contains a field from the provider's [`sensitiveRequestFields()`](/features/redaction) is not cached, and it is not served from the cache. `cacheFor` and `serveStale` have no effect on it. A request whose body contains none of these fields is cached as usual.

Before the package calculates the cache key's hash, it replaces each secret in the request data with `[REDACTED]`. If the package cached requests with redacted fields, two requests that differ only in a secret would have the same cache key. The second caller would then get the response to the first request. If the key were a hash of the unredacted body, the database would contain a fast, non-cryptographic hash of the secret.

The package also does not cache a response that it cannot store unchanged:

- A response with a binary body. The package stores a `[BINARY ...]` marker in place of the body.
- A response that `json_encode` cannot encode. The package stores an `[UNENCODABLE ...]` marker in place of the body.
- A response whose body contains a field from `sensitiveResponseFields()`. The package stores `[REDACTED]` in place of each of these fields.

A caller served from the cache would get the stored copy of the response.

## Stale cache fallback

When `serveStale: true` is set and the live request fails, the package returns the expired cached response instead of throwing. This is useful for non-critical data that's better stale than missing.

## Tracking

Cache hits and stale hits are tracked per-response via `cache_hits` and `stale_hits` counters on the `IntegrationRequest` model.
