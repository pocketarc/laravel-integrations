# Rate limiting

Each integration has a cache-based sliding window rate counter. Providers that implement `HasScheduledSync` declare their rate limit via `defaultRateLimit()`.

## How it works

Before every request, the package checks the current request count against the provider's configured limit. If the limit is reached:

- When `max_wait_seconds > 0` (default: 10), the package sleeps in 1-second intervals and re-checks until capacity is available or the wait time is exceeded.
- When `max_wait_seconds = 0`, throws `RateLimitExceededException` immediately.

Every attempt (including retries) counts toward the rate limit.

## Provider configuration

```php
class GitHubProvider implements IntegrationProvider, HasScheduledSync
{
    public function defaultRateLimit(): ?int
    {
        return 83; // ~5000 requests/hour, null = unlimited
    }

    // ...
}
```

## Global configuration

```php
// config/integrations.php
'rate_limiting' => [
    'max_wait_seconds' => 10, // wait for capacity before throwing (0 = immediate)
],
```

## Adaptive rate limits

Adapters can feed the limiter signals from the upstream's response headers. When an adapter reports `Retry-After` or `X-RateLimit-Remaining: 0` (with a reset window), the limiter suppresses subsequent requests until the window clears, even if the local bucket still has capacity.

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

When the next `enforce()` runs and the suppression window is still open, the limiter waits (or throws `RateLimitExceededException`, depending on `max_wait_seconds`) just as it does for the local bucket. Once the window passes, the suppression key is dropped and traffic resumes.

If no adapter reports anything, the limiter falls back to the local bucket alone.

## Interaction with retries

Rate limiting and retries work together. Each retry attempt checks the rate limiter before executing, so a burst of retries won't exceed the provider's API limits.
