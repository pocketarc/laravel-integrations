# Authenticated identity

Ask which account an integration's credentials authenticate as (the principal behind the token) without a provider-specific call. A provider opts in by implementing `IdentifiesAuthenticatedUser`, and the package adds caching, resilience, and a CLI surface on top.

This answers "who are we acting as?" for self-authored filtering (skip the activity your own integration produced), audit, and display.

## The contract

```php
use Integrations\Contracts\IdentifiesAuthenticatedUser;
use Integrations\Data\AuthenticatedUser;
use Integrations\Models\Integration;

interface IdentifiesAuthenticatedUser
{
    public function authenticatedUser(Integration $integration): AuthenticatedUser;
}
```

The implementation makes the upstream "who am I" call through the integration's request builder, so the circuit breaker, rate limiter, and request logging all apply, and maps the payload to a provider-agnostic `AuthenticatedUser`.

## The AuthenticatedUser DTO

A Spatie `Data` object with a stable, provider-agnostic shape:

```php
class AuthenticatedUser extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $username = null,
        public readonly ?string $name = null,
        public readonly ?string $email = null,
        public readonly array $raw = [],
    ) {}
}
```

- `id` — the stable provider user id.
- `username` — the provider's human handle (GitHub login, Zendesk email, …).
- `name`, `email` — when the upstream exposes them.
- `raw` — the full upstream payload, for provider-specific needs the mapped fields don't cover.

## Reading the identity

```php
$user = $integration->authenticatedUser();
$user->id;        // "583231"
$user->username;  // "octocat"
```

`authenticatedUser()` throws `UnsupportedByProvider` when the provider doesn't implement the contract. That's a misconfiguration to fix, not a runtime failure to degrade past. Pre-check with `supportsAuthenticatedUser()` to branch without catching:

```php
if ($integration->supportsAuthenticatedUser()) {
    $me = $integration->authenticatedUser();
}
```

## Caching

The identity rarely changes, and a caller on a hot path (or running while the upstream is down) shouldn't make a live call. Pass `cacheFor` to cache the resolved identity, keyed per integration:

```php
// Cached for a day: only the first call (or a cold cache) hits the upstream.
$me = $integration->authenticatedUser(cacheFor: now()->addDay());

// Force a fresh fetch and overwrite the cached value.
$me = $integration->authenticatedUser(cacheFor: now()->addDay(), refresh: true);
```

With `cacheFor` null the call is always live.

## Treat a failure as unknown

The call goes through the request executor, so it can throw: a provider error, or `CircuitOpenException` when the breaker is open. A warm cache shields a hot path from this, but with no cached value to fall back on, the exception propagates. Catch it and carry on without the identity rather than letting it fail unrelated work:

```php
try {
    $me = $integration->authenticatedUser(cacheFor: now()->addDay());
} catch (\Throwable) {
    $me = null; // proceed without the identity
}
```

## Implementing it on a provider

Map the upstream "who am I" response to an `AuthenticatedUser`, making the call through the integration's request builder so it's logged and gated:

```php
use Integrations\Contracts\IdentifiesAuthenticatedUser;
use Integrations\Data\AuthenticatedUser;
use Integrations\Models\Integration;

class GitHubProvider implements IntegrationProvider, IdentifiesAuthenticatedUser
{
    public function authenticatedUser(Integration $integration): AuthenticatedUser
    {
        $response = $integration
            ->at('/user')
            ->get(fn () => Http::withToken($integration->credentialsArray()['token'])
                ->get('https://api.github.com/user'));

        return new AuthenticatedUser(
            id: (string) $response['id'],
            username: $response['login'],
            name: $response['name'] ?? null,
            email: $response['email'] ?? null,
            raw: $response,
        );
    }
}
```

## In the CLI

`integrations:health` shows the resolved identity for providers that support it:

```
=== GitHub (github) ===
  Health: healthy
  ...
  Authenticated as: octocat (id: 583231)
```

It caches the lookup briefly so a report over many integrations doesn't fan out a live call each, and a failure to resolve degrades to `Authenticated as: unknown (…)` rather than aborting the report.
