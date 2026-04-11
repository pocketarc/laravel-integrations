# Providers

A provider defines how your app talks to an external service. Every provider must implement the `IntegrationProvider` interface. Optional interfaces add capabilities like OAuth2, sync scheduling, webhooks, and more.

## The IntegrationProvider interface

```php
use Integrations\Contracts\IntegrationProvider;

interface IntegrationProvider
{
    public function name(): string;
    public function credentialRules(): array;       // Laravel validation rules
    public function metadataRules(): array;         // Laravel validation rules
    public function credentialDataClass(): ?string;  // Spatie Data class or null
    public function metadataDataClass(): ?string;    // Spatie Data class or null
}
```

| Method | Description |
|--------|-------------|
| `name()` | Human-readable name shown in logs and commands |
| `credentialRules()` | Laravel validation rules for the credentials array (validated on create/update) |
| `metadataRules()` | Laravel validation rules for the metadata array |
| `credentialDataClass()` | Optional Spatie Data class for typed credential access (see [Credentials & Metadata](/core-concepts/credentials)) |
| `metadataDataClass()` | Optional Spatie Data class for typed metadata access |

## Registration

Register providers in `config/integrations.php`:

```php
'providers' => [
    'github'  => App\Integrations\GitHubProvider::class,
],
```

Or programmatically via the facade:

```php
use Integrations\Facades\Integrations;

Integrations::register('github', GitHubProvider::class);
```

The key (`'github'`) is the provider identifier stored in the `Integration` model's `provider` column.

### Auto-registration for companion packages

Companion packages (like `pocketarc/laravel-integrations-adapters`) can auto-register their providers so users don't need to edit config after `composer require`. The package ships a Laravel service provider that calls `registerDefaults()` during registration:

```php
use Integrations\IntegrationManager;

IntegrationManager::registerDefaults([
    'github' => GitHubProvider::class,
    'zendesk' => ZendeskProvider::class,
]);
```

Defaults never override entries the user has already defined in their published config. If you've set `'github' => MyCustomGitHubProvider::class`, the default is ignored.

## Optional interfaces

Providers can opt into additional capabilities by implementing these interfaces:

| Interface             | Purpose                                                         |
|-----------------------|-----------------------------------------------------------------|
| `IntegrationProvider` | **Required.** Name, credential/metadata rules and Data classes. |
| `HasScheduledSync`    | Scheduled sync support with rate limits.                        |
| `HandlesWebhooks`     | Inbound webhook handling with signature verification.           |
| `HasOAuth2`           | OAuth2 authorization flow with token refresh.                   |
| `HasHealthCheck`      | Lightweight connection testing.                                 |
| `RedactsRequestData`  | Redact sensitive fields from stored request/response data.      |
| `HasIncrementalSync`  | Delta sync with cursor support (extends `HasScheduledSync`).    |
| `CustomizesRetry`     | Provider-specific retry decisions and delay logic.              |

Each interface is documented in detail on its feature page:

- `HasScheduledSync` / `HasIncrementalSync` -- [Scheduled Syncs](/features/scheduled-syncs)
- `HandlesWebhooks` -- [Webhooks](/features/webhooks)
- `HasOAuth2` -- [OAuth2](/features/oauth2)
- `HasHealthCheck` -- [Health Monitoring](/core-concepts/health-monitoring)
- `RedactsRequestData` -- [Data Redaction](/features/redaction)
- `CustomizesRetry` -- [Custom Retry Logic](/advanced/custom-retry)

Full interface signatures are in the [Contracts Reference](/reference/contracts).
