# Custom retry logic

For SDKs that throw custom exceptions not wrapping Guzzle (e.g. GitHub's `ApiLimitExceedException`, Twilio's rate limit exceptions), the `CustomizesRetry` interface lets the provider define retry semantics for its own exceptions.

## The interface

```php
use Integrations\Contracts\CustomizesRetry;

interface CustomizesRetry
{
    public function isRetryable(\Throwable $e): ?bool;
    public function retryDelayMs(\Throwable $e, int $attempt, ?int $statusCode): ?int;
}
```

Both methods return `null` to fall back to the default retry logic. This means providers only need to handle exceptions they know about.

## Example: GitHub

```php
use Github\Exception\ApiLimitExceedException;
use Github\Exception\RuntimeException as GithubRuntimeException;

class GithubProvider implements IntegrationProvider, CustomizesRetry
{
    public function isRetryable(\Throwable $e): ?bool
    {
        if ($e instanceof ApiLimitExceedException) {
            return true;
        }

        if ($e instanceof GithubRuntimeException) {
            return false; // GitHub SDK errors are not transient
        }

        return null; // fall back to core logic
    }

    public function retryDelayMs(\Throwable $e, int $attempt, ?int $statusCode): ?int
    {
        if ($e instanceof ApiLimitExceedException) {
            $resetTime = $e->getResetTime(); // Unix timestamp from GitHub
            $delaySeconds = max(0, $resetTime - time());

            return $delaySeconds * 1000;
        }

        return null; // fall back to core logic
    }
}
```

## How it composes with default logic

1. The retry handler first consults `isRetryable()` on the provider (if it implements `CustomizesRetry`).
2. If the provider returns `true` or `false`, that decision is final.
3. If the provider returns `null`, the default logic kicks in (exception chain walking, status code checks).
4. For delays, `retryDelayMs()` is consulted first, with the same null-fallback pattern.

This means providers only need to handle their SDK-specific exceptions -- everything else is handled automatically by the core retry handler.
