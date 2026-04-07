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

Resources handle the actual API calls. They go through `Integration::request()` / `requestAs()` so everything is logged, rate-limited, and health-tracked:

```php
class LinearIssues extends LinearResource
{
    use HandlesErrors;

    public function get(string $id): LinearIssueData
    {
        return $this->integration->requestAs(
            endpoint: "issues/{$id}",
            method: 'GET',
            responseClass: LinearIssueData::class,
            callback: fn () => $this->sdk->issues()->find($id),
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
- Store the original API response in an `original` property for debugging
- Use `prepareForPipeline()` to transform raw API responses
- Extract nested data (attachments from HTML, fallback values, etc.) in the pipeline

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
- Consumers should use `updateOrCreate()` since overlap is expected

## Contributing to the official package

To add a new adapter to `pocketarc/laravel-integrations-adapters`:

1. Create the adapter directory under `src/Linear/` (using your service's name)
2. Follow the patterns above
3. Add a `README.md` inside your adapter directory
4. Add tests under `tests/Unit/Linear/`
5. Open a PR

## Releasing a community adapter

If you prefer to maintain your own package:

1. Create a new Composer package
2. Require `pocketarc/laravel-integrations` as a dependency
3. Follow the same patterns for consistency
4. Submit your adapter for listing on these docs by opening an issue on the [laravel-integrations](https://github.com/pocketarc/laravel-integrations) repository
