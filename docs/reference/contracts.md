# Contracts reference

All contracts live in the `Integrations\Contracts` namespace.

## IntegrationProvider (required)

Every provider must implement this interface.

```php
interface IntegrationProvider
{
    public function name(): string;
    public function credentialRules(): array;
    public function metadataRules(): array;
    public function credentialDataClass(): ?string;
    public function metadataDataClass(): ?string;
}
```

| Method | Returns | Description |
|--------|---------|-------------|
| `name()` | `string` | Human-readable provider name |
| `credentialRules()` | `array` | Laravel validation rules for credentials |
| `metadataRules()` | `array` | Laravel validation rules for metadata |
| `credentialDataClass()` | `?string` | Spatie Data class-string or null for plain array |
| `metadataDataClass()` | `?string` | Spatie Data class-string or null for plain array |

## DeclaresRateLimit

Declares an in-code API rate budget. Implement it on any provider, with or without scheduled sync.

```php
interface DeclaresRateLimit
{
    public function defaultRateLimit(): ?RateLimit;
}
```

| Method | Returns | Description |
|--------|---------|-------------|
| `defaultRateLimit()` | `?RateLimit` | API rate budget, e.g. `RateLimit::perHour(5000)` (fixed window) or `RateLimit::perMinute(700)->sliding()`. null = unlimited |

The rate budget is a transport property of the upstream, independent of scheduled sync. A request-only provider implements `DeclaresRateLimit` alone to ship a default limit; `HasScheduledSync` extends it, so every sync provider satisfies it too. The framework reads the value through `Integration::effectiveRateLimit()`, where a [runtime override](/advanced/circuit-breaker#runtime-overrides) takes precedence. See [Rate limiting](/core-concepts/rate-limiting).

## HasScheduledSync

Adds automated sync scheduling.

```php
interface HasScheduledSync extends DeclaresRateLimit
{
    public function sync(Integration $integration, SyncSession $session): void;
    public function defaultSyncInterval(): int;
    public function reduceCheckpoints(array $checkpoints): mixed;
}
```

| Method | Returns | Description |
|--------|---------|-------------|
| `sync()` | `void` | Enumerate items and hand each to `$session->dispatch()` |
| `defaultSyncInterval()` | `int` | Default interval in minutes |
| `reduceCheckpoints()` | `mixed` | Reduce a run's completed checkpoint values into the next `sync_cursor` |

It also inherits `defaultRateLimit()` from [`DeclaresRateLimit`](#declaresratelimit), so a sync provider declares its rate budget the same way.

`sync()` doesn't process items or return a result. It hands each item to `$session->dispatch($event, $checkpointValue, $externalId)`, and the framework wraps each one in a queued `ProcessSyncItem` job, batches them, runs the listeners, and advances the cursor once every job has succeeded. See [Scheduled syncs](/features/scheduled-syncs).

`reduceCheckpoints()` turns the run's completed checkpoint values into the next cursor. `use Integrations\Concerns\ReducesCheckpointsByMax` for the common "max wins" reduction (correct for ISO-8601 timestamps and lexicographic ids), or implement it directly for non-comparable cursors.

## HasIncrementalSync

Extends `HasScheduledSync` with delta sync support.

```php
interface HasIncrementalSync extends HasScheduledSync
{
    public function syncIncremental(Integration $integration, SyncSession $session): void;
}
```

| Method | Returns | Description |
|--------|---------|-------------|
| `syncIncremental()` | `void` | Like `sync()`, but fetches only items changed since `$session->cursor()` |

Read the previous cursor with `$session->cursor()` (null on the first run) and scope the upstream request by it. When a provider implements `HasIncrementalSync`, the sync job calls `syncIncremental()` instead of `sync()`.

## HandlesWebhooks

Adds inbound webhook handling.

```php
interface HandlesWebhooks
{
    public function handleWebhook(Integration $integration, Request $request): mixed;
    public function verifyWebhookSignature(Integration $integration, Request $request): bool;
    public function resolveWebhookEvent(Request $request): ?string;
    public function webhookHandlers(): array;
    public function webhookDeliveryId(Request $request): ?string;
}
```

| Method | Returns | Description |
|--------|---------|-------------|
| `handleWebhook()` | `mixed` | Process the webhook payload |
| `verifyWebhookSignature()` | `bool` | Validate the webhook signature |
| `resolveWebhookEvent()` | `?string` | Extract event type from payload |
| `webhookHandlers()` | `array` | Map of event type to handler class |
| `webhookDeliveryId()` | `?string` | Deduplication key |

## HasOAuth2

Adds OAuth2 authorization flow.

```php
interface HasOAuth2
{
    public function authorizationUrl(Integration $integration, string $redirectUri, string $state): string;
    public function exchangeCode(Integration $integration, string $code, string $redirectUri): array;
    public function refreshToken(Integration $integration): array;
    public function revokeToken(Integration $integration): void;
    public function refreshThreshold(): int;
}
```

| Method | Returns | Description |
|--------|---------|-------------|
| `authorizationUrl()` | `string` | Build the provider's OAuth consent URL |
| `exchangeCode()` | `array` | Exchange authorization code for tokens |
| `refreshToken()` | `array` | Refresh an expired access token |
| `revokeToken()` | `void` | Revoke the OAuth authorization |
| `refreshThreshold()` | `int` | Seconds before expiry to trigger refresh |

Both `exchangeCode()` and `refreshToken()` return `['access_token' => ..., 'refresh_token' => ..., 'token_expires_at' => ...]`.

## HasHealthCheck

Adds lightweight connection testing.

```php
interface HasHealthCheck
{
    public function healthCheck(Integration $integration): bool;
}
```

| Method | Returns | Description |
|--------|---------|-------------|
| `healthCheck()` | `bool` | Test the connection without a full sync |

## IdentifiesAuthenticatedUser

Resolves the account the integration's credentials authenticate as.

```php
interface IdentifiesAuthenticatedUser
{
    public function authenticatedUser(Integration $integration): AuthenticatedUser;
}
```

| Method | Returns | Description |
|--------|---------|-------------|
| `authenticatedUser()` | `AuthenticatedUser` | The principal behind the credentials, mapped from the upstream "who am I" call |

Read it through `Integration::authenticatedUser(?CarbonInterface $cacheFor = null, bool $refresh = false)`, which caches the resolved identity and throws `UnsupportedByProvider` when the provider doesn't implement this contract. Pre-check with `Integration::supportsAuthenticatedUser()` to branch without catching. See [Authenticated identity](/features/authenticated-identity).

## RedactsRequestData

Declares sensitive fields to redact before persistence.

```php
interface RedactsRequestData
{
    public function sensitiveRequestFields(): array;
    public function sensitiveResponseFields(): array;
}
```

| Method | Returns | Description |
|--------|---------|-------------|
| `sensitiveRequestFields()` | `array` | Dot-notation field paths in request data |
| `sensitiveResponseFields()` | `array` | Dot-notation field paths in response data |

## LimitsRequestLogging

Opts a provider's noisiest endpoints out of storing their response body.

```php
interface LimitsRequestLogging
{
    public function unloggedResponseEndpoints(): array;
}
```

| Method | Returns | Description |
|--------|---------|-------------|
| `unloggedResponseEndpoints()` | `array` | Endpoint patterns whose response bodies are not stored |

Patterns use `*` as a wildcard within a path segment, with an optional HTTP-verb prefix: `['chat/*', 'POST:embeddings']`. The request row is still written, so health, failure rates and request counts are unaffected; only the body is replaced with a note of its size. See [Request logging](/core-concepts/logging#request-logging).

## SupportsIdempotency

Marker interface declaring that the upstream API natively dedupes requests by idempotency key. No methods.

```php
interface SupportsIdempotency {}
```

Implement on a provider when the underlying API recognises an idempotency key (Stripe's `Idempotency-Key` header, for example) and dedupes server-side. Without this marker, core logs a warning when a caller attaches a key. The key still persists to `integration_requests.idempotency_key` for searchability, but it's a no-op upstream.

See [Idempotency](/core-concepts/idempotency) for how the key gets onto the wire.

## CustomizesRetry

Provider-specific retry decisions for SDKs with custom exceptions.

```php
interface CustomizesRetry
{
    public function isRetryable(\Throwable $e): ?bool;
    public function retryDelayMs(\Throwable $e, int $attempt, ?int $statusCode): ?int;
}
```

| Method | Returns | Description |
|--------|---------|-------------|
| `isRetryable()` | `?bool` | Whether the exception is retryable, null = fall back to core |
| `retryDelayMs()` | `?int` | Delay in milliseconds before retry, null = fall back to core |

Both methods return `null` to fall back to the default retry logic.
