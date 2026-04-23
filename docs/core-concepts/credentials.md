# Credentials & metadata

Integrations store two sets of data: **credentials** (encrypted at rest) and **metadata** (plain JSON).

## Credentials

Credentials are encrypted automatically when stored and decrypted when accessed. This happens via a cast on the `Integration` model -- you never deal with encryption directly.

```php
$integration = Integration::create([
    'provider' => 'github',
    'name' => 'Acme GitHub',
    'credentials' => [
        'token' => 'ghp_abc123...',
    ],
]);
```

Or seed the row from the CLI. The [`integrations:install`](/reference/artisan-commands#integrations-install) Artisan command introspects your provider's `credentialDataClass()` to prompt for each required field, masks secret-looking ones, validates against your rules, and persists the row.

### Plain array access

By default, `$integration->credentials` returns whatever the provider's `credentialDataClass()` returns. Use `credentialsArray()` when you need the raw array regardless:

```php
$token = $integration->credentialsArray()['token'];
```

### Typed access with Data classes

Providers can declare a [Spatie Laravel Data](https://spatie.be/docs/laravel-data/v4/introduction) class for typed credential access:

```php
use Spatie\LaravelData\Data;

class GitHubCredentials extends Data
{
    public function __construct(
        public string $token,
    ) {}
}
```

```php
class GitHubProvider implements IntegrationProvider
{
    // ...

    public function credentialDataClass(): ?string
    {
        return GitHubCredentials::class;
    }
}
```

Now `$integration->credentials` returns a `GitHubCredentials` instance:

```php
$integration->credentials->token; // 'ghp_abc123...'
```

## Metadata

Metadata is stored as plain JSON (not encrypted). Use it for non-sensitive configuration like locale, subdomain, or feature flags.

```php
$integration = Integration::create([
    'provider' => 'github',
    'name' => 'Acme GitHub',
    'credentials' => [...],
    'metadata' => ['owner' => 'acme', 'repo' => 'widgets'],
]);
```

Metadata supports the same typed access pattern via `metadataDataClass()`. Return `null` for plain array access.
