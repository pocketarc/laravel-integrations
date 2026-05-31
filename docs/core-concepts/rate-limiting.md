# Rate limiting

Each integration has a cache-based request counter. A provider declares its limit through [`DeclaresRateLimit::defaultRateLimit()`](/reference/contracts#declaresratelimit), returning a `RateLimit` value object, or `null` for no limit. The budget is a transport property of the upstream, separate from scheduled sync, so a request-only provider can declare one on its own. `HasScheduledSync` extends `DeclaresRateLimit`, so a sync provider satisfies it without a second interface.

## Declaring a limit

A `RateLimit` carries three things: how many requests, the window in seconds, and whether the upstream enforces that window as fixed or sliding. Build one with the named constructors:

```php
use Integrations\RateLimit;

RateLimit::perMinute(700);    // 700 requests per 60s
RateLimit::perHour(5000);     // 5000 requests per 3600s
RateLimit::perDay(100_000);   // 100000 requests per 86400s
RateLimit::per(30, 10);       // 30 requests per 10s
```

The window is part of the limit. An hourly budget and a per-minute budget are not interchangeable: `5000` per hour is not `83` per minute, because the hourly budget can be spent in a burst.

## Fixed vs sliding windows

The strategy should match how the upstream API enforces its limit.

With a fixed window (the default), the budget resets on a hard boundary. GitHub gives an authenticated token 5,000 requests per hour and resets the counter at the time in `X-RateLimit-Reset`. Spending the whole budget early in the window is allowed, so the limiter permits bursts up to the full limit.

```php
public function defaultRateLimit(): ?RateLimit
{
    return RateLimit::perHour(5000);
}
```

With a sliding window, the upstream caps requests in any rolling window of `windowSeconds`, with no fixed reset. A fixed local window would let a burst straddle the boundary and trip the upstream; a sliding window smooths traffic across the window instead. Opt in with `->sliding()`:

```php
public function defaultRateLimit(): ?RateLimit
{
    return RateLimit::perMinute(700)->sliding();
}
```

## How it works

Before every request, the limiter checks the integration's request count for the current window against the configured limit. If there is capacity, the request proceeds and is counted. If not:

- When `max_wait_seconds > 0` (default: 10) and capacity will return within that many seconds, the limiter sleeps until the window rolls over and re-checks.
- Otherwise it throws `RateLimitExceededException`, which carries `retryAfterSeconds` (when capacity is next expected).

Every attempt (including retries) counts toward the limit. The counter is keyed per integration, so all queue workers for one integration share the same budget.

## Rate limits and syncs

Inside a scheduled sync, hitting the rate limit is not a failure. The `ProcessSyncItem` job catches `RateLimitExceededException` and releases itself back onto the queue with the exception's `retryAfterSeconds` delay, so the item is retried once the upstream's window reopens and the run stays in flight. A throttled sync slows down instead of wedging.

`sync.item_tries` counts only genuine listener exceptions, so a rate-limit deferral never costs an item its failure budget. The absolute bound on deferrals is `sync.item_retry_window` (default 6 hours).

## Global configuration

```php
// config/integrations.php
'rate_limiting' => [
    'max_wait_seconds' => 10, // max sleep waiting for capacity (0 = throw immediately)
],
```

## Adaptive rate limits

Adapters can feed the limiter signals from the upstream's response headers. When an adapter reports `Retry-After` or `X-RateLimit-Remaining: 0` (with a reset window), the limiter suppresses subsequent requests until that window clears, even if the local counter still has capacity.

The adapter does the reporting from inside its closure:

```php
$response = $this->integration
    ->at('payment_intents')
    ->post(function (RequestContext $ctx) {
        $intent = $sdk->paymentIntents->create($params);

        // Pull limit info from wherever the SDK exposes it. GitHub uses
        // X-RateLimit-Remaining + X-RateLimit-Reset; Stripe surfaces
        // Retry-After on 429s; others vary.
        $ctx->reportResponseMetadata(
            rateLimitRemaining: $headers['X-RateLimit-Remaining'] ?? null,
            rateLimitResetAt: $resetAt,
        );

        return $intent;
    });
```

When the next `enforce()` runs and the suppression window is still open, the limiter waits (or throws `RateLimitExceededException`, depending on `max_wait_seconds`) just as it does for the local counter. Once the window passes, the suppression key is dropped and traffic resumes.

If no adapter reports anything, the limiter falls back to the local counter alone.

## Interaction with retries

Rate limiting and retries work together. Each retry attempt checks the rate limiter before executing, so a burst of retries won't exceed the provider's API limits.
