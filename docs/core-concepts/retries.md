# Retries

GET requests default to 3 attempts (1 original + up to 2 retries) on transient errors. Non-GET requests default to 1 attempt (no retries). Override per-request with `maxAttempts`.

## Basic usage

```php
$tickets = $integration->requestAs(
    endpoint: '/api/v2/tickets.json',
    method: 'GET',
    responseClass: TicketListResponse::class,
    callback: fn () => Http::get($url),
    maxAttempts: 3,
);
```

Each retry is persisted as its own `IntegrationRequest` row with `retry_of` pointing to the first attempt. Every attempt counts toward rate limiting and is visible in logs.

## Backoff strategy

| Status           | Backoff                                                              |
|------------------|----------------------------------------------------------------------|
| `Retry-After`    | Respects the header value, capped at configured max (default 10 min) |
| 429              | Fixed 30-second delay (when no `Retry-After` header)                 |
| 5xx              | Exponential (attempt x 2s)                                           |
| Connection error | Linear (attempt x 1s)                                                |
| 4xx (except 429) | Not retried, thrown immediately                                      |

## RetryableException

When your code knows an error is retryable, throw `RetryableException`:

```php
use Integrations\Exceptions\RetryableException;

throw new RetryableException(
    'GitHub API secondary rate limit',
    retryAfterSeconds: 60,
);
```

The exception accepts two optional hints:

| Parameter           | Description                                                                  |
|---------------------|------------------------------------------------------------------------------|
| `retryAfterSeconds` | Delay before the next attempt (capped by `retry_after_max_seconds` config)   |
| `maxAttempts`       | Maximum attempts for this specific error (caps the executor's `maxAttempts`) |

`RetryableException` has the highest priority in the retry decision chain: it overrides both `CustomizesRetry` and the default status-code logic. You can also wrap third-party exceptions:

```php
catch (ZendeskRateLimitException $e) {
    throw new RetryableException(
        'Zendesk rate limited',
        retryAfterSeconds: $e->getRetryAfter(),
        previous: $e,
    );
}
```

Domain-specific subclasses work too: extend `RetryableException`.

## SDK exception support

The retry handler walks the exception chain (`getPrevious()`) to detect retryable status codes and connection errors wrapped by third-party SDKs. If your SDK wraps a Guzzle, Laravel HTTP, or Symfony HTTP exception as a previous exception, retries work automatically with no adapter code needed.

For SDKs that throw completely custom exceptions (not wrapping Guzzle), you have two options:

- Throw a [`RetryableException`](#retryableexception) from the call site when you know an error is transient. Best for adapters and code you control.
- Implement [`CustomizesRetry`](/advanced/custom-retry) on the provider to inspect exceptions after the fact. Best for third-party SDK exceptions you can't modify.

## Standalone retry handler

The `RetryHandler` can be used independently of `Integration::request()`:

```php
use Integrations\RetryHandler;

$result = RetryHandler::execute(
    callback: fn () => Http::get($url)->throw(),
    maxAttempts: 3,
    retryableStatusCodes: [429, 500, 502, 503, 504],
    onRetry: function (int $attempt, Throwable $e) {
        Log::warning("Retry attempt {$attempt}", ['error' => $e->getMessage()]);
    },
);
```

## Configuration

The `Retry-After` header cap is configurable in `config/integrations.php`:

```php
'retry' => [
    'retry_after_max_seconds' => 600, // cap at 10 minutes
],
```
