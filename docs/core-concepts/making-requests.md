# Making requests

There are two request methods (`requestAs()` for typed responses, `request()` for untyped) and a fluent builder. All three wrap your API call with logging, caching, rate limiting, retries, and health tracking.

## Typed requests with `requestAs()`

`requestAs()` requires a [Spatie Laravel Data](https://spatie.be/docs/laravel-data/v4/introduction) class-string. The response is reconstructed via `Data::from()`, giving you a typed object on every call (both live and cached):

```php
$tickets = $integration->requestAs(
    endpoint: '/api/v2/tickets.json',
    method: 'GET',
    responseClass: TicketListResponse::class, // Spatie Data class
    callback: fn () => Http::get($url),
    relatedTo: $ticket,                // optional - links this request to a model
    requestData: ['status' => 'open'], // optional - logged (auto-captured for HTTP responses)
    cacheFor: now()->addHour(),        // optional - cache the response
    serveStale: true,                  // optional - return expired cache on error
    maxAttempts: 3,                     // optional - total attempts including first
);
```

The `endpoint` and `method` are logical identifiers. They can be real HTTP paths or SDK operation names:

```php
// SDK-style: endpoint is a logical name
$customer = $integration->requestAs(
    endpoint: 'customers.create',
    method: 'POST',
    responseClass: CustomerResponse::class,
    callback: fn () => $stripe->customers->create(['email' => $email]),
    requestData: ['email' => $email],
);
```

## Untyped requests with `request()`

Use `request()` for non-JSON responses (PDFs, HTML, binary data) or when you don't need a typed response. It returns `mixed`:

```php
$pdf = $integration->request(
    endpoint: '/api/invoice.pdf',
    method: 'GET',
    callback: fn () => Http::get($url),
);
```

If you cache an untyped response and the response is an object, a warning is logged suggesting you use `requestAs()` instead.

## Fluent request builder

A chainable API is available via `toAs()` (typed) and `to()` (untyped).

### Typed -- `toAs()`

```php
// With a callback
$tickets = $integration->toAs('/api/v2/tickets.json', TicketListResponse::class)
    ->withCache(3600, serveStale: true)
    ->withAttempts(3)
    ->relatedTo($ticket)
    ->get(fn () => Http::get($url));

// With a URL (uses Laravel's HTTP client automatically)
$tickets = $integration->toAs('/api/v2/tickets.json', TicketListResponse::class)
    ->withData(['status' => 'open'])
    ->get("https://api.example.com/tickets");
```

### Untyped -- `to()`

```php
$pdf = $integration->to('/api/invoice.pdf')
    ->get(fn () => Http::get($url));
```

### Available methods

| Method | Description |
|--------|-------------|
| `withCache(int\|CarbonInterface $ttl, bool $serveStale)` | Cache successful responses |
| `withAttempts(int $max)` | Set max retry attempts |
| `relatedTo(Model $model)` | Link request to a model |
| `withData(string\|array $data)` | Attach request data for logging |
| `retryOf(int $id)` | Mark as retry of a previous request |

### Terminal methods

`get()`, `post()`, `put()`, `patch()`, `delete()`, `execute(string $method, Closure $callback)`.

## What happens inside a request

1. Checks a cache-based sliding window rate counter against the provider's configured limit. Throws `RateLimitExceededException` if exceeded.
2. If `cacheFor` is set, looks for a matching unexpired response (same integration + endpoint + method + request data hash).
3. Runs your closure, measuring duration with `hrtime()`.
4. Normalizes the response: handles Laravel HTTP responses, Guzzle PSR-7 responses, `JsonResponse`, arrays, objects, and strings. Extracts status code and body automatically.
5. If the request fails and stale cache exists, returns the stale response instead of throwing.
6. Saves an `IntegrationRequest` record with full request/response data, timing, and error details.
7. Calls `recordSuccess()` or `recordFailure()` on the integration, updating `consecutive_failures` and `health_status`.
8. Dispatches `RequestCompleted` or `RequestFailed`.
