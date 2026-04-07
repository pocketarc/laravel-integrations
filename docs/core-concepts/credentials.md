# Credentials & metadata

Integrations store two sets of data: **credentials** (encrypted at rest) and **metadata** (plain JSON).

## Credentials

Credentials are encrypted automatically when stored and decrypted when accessed. This happens via a cast on the `Integration` model -- you never deal with encryption directly.

```php
$integration = Integration::create([
    'provider' => 'zendesk',
    'name' => 'Production Zendesk',
    'credentials' => [
        'subdomain' => 'acme',
        'api_token' => 'abc123',
        'email' => 'admin@acme.com',
    ],
]);
```

### Plain array access

By default, `$integration->credentials` returns whatever the provider's `credentialDataClass()` returns. Use `credentialsArray()` when you need the raw array regardless:

```php
$token = $integration->credentialsArray()['api_token'];
```

### Typed access with Data classes

Providers can declare a [Spatie Laravel Data](https://spatie.be/docs/laravel-data/v4/introduction) class for typed credential access:

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
}
```

Now `$integration->credentials` returns a `ZendeskCredentials` instance:

```php
$integration->credentials->subdomain; // 'acme'
$integration->credentials->api_token; // 'abc123'
```

## Metadata

Metadata is stored as plain JSON (not encrypted). Use it for non-sensitive configuration like locale, subdomain, or feature flags.

```php
$integration = Integration::create([
    'provider' => 'zendesk',
    'name' => 'Production Zendesk',
    'credentials' => [...],
    'metadata' => ['locale' => 'en-US'],
]);
```

Metadata supports the same typed access pattern via `metadataDataClass()`. Return `null` for plain array access.
