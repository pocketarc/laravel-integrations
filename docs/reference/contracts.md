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

## HasScheduledSync

Adds automated sync scheduling.

```php
interface HasScheduledSync
{
    public function sync(Integration $integration): SyncResult;
    public function defaultSyncInterval(): int;
    public function defaultRateLimit(): ?int;
}
```

| Method | Returns | Description |
|--------|---------|-------------|
| `sync()` | `SyncResult` | Execute a full sync |
| `defaultSyncInterval()` | `int` | Default interval in minutes |
| `defaultRateLimit()` | `?int` | Requests per minute, null = unlimited |

## HasIncrementalSync

Extends `HasScheduledSync` with delta sync support.

```php
interface HasIncrementalSync extends HasScheduledSync
{
    public function syncIncremental(Integration $integration, mixed $cursor): SyncResult;
}
```

| Method | Returns | Description |
|--------|---------|-------------|
| `syncIncremental()` | `SyncResult` | Sync only changed records since cursor |

The `SyncResult` can carry a `cursor` value that gets stored in `sync_cursor` for the next run.

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
