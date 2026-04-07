# Making requests

There are two request methods (`requestAs()` for typed responses, `request()` for untyped) and a fluent builder. All three wrap your API call with logging, caching, rate limiting, retries, and health tracking.

## Typed requests with `requestAs()`

`requestAs()` requires a [Spatie Laravel Data](https://spatie.be/docs/laravel-data/v4/introduction) class-string. The response is reconstructed via `Data::from()`, giving you a typed object on every call (both live and cached):

```php
$issues = $integration->requestAs(
    endpoint: '/repos/{owner}/{repo}/issues',
    method: 'GET',
    responseClass: IssueListResponse::class,  // Spatie Data class
    callback: fn () => Http::get($url),
    relatedTo: $issue,                // optional - links this request to a model
    requestData: ['state' => 'open'], // optional - logged (auto-captured for HTTP responses)
    cacheFor: now()->addHour(),        // optional - cache the response
    serveStale: true,                  // optional - return expired cache on error
    maxAttempts: 3,                     // optional - total attempts including first
);
```

The `endpoint` and `method` are logical identifiers. They can be real HTTP paths or SDK operation names:

```php
// SDK-style: endpoint is a logical name
$issue = $integration->requestAs(
    endpoint: 'issues.create',
    method: 'POST',
    responseClass: IssueResponse::class,
    callback: fn () => $github->issues()->create($owner, $repo, ['title' => $title]),
    requestData: ['title' => $title],
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
$issues = $integration->toAs('/repos/{owner}/{repo}/issues', IssueListResponse::class)
    ->withCache(3600, serveStale: true)
    ->withAttempts(3)
    ->relatedTo($issue)
    ->get(fn () => Http::get($url));

// With a URL (uses Laravel's HTTP client automatically)
$issues = $integration->toAs('/repos/{owner}/{repo}/issues', IssueListResponse::class)
    ->withData(['state' => 'open'])
    ->get("https://api.github.com/repos/acme/widgets/issues");
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

<!--
Mermaid source (for editing in Excalidraw):
flowchart TD
    A[Incoming request] -→ B{Rate limit check}
    B -→|Exceeded| C[RateLimitExceededException]
    B -→|OK| D{Cache lookup}
    D -→|Hit| E[Return cached response]
    D -→|Miss| F[Execute callback]
    F -→ G[Normalize response]
    G -→|Success| H[Save IntegrationRequest]
    G -→|Failure| I{Stale cache?}
    I -→|Yes| J[Serve stale response]
    I -→|No| K[Propagate error]
    E -→ H
    J -→ H
    K -→ H
    H -→ L[Update health status]
    L -→ M[Dispatch event]
-->
<InlineSvg src="/request-pipeline.svg" />
