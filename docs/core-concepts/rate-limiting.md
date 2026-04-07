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

## Interaction with retries

Rate limiting and retries work together. Each retry attempt checks the rate limiter before executing, so a burst of retries won't exceed the provider's API limits.
