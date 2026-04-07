# Quick start

Four steps to get from zero to making API requests through the integration layer.

## 1. Create a provider

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

You can also [scaffold this with an Artisan command](/getting-started/scaffolding).

## 2. Register it

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

## 3. Create an integration

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

## 4. Make API requests

Both `request()` and `requestAs()` wrap your API call with logging, caching, rate limiting, retries, and health tracking:

```php
$tickets = $integration->requestAs(
    endpoint: '/api/v2/tickets.json',
    method: 'GET',
    responseClass: TicketListResponse::class,
    callback: fn () => Http::withHeaders([
        'Authorization' => 'Bearer '.$integration->credentialsArray()['api_token'],
    ])->get("https://{$subdomain}.zendesk.com/api/v2/tickets.json"),
);
```

Use `requestAs()` for typed responses (returns a [Spatie Data](https://spatie.be/docs/laravel-data/v4/introduction) object), or `request()` when you don't need typed responses. See [Making Requests](/core-concepts/making-requests) for the full API.

## Next steps

- [Making Requests](/core-concepts/making-requests) -- the full request API including the fluent builder
- [Provider Contracts](/core-concepts/providers) -- optional interfaces for OAuth2, syncs, webhooks, and more
- [Credentials & Metadata](/core-concepts/credentials) -- typed Data classes for credentials
- [Adapters](/adapters/overview) -- ready-to-use adapters for GitHub, Zendesk, and more
