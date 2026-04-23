# Quick start

Four steps to get from zero to making API requests through the integration layer.

## 1. Create a provider

A provider defines how your app talks to an external service. At minimum, implement `IntegrationProvider`:

```php
namespace App\Integrations;

use Integrations\Contracts\IntegrationProvider;

class GitHubProvider implements IntegrationProvider
{
    public function name(): string
    {
        return 'GitHub';
    }

    public function credentialRules(): array
    {
        return [
            'token' => ['required', 'string'],
        ];
    }

    public function metadataRules(): array
    {
        return [
            'owner' => ['required', 'string'],
            'repo' => ['required', 'string'],
        ];
    }

    public function credentialDataClass(): ?string
    {
        return GitHubCredentials::class;
    }

    public function metadataDataClass(): ?string
    {
        return null;
    }
}
```

You can also [scaffold this with an Artisan command](/getting-started/scaffolding).

## 2. Register it

In `config/integrations.php`:

```php
'providers' => [
    'github' => App\Integrations\GitHubProvider::class,
],
```

Or programmatically via the facade:

```php
use Integrations\Facades\Integrations;

Integrations::register('github', GitHubProvider::class);
```

## 3. Create an integration

```php
use Integrations\Models\Integration;

$integration = Integration::create([
    'provider' => 'github',
    'name' => 'Acme GitHub',
    'credentials' => [
        'token' => 'ghp_abc123...',
    ],
    'metadata' => ['owner' => 'acme', 'repo' => 'widgets'],
]);
```

Credentials are encrypted at rest automatically. Metadata is stored as plain JSON.

Or create the row from the CLI. [`integrations:install`](/reference/artisan-commands#integrations-install) reads your provider's Data classes, prompts for the required fields (masking secret-looking ones), validates against your rules, and runs the health check if the provider has one:

```bash
php artisan integrations:install github --name="Acme GitHub"
```

## 4. Make API requests

Both `request()` and `requestAs()` wrap your API call with logging, caching, rate limiting, retries, and health tracking:

```php
$meta = $integration->metadata;

$issues = $integration->requestAs(
    endpoint: '/repos/{owner}/{repo}/issues',
    method: 'GET',
    responseClass: IssueListResponse::class,
    callback: fn () => Http::withHeaders([
        'Authorization' => 'Bearer '.$integration->credentialsArray()['token'],
    ])->get("https://api.github.com/repos/{$meta['owner']}/{$meta['repo']}/issues"),
);
```

Use `requestAs()` for typed responses (returns a [Spatie Data](https://spatie.be/docs/laravel-data/v4/introduction) object), or `request()` when you don't need typed responses. See [Making Requests](/core-concepts/making-requests) for the full API.

## Next steps

- [Making Requests](/core-concepts/making-requests) -- the full request API including the fluent builder
- [Provider Contracts](/core-concepts/providers) -- optional interfaces for OAuth2, syncs, webhooks, and more
- [Credentials & Metadata](/core-concepts/credentials) -- typed Data classes for credentials
- [Adapters](/adapters/overview) -- ready-to-use adapters for GitHub and more
