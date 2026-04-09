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

## How it composes with other retry logic

The full retry decision chain, in priority order:

1. `RetryableException`: if the thrown exception (or anything in its chain) is a [`RetryableException`](/core-concepts/retries#retryableexception), it is always retried. `CustomizesRetry` is not consulted.
2. `CustomizesRetry::isRetryable()`: if the provider returns `true` or `false`, that decision is final.
3. Default logic: exception chain walking, status code checks.

For delays, the same priority applies:

1. `RetryableException::retryAfterSeconds` (if set)
2. `CustomizesRetry::retryDelayMs()` (if non-null)
3. Default delay logic (Retry-After header, status-code-based backoff)

::: tip When to use which
Use `RetryableException` when you control the throwing code (your own adapters, wrappers around third-party calls). Use `CustomizesRetry` when you need to inspect exceptions thrown by code you don't control (third-party SDKs).
:::
