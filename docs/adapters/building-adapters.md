# Building adapters

This guide covers the conventions used by the official adapters. Follow these patterns whether you're contributing to the official adapters package or building your own.

## Adapter structure

Each adapter follows this directory layout:

```
YourService/
├── YourServiceProvider.php      # Main provider implementing contracts
├── YourServiceClient.php        # Wraps the SDK, bootstraps credentials
├── YourServiceCredentials.php   # Spatie Data class for credentials
├── YourServiceMetadata.php      # Spatie Data class for metadata
├── YourServiceResource.php      # Abstract base for resource classes
├── Data/                        # Spatie Data DTOs for API responses
├── Resources/                   # Concrete resource implementations
├── Enums/                       # Enums for statuses, types, etc.
└── Events/                      # Dispatchable events for sync
```

## Provider class

The provider implements `IntegrationProvider` plus whichever optional contracts make sense:

```php
class YourServiceProvider implements
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
class YourServiceClient
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
            assert($credentials instanceof YourServiceCredentials);

            $this->sdk = new SdkClient($credentials->token);
        }

        return $this->sdk;
    }
}
```

Patterns to follow:
- Lazy-load the SDK on first use via a `boot()` method
- Validate credential/metadata types at bootstrap time
- Expose resource access via methods: `$client->tickets()`, `$client->users()`

## Resource classes

Resources handle the actual API calls. They go through `Integration::request()` / `requestAs()` so everything is logged, rate-limited, and health-tracked:

```php
class YourServiceTickets extends YourServiceResource
{
    use HandlesErrors;

    public function get(int $id): YourServiceTicketData
    {
        return $this->integration->requestAs(
            endpoint: "tickets/{$id}",
            method: 'GET',
            responseClass: YourServiceTicketData::class,
            callback: fn () => $this->sdk->tickets()->find($id),
        );
    }

    public function since(string $cursor, Closure $callback): void
    {
        // Paginate through results, calling $callback for each item
    }
}
```

The `HandlesErrors` concern provides `executeWithErrorHandling()` for try/catch with logging.

## Data classes

Use Spatie Laravel Data classes for typed API responses:

```php
class YourServiceTicketData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $subject,
        public readonly string $status,
        public readonly ?YourServiceUserData $requester,
        public readonly array $original, // store original API response
    ) {}

    public static function prepareForPipeline(array $properties): array
    {
        // Transform raw API response into constructor-friendly shape
        return [
            'id' => $properties['id'],
            'subject' => $properties['subject'],
            'status' => $properties['status'],
            'requester' => $properties['requester'] ?? null,
            'original' => $properties,
        ];
    }
}
```

Patterns to follow:
- Store the original API response in an `original` property for debugging
- Use `prepareForPipeline()` to transform raw API responses
- Extract nested data (attachments from HTML, fallback values, etc.) in the pipeline

## Events

Dispatch events during sync so consuming applications can process the data:

```php
class YourServiceTicketSynced
{
    use Dispatchable;

    public function __construct(
        public readonly Integration $integration,
        public readonly YourServiceTicketData $ticket,
    ) {}
}
```

Typical events per adapter:
- `YourServiceItemSynced` -- per successful item
- `YourServiceItemSyncFailed` -- per failed item
- `YourServiceSyncCompleted` -- after the full sync

## Sync pattern

For incremental sync with safe cursor advancement:

```php
public function syncIncremental(Integration $integration, mixed $cursor): SyncResult
{
    $client = new YourServiceClient($integration);
    $startTime = $cursor ?? now()->subDay()->toIso8601String();

    // Subtract overlap buffer to catch items updated between syncs
    $bufferedStart = Carbon::parse($startTime)->subHour()->toIso8601String();

    $success = 0;
    $failures = 0;
    $safeCursor = $startTime;

    $client->tickets()->since($bufferedStart, function ($ticket) use ($integration, &$success, &$failures, &$safeCursor) {
        try {
            YourServiceTicketSynced::dispatch($integration, $ticket);
            $success++;
            $safeCursor = max($safeCursor, $ticket->updated_at);
        } catch (\Throwable $e) {
            YourServiceTicketSyncFailed::dispatch($integration, $ticket, $e);
            $failures++;
            // Don't advance cursor past failed items
        }
    });

    YourServiceSyncCompleted::dispatch($integration, new SyncResult($success, $failures, now(), $safeCursor));

    return new SyncResult($success, $failures, now(), $safeCursor);
}
```

Important:
- Subtract an overlap buffer from the cursor (1 hour in official adapters)
- Don't advance the cursor past failed items
- Consumers should use `updateOrCreate()` since overlap is expected

## Contributing to the official package

To add a new adapter to `pocketarc/laravel-integrations-adapters`:

1. Create the adapter directory under `src/YourService/`
2. Follow the patterns above
3. Add a `README.md` inside your adapter directory
4. Add tests under `tests/Unit/YourService/`
5. Open a PR

## Releasing a community adapter

If you prefer to maintain your own package:

1. Create a new Composer package
2. Require `pocketarc/laravel-integrations` as a dependency
3. Follow the same patterns for consistency
4. Submit your adapter for listing on these docs by opening an issue on the [laravel-integrations](https://github.com/pocketarc/laravel-integrations) repository
