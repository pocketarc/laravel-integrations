# Building adapters

This guide covers the conventions used by the official adapters. Follow these patterns whether you're contributing to the official adapters package or building your own.

## Adapter structure

Each adapter follows this directory layout:

```
Linear/
├── LinearProvider.php      # Main provider implementing contracts
├── LinearClient.php        # Wraps the SDK, bootstraps credentials
├── LinearCredentials.php   # Spatie Data class for credentials
├── LinearMetadata.php      # Spatie Data class for metadata
├── LinearResource.php      # Abstract base for resource classes
├── Data/                   # Spatie Data DTOs for API responses
├── Resources/              # Concrete resource implementations
├── Enums/                  # Enums for statuses, types, etc.
└── Events/                 # Dispatchable events for sync
```

## Provider class

The provider implements `IntegrationProvider` plus whichever optional contracts make sense:

```php
class LinearProvider implements
    IntegrationProvider,
    HasIncrementalSync,
    HasHealthCheck,
    RedactsRequestData
{
    // ...
}
```

Common combinations:
- **Read-only sync**: `HasIncrementalSync` + `HasHealthCheck` + `RedactsRequestData`
- **Full CRUD + sync**: Add `CustomizesRetry` if the SDK throws custom exceptions
- **OAuth + sync**: Add `HasOAuth2`
- **Webhooks only**: `HandlesWebhooks` + `HasHealthCheck`

## Client class

The client wraps the third-party SDK with lazy initialization:

```php
class LinearClient
{
    private ?SdkClient $sdk = null;

    public function __construct(
        private readonly Integration $integration,
    ) {}

    private function boot(): SdkClient
    {
        if ($this->sdk === null) {
            $credentials = $this->integration->credentials;
            // Validate types at runtime
            assert($credentials instanceof LinearCredentials);

            $this->sdk = new SdkClient($credentials->api_key);
        }

        return $this->sdk;
    }
}
```

Patterns to follow:
- Lazy-load the SDK on first use via a `boot()` method
- Validate credential/metadata types at bootstrap time
- Expose resource access via methods: `$client->issues()`, `$client->users()`

## Resource classes

Resources handle the actual API calls. They go through the fluent `at()->...->get()` builder (or the underlying `Integration::request()`) so everything is logged, rate-limited, and health-tracked:

```php
class LinearIssues extends LinearResource
{
    use HandlesErrors;

    public function get(string $id): LinearIssueData
    {
        return $this->integration
            ->at("issues/{$id}")
            ->as(LinearIssueData::class)
            ->get(fn () => $this->sdk->issues()->find($id));
    }

    public function since(string $cursor, Closure $callback): void
    {
        // Paginate through results, calling $callback for each item
    }
}
```

The `HandlesErrors` concern provides `executeWithErrorHandling()` for try/catch with logging.

## Input validation

Callers are internal application code, but the adapter is still a boundary worth guarding. For IDs, amounts, limits, and any value the upstream would reject with an opaque error, fail fast locally so the stack trace points at the actual caller. Keep a small base class for shared helpers rather than repeating guards in every method:

```php
abstract class StripeResource
{
    // ... constructor, sdk() accessor ...

    protected function assertId(string $id): void
    {
        if ($id === '') {
            throw new InvalidArgumentException('Stripe resource id cannot be empty.');
        }
    }

    protected function assertPositive(int $value, string $parameter): void
    {
        if ($value <= 0) {
            throw new InvalidArgumentException(sprintf(
                'Stripe %s must be positive, got %d.',
                $parameter,
                $value,
            ));
        }
    }
}
```

Then each method validates its inputs before building the request:

```php
public function retrieve(string $id): Refund
{
    $this->assertId($id); // '' would otherwise build `refunds/`, hitting the list endpoint

    return $this->expectInstance(
        $this->integration->at("refunds/{$id}")->get(...),
        Refund::class,
    );
}
```

Two reasons this matters:
- Empty-string IDs silently route to the list endpoint of most REST APIs; the `assertId()` check catches the common footgun (uninit variable, `?? ''` fallback in caller code) before the URL is built.
- Non-positive limits and amounts would be rejected by the upstream anyway, but a local `InvalidArgumentException` points at the caller directly rather than surfacing as an opaque API error.

## Idempotency keys for non-idempotent writes

The core retries GET requests 3 times and non-GET requests once. If a POST reaches the upstream but the response is lost in transit, the retry re-runs the closure and creates a second record: a duplicate refund, a duplicate charge capture, a duplicate dispatched message.

For any state-changing POST where a duplicate would be bad, accept an optional idempotency key and auto-generate one when the caller doesn't supply it:

```php
public function create(
    int $amount,
    string $currency,
    // ...
    ?string $idempotencyKey = null,
): PaymentIntent {
    $this->assertPositive($amount, 'amount');

    // null -> fresh UUID; '' -> throws; non-empty -> used as-is
    $idempotencyKey = $this->resolveIdempotencyKey($idempotencyKey);

    return $this->expectInstance(
        $this->integration
            ->at('payment_intents')
            ->withData($params)
            ->post(fn (): PaymentIntent => $this->sdk()->paymentIntents->create(
                $params,
                ['idempotency_key' => $idempotencyKey],
            )),
        PaymentIntent::class,
    );
}
```

Helper on the resource base class:

```php
protected function resolveIdempotencyKey(?string $key): string
{
    if ($key === null) {
        return Str::uuid()->toString();
    }

    if ($key === '') {
        throw new InvalidArgumentException('idempotencyKey must not be empty when provided.');
    }

    return $key;
}
```

Three things to get right:
- Generate the key outside the closure. The closure runs once per attempt, so generating inside it would produce a new key per retry and defeat the purpose.
- Callers re-issuing the same operation across job runs (e.g. a queued job that retries after a crash) should pass a stable key derived from the originating domain event. The auto-generated UUID only protects transient retries inside one adapter call, not retries across separate calls.
- Reject blank strings up front. Forwarding `['idempotency_key' => '']` silently disables the upstream's duplicate-protection behaviour without an error.

## Return types

Two paths for typing resource responses, depending on what the SDK gives you back:

| SDK behaviour                                 | Return                              | Example                |
|-----------------------------------------------|-------------------------------------|------------------------|
| Already returns typed classes (phpdoc or native) | The SDK type directly            | Stripe (`\Stripe\Refund`) |
| Returns arrays or loosely-typed objects       | Local Spatie Data class             | GitHub (`GitHubIssueData`), Zendesk (`ZendeskTicketData`) |

Wrapping an already-typed SDK response in a local Data class duplicates work without adding anything. Wrapping a raw-array response gives you strong typing, a place to normalise odd shapes, and a stable surface that survives SDK swaps.

### Returning SDK types directly

`Integration::request()` returns `mixed` because the closure can return anything the pipeline needs to log. Narrow back to the expected type with a runtime check rather than PHPDoc annotations. A generic helper on the resource base class handles this once:

```php
/**
 * @template T of object
 * @param  class-string<T>  $class
 * @return T
 */
protected function expectInstance(mixed $value, string $class): object
{
    if (! $value instanceof $class) {
        throw new UnexpectedValueException(sprintf(
            'Expected instance of %s, got %s.',
            $class,
            get_debug_type($value),
        ));
    }

    return $value;
}
```

For list endpoints, most SDKs expose a typed Collection class (e.g. `\Stripe\Collection<\Stripe\Refund>`). Narrow to that rather than iterating into a plain `list<T>`, so callers keep access to pagination metadata.

Serialization for request logging still works: objects that implement `JsonSerializable` (like `\Stripe\StripeObject`) are JSON-encoded by the pipeline before being stored.

### Wrapping in local Data classes

When the SDK returns raw arrays, use Spatie Laravel Data:

```php
class LinearIssueData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $state,
        public readonly ?LinearUserData $assignee,
        public readonly array $original, // store original API response
    ) {}

    public static function prepareForPipeline(array $properties): array
    {
        // Transform raw API response into constructor-friendly shape
        return [
            'id' => $properties['id'],
            'title' => $properties['title'],
            'state' => $properties['state']['name'] ?? $properties['state'],
            'assignee' => $properties['assignee'] ?? null,
            'original' => $properties,
        ];
    }
}
```

Patterns to follow:
- Store the original API response in an `original` property for debugging.
- Use `prepareForPipeline()` to transform raw API responses.
- Extract nested data (attachments from HTML, fallback values, etc.) in the pipeline.

## Events

Dispatch events during sync so consuming applications can process the data:

```php
class LinearIssueSynced
{
    use Dispatchable;

    public function __construct(
        public readonly Integration $integration,
        public readonly LinearIssueData $issue,
    ) {}
}
```

Typical events per adapter:
- `LinearIssueSynced` -- per successful item
- `LinearIssueSyncFailed` -- per failed item
- `LinearSyncCompleted` -- after the full sync

## Sync pattern

For incremental sync with safe cursor advancement:

```php
public function syncIncremental(Integration $integration, mixed $cursor): SyncResult
{
    $client = new LinearClient($integration);
    $startTime = $cursor ?? now()->subDay()->toIso8601String();

    // Subtract overlap buffer to catch items updated between syncs
    $bufferedStart = Carbon::parse($startTime)->subHour()->toIso8601String();

    $success = 0;
    $failures = 0;
    $safeCursor = $startTime;

    $client->issues()->since($bufferedStart, function ($issue) use ($integration, &$success, &$failures, &$safeCursor) {
        try {
            LinearIssueSynced::dispatch($integration, $issue);
            $success++;
            $safeCursor = max($safeCursor, $issue->updated_at);
        } catch (\Throwable $e) {
            LinearIssueSyncFailed::dispatch($integration, $issue, $e);
            $failures++;
            // Don't advance cursor past failed items
        }
    });

    LinearSyncCompleted::dispatch($integration, new SyncResult($success, $failures, now(), $safeCursor));

    return new SyncResult($success, $failures, now(), $safeCursor);
}
```

Important:
- Subtract an overlap buffer from the cursor (1 hour in official adapters)
- Don't advance the cursor past failed items
- Consumers should use [`upsertByExternalId()`](/features/id-mapping#upsert-by-external-id) since overlap is expected

## Auto-registration

Adapter packages can auto-register their providers so users don't need to manually edit `config/integrations.php`. Ship a Laravel service provider that calls `IntegrationManager::registerDefaults()`:

```php
<?php

namespace Integrations\Adapters;

use Illuminate\Support\ServiceProvider;
use Integrations\Adapters\GitHub\GitHubProvider;
use Integrations\Adapters\Zendesk\ZendeskProvider;
use Integrations\IntegrationManager;

class IntegrationAdaptersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        IntegrationManager::registerDefaults([
            'github' => GitHubProvider::class,
            'zendesk' => ZendeskProvider::class,
        ]);
    }
}
```

Then register it for Laravel's package auto-discovery in your `composer.json`:

```json
"extra": {
    "laravel": {
        "providers": [
            "Integrations\\Adapters\\IntegrationAdaptersServiceProvider"
        ]
    }
}
```

With this in place, `composer require` is all the user needs. Users can still override any key in their published config if they want a custom provider class.

## Contributing to the official package

To add a new adapter to `pocketarc/laravel-integrations-adapters`:

1. Create the adapter directory under `src/Linear/` (using your service's name).
2. Follow the patterns above.
3. Add a `README.md` inside your adapter directory.
4. Add tests under `tests/Unit/Linear/`.
5. Add your provider to `IntegrationAdaptersServiceProvider::register()` so it's auto-registered.
6. Open a PR.

## Releasing a community adapter

If you prefer to maintain your own package:

1. Create a new Composer package.
2. Require `pocketarc/laravel-integrations` as a dependency.
3. Follow the same patterns for consistency.
4. Ship a service provider that calls `IntegrationManager::registerDefaults()` (see [Auto-registration](#auto-registration) above).
5. Register it for auto-discovery in your `composer.json`.
6. Submit your adapter for listing on these docs by opening an issue on the [laravel-integrations](https://github.com/pocketarc/laravel-integrations) repository.
