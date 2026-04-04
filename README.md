# Laravel Integrations

[![CI](https://github.com/pocketarc/laravel-integrations/actions/workflows/ci.yml/badge.svg)](https://github.com/pocketarc/laravel-integrations/actions/workflows/ci.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/pocketarc/laravel-integrations)](https://packagist.org/packages/pocketarc/laravel-integrations)
[![Total Downloads](https://img.shields.io/packagist/dt/pocketarc/laravel-integrations)](https://packagist.org/packages/pocketarc/laravel-integrations)
[![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-8892BF?logo=php)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

A Laravel 11-13 package for production-ready third-party integrations. 

Provides the connection layer between your app and external APIs.

* Credential management
* API request logging
* Rate limiting
* Retry logic
* Sync scheduling
* OAuth2
* Health monitoring
* Webhook handling
* ID mapping

## Installation

```bash
composer require pocketarc/laravel-integrations
```

Publish the config and migrations:

```bash
php artisan vendor:publish --tag=integrations-config
php artisan vendor:publish --tag=integrations-migrations
php artisan migrate
```

## Quick start

### 1. Create a provider

You can scaffold a provider with the Artisan command:

```bash
php artisan make:integration-provider Zendesk --sync --webhooks --oauth --health-check
```

Or run it without flags for interactive prompts. Use `--all` to include all interfaces.

A provider defines how your app talks to an external service. At minimum, implement `IntegrationProvider`:

```php
namespace App\Integrations;

use Integrations\Contracts\IntegrationProvider;

class ZendeskProvider implements IntegrationProvider
{
    public function name(): string
    {
        return 'Zendesk';
    }

    public function credentialRules(): array
    {
        return [
            'subdomain' => ['required', 'string'],
            'api_token' => ['required', 'string'],
            'email' => ['required', 'email'],
        ];
    }

    public function metadataRules(): array
    {
        return [
            'locale' => ['sometimes', 'string'],
        ];
    }

    public function credentialDataClass(): ?string
    {
        return ZendeskCredentials::class;
    }

    public function metadataDataClass(): ?string
    {
        return null;
    }
}
```

### 2. Register it

In `config/integrations.php`:

```php
'providers' => [
    'zendesk' => App\Integrations\ZendeskProvider::class,
],
```

Or programmatically via the facade:

```php
use Integrations\Facades\Integrations;

Integrations::register('zendesk', ZendeskProvider::class);
```

### 3. Create an integration

```php
use Integrations\Models\Integration;

$integration = Integration::create([
    'provider' => 'zendesk',
    'name' => 'Production Zendesk',
    'credentials' => [
        'subdomain' => 'acme',
        'api_token' => 'abc123',
        'email' => 'admin@acme.com',
    ],
    'metadata' => ['locale' => 'en-US'],
]);
```

Credentials are encrypted at rest automatically. Metadata is stored as plain JSON.

### 4. Make API requests

Every API call goes through `$integration->request()`, which handles logging, caching, rate limiting, retries, and health tracking:

```php
$result = $integration->request(
    endpoint: '/api/v2/tickets.json',
    method: 'GET',
    callback: fn () => Http::withHeaders([
        'Authorization' => 'Bearer '.$integration->credentialsArray()['api_token'],
    ])->get("https://{$subdomain}.zendesk.com/api/v2/tickets.json"),
);
```

## Table of contents

- [Making requests](#making-requests)
- [Provider contracts](#provider-contracts)
- [Typed credentials and metadata](#typed-credentials-and-metadata)
- [OAuth2](#oauth2)
- [Webhooks](#webhooks)
- [Scheduled syncs](#scheduled-syncs)
- [Health monitoring](#health-monitoring)
- [ID mapping](#id-mapping)
- [Operation logging](#operation-logging)
- [Structured logging](#structured-logging)
- [Events](#events)
- [Artisan commands](#artisan-commands)
- [Testing](#testing)
- [Multi-tenancy](#multi-tenancy)
- [Configuration reference](#configuration-reference)

## Making requests

`Integration::request()` wraps any API call with the full parameter list:

```php
$result = $integration->request(
    endpoint: '/api/v2/tickets.json',
    method: 'GET',
    callback: fn () => Http::get($url),
    relatedTo: $ticket,                // optional - links this request to a model
    requestData: ['status' => 'open'], // optional - logged (auto-captured for HTTP responses)
    cacheFor: now()->addHour(),        // optional - cache the response
    serveStale: true,                  // optional - return expired cache on error
    maxRetries: 3,                     // optional - retry on transient errors (default: 3)
);
```

The `endpoint` and `method` are logical identifiers. They can be real HTTP paths or SDK operation names:

```php
// SDK-style: endpoint is a logical name
$result = $integration->request(
    endpoint: 'customers.create',
    method: 'POST',
    callback: fn () => $stripe->customers->create(['email' => $email]),
    requestData: ['email' => $email],
);
```

<details>
<summary><strong>What happens inside <code>request()</code></strong></summary>

1. Counts actual requests in the last minute against the provider's configured limit. Throws `RateLimitExceededException` if exceeded.
2. If `cacheFor` is set, looks for a matching unexpired response (same integration + endpoint + method + request data hash).
3. Runs your closure, measuring duration with `hrtime()`.
4. Normalizes the response: Handles Laravel HTTP responses, Guzzle PSR-7 responses, `JsonResponse`, arrays, objects, and strings. Extracts status code and body automatically.
5. If the request fails and stale cache exists, returns the stale response instead of throwing.
6. Saves an `IntegrationRequest` record with full request/response data, timing, and error details.
7. Calls `recordSuccess()` or `recordFailure()` on the integration, updating `consecutive_failures` and `health_status`.
8. Dispatches `RequestCompleted` or `RequestFailed`.

</details>

<details>
<summary><strong>Response caching</strong></summary>

Pass `cacheFor` to cache successful responses. Subsequent identical requests (matched by endpoint + method + request data hash) return the cached response without executing the callback.

```php
$result = $integration->request(
    endpoint: '/api/v2/tickets.json',
    method: 'GET',
    callback: fn () => Http::get($url),
    cacheFor: now()->addHour(),
    serveStale: true, // fall back to expired cache if the live request fails
);
```

Cache hits and stale hits are tracked per-response via `cache_hits` and `stale_hits` counters on `IntegrationRequest`.

</details>

<details>
<summary><strong>Retries</strong></summary>

Requests retry up to 3 times by default on transient errors (429, 5xx, connection errors). Override per-request:

```php
$result = $integration->request(
    endpoint: '/api/v2/tickets.json',
    method: 'GET',
    callback: fn () => Http::get($url),
    maxRetries: 3,
);
```

Each retry is persisted as its own `IntegrationRequest` row with `retry_of` pointing to the first attempt. Every attempt counts toward rate limiting and is visible in logs.

**Backoff strategy:**

| Status           | Backoff                         |
|------------------|---------------------------------|
| 429              | Fixed 30-second delay           |
| 5xx              | Exponential (attempt x 2s)      |
| Connection error | Linear (attempt x 1s)           |
| 4xx (except 429) | Not retried, thrown immediately |

</details>

<details>
<summary><strong>Standalone retry handler</strong></summary>

The `RetryHandler` can also be used independently of `Integration::request()`:

```php
use Integrations\RetryHandler;

$result = RetryHandler::execute(
    callback: fn () => Http::get($url)->throw(),
    maxRetries: 3,
    retryableStatusCodes: [429, 500, 502, 503, 504],
    onRetry: function (int $attempt, Throwable $e) {
        Log::warning("Retry attempt {$attempt}", ['error' => $e->getMessage()]);
    },
);
```

</details>

<details>
<summary><strong>Fluent request builder</strong></summary>

A chainable API is available via `Integration::to()`:

```php
// With a callback
$result = $integration->to('/api/v2/tickets.json')
    ->withCache(3600, serveStale: true)
    ->withRetries(3)
    ->relatedTo($ticket)
    ->get(fn () => Http::get($url));

// With a URL (uses Laravel's HTTP client automatically)
$result = $integration->to('/api/v2/tickets.json')
    ->withData(['status' => 'open'])
    ->get("https://api.example.com/tickets");
```

Available methods: `withCache(int|CarbonInterface $ttl, bool $serveStale)`, `withRetries(int $max)`, `relatedTo(Model $model)`, `withData(string|array $data)`, `retryOf(int $id)`. Terminal methods: `get()`, `post()`, `put()`, `patch()`, `delete()`, `execute(string $method, Closure $callback)`.

</details>

## Provider contracts

Every provider must implement `IntegrationProvider`. Optional interfaces add capabilities:

| Interface             | Purpose                                                         |
|-----------------------|-----------------------------------------------------------------|
| `IntegrationProvider` | **Required.** Name, credential/metadata rules and Data classes. |
| `HasScheduledSync`    | Scheduled sync support with rate limits.                        |
| `HandlesWebhooks`     | Inbound webhook handling with signature verification.           |
| `HasOAuth2`           | OAuth2 authorization flow with token refresh.                   |
| `HasHealthCheck`      | Lightweight connection testing.                                 |
| `RedactsRequestData`  | Redact sensitive fields from stored request/response data.      |
| `HasIncrementalSync`  | Delta sync with cursor support (extends `HasScheduledSync`).    |

<details>
<summary><strong>IntegrationProvider</strong> (required)</summary>

```php
use Integrations\Contracts\IntegrationProvider;

interface IntegrationProvider
{
    public function name(): string;
    public function credentialRules(): array;    // Laravel validation rules
    public function metadataRules(): array;      // Laravel validation rules
    public function credentialDataClass(): ?string; // Spatie Data class or null
    public function metadataDataClass(): ?string;   // Spatie Data class or null
}
```

</details>

<details>
<summary><strong>HasScheduledSync</strong></summary>

```php
use Integrations\Contracts\HasScheduledSync;
use Integrations\Models\Integration;

interface HasScheduledSync
{
    public function sync(Integration $integration): SyncResult;
    public function defaultSyncInterval(): int;  // minutes
    public function defaultRateLimit(): ?int;     // requests/minute, null = unlimited
}
```

Example:

```php
class ZendeskProvider implements IntegrationProvider, HasScheduledSync
{
    // ... name(), credentialRules(), metadataRules() ...

    public function sync(Integration $integration): SyncResult
    {
        $tickets = $integration->request(
            endpoint: '/api/v2/tickets.json',
            method: 'GET',
            callback: fn () => Http::get("https://{$subdomain}.zendesk.com/api/v2/tickets.json"),
        );

        $count = 0;
        foreach ($tickets['tickets'] as $ticket) {
            // Process each ticket...
            $count++;
        }

        return new SyncResult($count, 0, now());
    }

    public function defaultSyncInterval(): int
    {
        return 5; // every 5 minutes
    }

    public function defaultRateLimit(): ?int
    {
        return 400; // Zendesk allows ~400 requests/minute
    }
}
```

</details>

<details>
<summary><strong>HandlesWebhooks</strong></summary>

```php
use Integrations\Contracts\HandlesWebhooks;
use Illuminate\Http\Request;
use Integrations\Models\Integration;

interface HandlesWebhooks
{
    public function handleWebhook(Integration $integration, Request $request): mixed;
    public function verifyWebhookSignature(Integration $integration, Request $request): bool;
    public function resolveWebhookEvent(Request $request): ?string;
    public function webhookHandlers(): array;
    public function webhookDeliveryId(Request $request): ?string;
}
```

Example:

```php
class StripeProvider implements IntegrationProvider, HandlesWebhooks
{
    // ... name(), credentialRules(), metadataRules() ...

    public function handleWebhook(Integration $integration, Request $request): mixed
    {
        $event = $request->input('type');

        return match ($event) {
            'invoice.paid' => $this->handleInvoicePaid($integration, $request),
            'customer.created' => $this->handleCustomerCreated($integration, $request),
            default => null,
        };
    }

    public function verifyWebhookSignature(Integration $integration, Request $request): bool
    {
        $secret = $integration->credentialsArray()['webhook_secret'];

        return hash_equals(
            hash_hmac('sha256', $request->getContent(), $secret),
            $request->header('Stripe-Signature', ''),
        );
    }
}
```

</details>

<details>
<summary><strong>HasOAuth2</strong></summary>

```php
use Integrations\Contracts\HasOAuth2;
use Integrations\Models\Integration;

interface HasOAuth2
{
    public function authorizationUrl(Integration $integration, string $redirectUri, string $state): string;
    public function exchangeCode(Integration $integration, string $code, string $redirectUri): array;
    public function refreshToken(Integration $integration): array;
    public function revokeToken(Integration $integration): void;
    public function refreshThreshold(): int; // seconds before expiry to trigger refresh
}
```

The `exchangeCode()` and `refreshToken()` methods return arrays that get merged into the integration's encrypted credentials. The expected keys are:

```php
[
    'access_token' => '...',
    'refresh_token' => '...',
    'token_expires_at' => '2026-03-24T12:00:00Z',
]
```

</details>

<details>
<summary><strong>HasHealthCheck</strong></summary>

```php
use Integrations\Contracts\HasHealthCheck;
use Integrations\Models\Integration;

interface HasHealthCheck
{
    public function healthCheck(Integration $integration): bool;
}
```

Example:

```php
class ZendeskProvider implements IntegrationProvider, HasHealthCheck
{
    public function healthCheck(Integration $integration): bool
    {
        try {
            $integration->request(
                endpoint: '/api/v2/users/me.json',
                method: 'GET',
                callback: fn () => Http::get("https://{$subdomain}.zendesk.com/api/v2/users/me.json"),
            );
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
```

</details>

<details>
<summary><strong>RedactsRequestData</strong></summary>

Providers handling sensitive data can declare fields to redact before persistence:

```php
use Integrations\Contracts\RedactsRequestData;

class StripeProvider implements IntegrationProvider, RedactsRequestData
{
    public function sensitiveRequestFields(): array
    {
        return ['card.number', 'card.cvc', 'password'];
    }

    public function sensitiveResponseFields(): array
    {
        return ['token', 'secret_key'];
    }
}
```

Fields use dot-notation and are replaced with `[REDACTED]` in stored request and response data.

</details>

<details>
<summary><strong>HasIncrementalSync</strong></summary>

For providers that support fetching only changed records since a cursor or timestamp:

```php
use Integrations\Contracts\HasIncrementalSync;

class ZendeskProvider implements IntegrationProvider, HasIncrementalSync
{
    public function syncIncremental(Integration $integration, mixed $cursor): SyncResult
    {
        $startTime = $cursor ?? now()->subDay()->toIso8601String();

        $tickets = $integration->request(
            endpoint: '/api/v2/incremental/tickets.json',
            method: 'GET',
            callback: fn () => Http::get($url, ['start_time' => $startTime]),
        );

        // Process tickets...

        return new SyncResult(
            successCount: count($tickets),
            failureCount: 0,
            safeSyncedAt: now(),
            cursor: $tickets['end_time'], // stored for next sync
        );
    }

    // Also requires sync(), defaultSyncInterval(), defaultRateLimit() from HasScheduledSync
}
```

The cursor is stored as JSON in the `sync_cursor` column and passed to `syncIncremental()` on the next sync. When a provider implements `HasIncrementalSync`, the sync job calls `syncIncremental()` instead of `sync()`.

</details>

## Typed credentials and metadata

By default, `$integration->credentials` returns a plain array. Providers can declare a [Laravel Data](https://spatie.be/docs/laravel-data/v4/introduction) class for typed access via `credentialDataClass()` and `metadataDataClass()`:

```php
use Spatie\LaravelData\Data;

class ZendeskCredentials extends Data
{
    public function __construct(
        public string $subdomain,
        public string $api_token,
        public string $email,
    ) {}
}
```

```php
class ZendeskProvider implements IntegrationProvider
{
    // ...

    public function credentialDataClass(): ?string
    {
        return ZendeskCredentials::class;
    }

    public function metadataDataClass(): ?string
    {
        return null; // plain array
    }
}
```

Now `$integration->credentials` returns a `ZendeskCredentials` instance:

```php
$integration->credentials->subdomain; // 'acme'
$integration->credentials->api_token; // 'abc123'
```

Use `$integration->credentialsArray()` when you need the raw array regardless of whether a Data class is configured.

## OAuth2

OAuth2 authorization with automatic token refresh is built in.

### Routes

The package registers these routes automatically:

| Route                                    | Name                           | Purpose                  |
|------------------------------------------|--------------------------------|--------------------------|
| `GET /integrations/{id}/oauth/authorize` | `integrations.oauth.authorize` | Start the OAuth flow     |
| `GET /integrations/oauth/callback`       | `integrations.oauth.callback`  | Handle provider callback |
| `POST /integrations/{id}/oauth/revoke`   | `integrations.oauth.revoke`    | Revoke authorization     |

### Starting the flow

Link to the authorize route from your UI:

```html
<a href="{{ route('integrations.oauth.authorize', $integration) }}">Connect to Zendesk</a>
```

The package generates a state token, caches it, and redirects the user to the provider's consent page via your `authorizationUrl()` implementation. After the user authorizes, the provider redirects back to the callback route, which exchanges the code for tokens and stores them in the encrypted credentials column.

### Automatic token refresh

```php
$token = $integration->getAccessToken();
```

This checks `token_expires_at` against the provider's `refreshThreshold()`. If the token is about to expire, it calls `refreshToken()` first, updates credentials, and returns the fresh token.

You can also check and refresh explicitly:

```php
if ($integration->tokenExpiresSoon()) {
    $integration->refreshTokenIfNeeded();
}
```

## Webhooks

Webhook routes are registered automatically:

| Route                                             | Name                            | Purpose                      |
|---------------------------------------------------|---------------------------------|------------------------------|
| `GET\|POST /integrations/{provider}/webhook`      | `integrations.webhook`          | Generic provider webhook     |
| `GET\|POST /integrations/{provider}/{id}/webhook` | `integrations.webhook.specific` | Integration-specific webhook |

Incoming webhooks are stored in the `integration_webhooks` table with full payload, headers, and processing status.

When a webhook arrives:

1. The provider is resolved from the URL
2. Signature is verified via `verifyWebhookSignature()`
3. The webhook is persisted to `integration_webhooks`
4. A `WebhookReceived` event is dispatched
5. A `ProcessWebhook` job is dispatched to the configured `webhook.queue`
6. The job calls your `handleWebhook()` (or routed handler)
7. The result is logged in `IntegrationLog`

Webhook routes have no middleware by default (most providers can't handle CSRF or session auth). Add signature verification middleware via config if needed.

Point your external service's webhook URL at:
```
https://yourapp.com/integrations/zendesk/webhook
https://yourapp.com/integrations/zendesk/42/webhook  # for a specific integration
```

### Event type routing

Providers can declare how to extract the event type from the payload and route to specific handlers:

```php
class StripeProvider implements IntegrationProvider, HandlesWebhooks
{
    public function resolveWebhookEvent(Request $request): ?string
    {
        return $request->input('type'); // e.g. 'invoice.paid'
    }

    public function webhookHandlers(): array
    {
        return [
            'invoice.paid' => HandleInvoicePaid::class,
            'customer.created' => HandleCustomerCreated::class,
        ];
    }
}
```

### Deduplication

Providers can declare a deduplication key to prevent processing the same webhook twice:

```php
public function webhookDeliveryId(Request $request): ?string
{
    return $request->header('X-Webhook-Id');
}
```

When a duplicate is detected, the webhook is stored but not processed.

### Queue processing

All webhooks are processed asynchronously via the `ProcessWebhook` job. Configure the queue in `config/integrations.php`:

```php
'webhook' => [
    'queue' => 'webhooks',
],
```

Payloads exceeding `webhook.max_payload_bytes` (default 1MB) are rejected with a 413 response.

### Replaying webhooks

Stored webhooks can be replayed by their webhook ID:

```bash
php artisan integrations:replay-webhook {webhookId}
```

This reconstructs the request from stored data and re-dispatches it through `handleWebhook()`. Useful when a handler had a bug that's since been fixed.

### Recovering stale webhooks

If a queue worker dies mid-processing, a webhook can get stuck in `processing` status. The recovery command finds these and re-queues them:

```bash
php artisan integrations:recover-webhooks
```

Add to your scheduler for automatic recovery:

```php
Schedule::command('integrations:recover-webhooks')->hourly();
```

A webhook is considered stale after `webhook.processing_timeout` seconds (default 1800 / 30 minutes). Set this higher if your handlers are long-running.

## Scheduled syncs

Providers that implement `HasScheduledSync` get automated sync scheduling.

### Setup

Add one line to your app's scheduler:

```php
// bootstrap/app.php (Laravel 11+)
Schedule::command('integrations:sync')->everyMinute();
```

The `integrations:sync` command finds all active integrations where `next_sync_at` has passed and dispatches a `SyncIntegration` job for each. Jobs use `WithoutOverlapping` to prevent concurrent syncs of the same integration.

### Per-integration intervals

Each integration can have its own sync frequency:

```php
$integration->update([
    'sync_interval_minutes' => 5,   // sync every 5 minutes
    'next_sync_at' => now(),         // start immediately
]);
```

If `sync_interval_minutes` is null, the provider's `defaultSyncInterval()` is used. If neither is set, the integration is not scheduled for sync.

After a successful sync, `markSynced()` sets `last_synced_at` to now and computes the next `next_sync_at`.

### Health-aware backoff

The sync scheduler respects health status. Degraded integrations sync at a reduced frequency, and failing integrations back off heavily:

| Health Status | Interval Multiplier | Example (5-min base)      |
|---------------|---------------------|---------------------------|
| Healthy       | 1x                  | Every 5 minutes           |
| Degraded      | 2x (configurable)   | Every 10 minutes          |
| Failing       | 10x (configurable)  | Every 50 minutes          |
| Disabled      | Not synced          | Requires manual re-enable |

<details>
<summary><strong>Sync timeline</strong></summary>

During a sync, all API requests made via `$integration->request()` are tracked and their IDs stored in the parent sync log's metadata. This allows post-sync analysis:

```php
$syncLog = $integration->logs()->forOperation('sync')->latest()->first();
$requestIds = $syncLog->metadata['request_ids'] ?? [];
$requests = IntegrationRequest::whereIn('id', $requestIds)->get();
```

</details>

## Health monitoring

Integration health is tracked automatically based on request outcomes, using a circuit breaker pattern.

### How it works

Each successful request resets `consecutive_failures` to 0 and sets `health_status` to `healthy`. Each failure increments `consecutive_failures` and updates `last_error_at`. After 5 consecutive failures (configurable), status transitions to `degraded`; after 20, to `failing`. Any subsequent success resets back to `healthy`.

By default, integrations that exceed 50 consecutive failures are automatically set to `disabled` status and stop syncing entirely. This threshold is configurable via `health.disabled_after` (set to `null` to disable). Disabled integrations require manual re-enabling. An `IntegrationDisabled` event is dispatched when this occurs.

Every health transition dispatches an `IntegrationHealthChanged` event with the previous and new status.

### Health checks

Providers that implement `HasHealthCheck` can be probed without running a full sync:

```bash
php artisan integrations:test
```

### Querying by health

```php
Integration::where('health_status', 'failing')->get();
Integration::where('health_status', 'degraded')->get();
```

## ID mapping

Track the relationship between external provider IDs and your internal models:

```php
// Map an external ID to an internal model
$integration->mapExternalId('ticket-4521', $ticket);

// Resolve: external ID -> internal model
$ticket = $integration->resolveMapping('ticket-4521', Ticket::class);

// Reverse: internal model -> external ID
$externalId = $integration->findExternalId($ticket);
```

Mappings are scoped to the integration, so the same external ID can map to different internal models across integrations. The unique constraint is on `(integration_id, external_id, internal_type)`.

`mapExternalId()` uses `updateOrCreate`, so calling it again with the same external ID and type updates the mapping rather than creating a duplicate.

## Operation logging

Log business-level operations (syncs, imports, webhooks) separately from individual API requests:

```php
$log = $integration->logOperation(
    operation: 'sync',
    direction: 'inbound',
    status: 'success',
    summary: 'Synced 42 tickets from Zendesk',
    metadata: ['ticket_count' => 42, 'new' => 12, 'updated' => 30],
    durationMs: 3200,
);
```

### Hierarchical logging

Use `parentId` for per-record granularity under a parent operation:

```php
$parentLog = $integration->logOperation(
    operation: 'sync',
    direction: 'inbound',
    status: 'success',
    summary: 'Full ticket sync',
);

foreach ($tickets as $ticket) {
    $integration->logOperation(
        operation: 'sync',
        direction: 'inbound',
        status: 'success',
        externalId: $ticket['id'],
        summary: "Imported ticket {$ticket['id']}",
        parentId: $parentLog->id,
    );
}
```

### Querying logs

```php
$integration->logs()->successful()->get();
$integration->logs()->failed()->forOperation('sync')->get();
$integration->logs()->topLevel()->recent(48)->get(); // top-level logs from last 48 hours
```

## Events

All events carry the relevant model(s) and use Laravel's standard `Dispatchable` and `SerializesModels` traits.

| Event                      | Payload                                         | When                                         |
|----------------------------|-------------------------------------------------|----------------------------------------------|
| `IntegrationCreated`       | `$integration`                                  | An integration is created                    |
| `IntegrationSynced`        | `$integration`                                  | `markSynced()` is called                     |
| `IntegrationHealthChanged` | `$integration`, `$previousStatus`, `$newStatus` | Health status transitions                    |
| `RequestCompleted`         | `$integration`, `$request`                      | An API request succeeds                      |
| `RequestFailed`            | `$integration`, `$request`                      | An API request fails                         |
| `OperationCompleted`       | `$integration`, `$log`                          | An operation is logged with status `success` |
| `OperationFailed`          | `$integration`, `$log`                          | An operation is logged with status `failed`  |
| `WebhookReceived`          | `$integration`, `$provider`                     | A webhook arrives                            |
| `OAuthCompleted`           | `$integration`                                  | OAuth2 authorization completes               |
| `IntegrationDisabled`      | `$integration`                                  | Integration auto-disabled after threshold    |
| `OAuthRevoked`             | `$integration`                                  | OAuth2 authorization is revoked              |

Listen for them with attribute-based listeners or in your `EventServiceProvider`:

```php
use Integrations\Events\IntegrationHealthChanged;

class NotifyOnHealthDegradation
{
    public function handle(IntegrationHealthChanged $event): void
    {
        if ($event->newStatus->value !== 'healthy') {
            // Notify the team via Slack, email, etc.
        }
    }
}
```

## Artisan commands

| Command                                   | Purpose                                                                |
|-------------------------------------------|------------------------------------------------------------------------|
| `integrations:sync`                       | Find overdue integrations, dispatch sync jobs                          |
| `integrations:list`                       | Show all integrations with health, last sync, request counts           |
| `integrations:health`                     | Detailed health report (error rates, response times, top errors)       |
| `integrations:test`                       | Run `HasHealthCheck` on all supporting integrations                    |
| `integrations:prune`                      | Clean up old request and log records                                   |
| `integrations:recover-webhooks`           | Reset stale processing webhooks to pending and re-dispatch them        |
| `integrations:replay-webhook {webhookId}` | Re-dispatch a stored webhook payload                                   |
| `integrations:stats`                      | Show request counts, error rates, and cache hit ratios per integration |

<details>
<summary><strong>integrations:list</strong> example output</summary>

```
┌──────────┬──────────┬─────────┬─────────────────────┬──────────┬───────────┐
│ Name     │ Provider │ Health  │ Last Synced          │ Requests │ Error Rate│
├──────────┼──────────┼─────────┼─────────────────────┼──────────┼───────────┤
│ Prod ZD  │ zendesk  │ healthy │ 2026-03-22 10:15:00 │ 1,243    │ 0.8%      │
│ GitHub   │ github   │ degraded│ 2026-03-22 10:10:00 │ 891      │ 12.3%     │
└──────────┴──────────┴─────────┴─────────────────────┴──────────┴───────────┘
```

</details>

<details>
<summary><strong>Pruning</strong> schedule and configure</summary>

Add to your scheduler:

```php
Schedule::command('integrations:prune')->daily();
```

Configure retention in `config/integrations.php`:

```php
'pruning' => [
    'requests_days' => 90,    // delete IntegrationRequest records older than 90 days
    'logs_days' => 365,       // delete IntegrationLog records older than 1 year
    'chunk_size' => 1000,     // delete in chunks to avoid table locks
],
```

</details>

## Testing

A testing fake follows the `Http::fake()` pattern, with no real API calls and no database writes.

```php
use Integrations\Models\IntegrationRequest;

// Activate the fake (optionally with canned responses)
IntegrationRequest::fake([
    '/api/v2/tickets.json' => ['tickets' => [['id' => 1, 'subject' => 'Test']]],
    'customers.create' => fn () => ['id' => 'cus_123', 'email' => 'test@example.com'],
]);

// ... run your code that calls $integration->request() ...

// Assert
IntegrationRequest::assertRequested('/api/v2/tickets.json');
IntegrationRequest::assertRequested('/api/v2/tickets.json', times: 2);
IntegrationRequest::assertNotRequested('customers.delete');
IntegrationRequest::assertRequestedWith('customers.create', function (string $requestData) {
    return str_contains($requestData, 'test@example.com');
});

// Clean up
IntegrationRequest::stopFaking();
```

When the fake is active, `Integration::request()` skips rate limiting, caching, health tracking, and database persistence entirely. It records requests in memory and returns your fake responses (or `null` for unmatched endpoints).

### Sequences and exceptions

```php
use Integrations\Testing\ResponseSequence;

IntegrationRequest::fake([
    '/api/items' => new ResponseSequence('first', 'second', 'third'),
    '/api/fail' => new \RuntimeException('Service unavailable'),
]);

// Returns 'first', 'second', 'third', then null
$r1 = $integration->request(endpoint: '/api/items', method: 'GET');

// Throws RuntimeException
$integration->request(endpoint: '/api/fail', method: 'GET');
```

Additional assertions:

```php
IntegrationRequest::assertRequestCount(5);
IntegrationRequest::assertNothingRequested();
```

## Multi-tenancy

The `Integration` model has optional polymorphic `owner_type`/`owner_id` columns for multi-tenant setups:

```php
// Assign ownership
$integration = Integration::create([
    'provider' => 'zendesk',
    'name' => 'Acme Zendesk',
    'credentials' => [...],
    'owner_type' => Team::class,
    'owner_id' => $team->id,
]);

// Query by owner
Integration::ownedBy($team)->get();

// Access the owner
$integration->owner; // returns the Team model
```

If you don't need multi-tenancy, leave these columns null.

## Structured logging

During sync and webhook processing, the package automatically adds integration context to Laravel's shared log context:

```php
// Automatically added by SyncIntegration and ProcessWebhook jobs:
Log::shareContext([
    'integration_id' => 42,
    'integration_provider' => 'zendesk',
    'integration_name' => 'Production Zendesk',
    'integration_operation' => 'sync',
]);
```

Use `IntegrationContext` directly in your own code:

```php
use Integrations\Support\IntegrationContext;

IntegrationContext::push($integration, 'custom-operation');
// ... your code, all Log:: calls include the context ...
IntegrationContext::clear();
```

## Configuration reference

<details>
<summary><strong>Full <code>config/integrations.php</code></strong></summary>

```php
return [
    // Prefix for all database tables: {prefix}s, {prefix}_requests, {prefix}_logs, {prefix}_mappings.
    'table_prefix' => 'integration',

    // Prefix for all cache keys used by this package (e.g. OAuth state tokens).
    'cache_prefix' => 'integrations',

    'webhook' => [
        'prefix' => 'integrations',          // URL prefix: POST /{prefix}/{provider}/webhook
        'queue' => 'default',                // queue for ProcessWebhook jobs
        'max_payload_bytes' => 1_048_576,    // reject payloads larger than 1MB
        'processing_timeout' => 1800,       // seconds before a processing webhook is considered stale
        'middleware' => [],                  // no CSRF by default; webhooks can't carry tokens
    ],

    'oauth' => [
        'route_prefix' => 'integrations',       // URL prefix for OAuth routes
        'middleware' => ['web'],                 // authorize + revoke routes
        'callback_middleware' => ['web'],        // callback route (redirect from provider)
        'success_redirect' => '/integrations',   // where to redirect after OAuth completes
        'state_ttl' => 600,                      // state token validity in seconds (10 min)
        'refresh_lock_ttl' => 30,                // cache lock TTL for token refresh (seconds)
        'refresh_lock_wait' => 15,               // max wait for refresh lock (seconds)
    ],

    'sync' => [
        'queue' => 'default',   // queue for SyncIntegration jobs
        'lock_ttl' => 600,      // WithoutOverlapping lock TTL in seconds
    ],

    'rate_limiting' => [
        'max_wait_seconds' => 10, // wait for capacity before throwing (0 = immediate)
    ],

    'health' => [
        'degraded_after' => 5,    // consecutive failures -> degraded
        'failing_after' => 20,    // consecutive failures -> failing
        'disabled_after' => 50,   // consecutive failures -> disabled (null = never)
        'degraded_backoff' => 2,  // sync interval multiplier when degraded
        'failing_backoff' => 10,  // sync interval multiplier when failing
    ],

    'pruning' => [
        'requests_days' => 90,    // retention for integration_requests
        'logs_days' => 365,       // retention for integration_logs
        'chunk_size' => 1000,     // rows per delete batch
    ],

    // Provider class registration
    'providers' => [
        // 'zendesk' => App\Integrations\ZendeskProvider::class,
    ],
];
```

</details>

## Contributing

Bug fixes and maintenance PRs are welcome. For new features, please open an issue first so we can discuss the approach before you put in the work.

## License

MIT. See [LICENSE](LICENSE) for details.
