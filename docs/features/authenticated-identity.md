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

The identity rarely changes. To skip the live call on a hot path, or while the upstream is down, pass `cacheFor`. The identity is then cached per integration:

```php
// Cached for a day. The upstream is called only when no cached entry exists.
$me = $integration->authenticatedUser(cacheFor: now()->addDay());

// Clear the cached entry and fetch the identity again.
$me = $integration->authenticatedUser(cacheFor: now()->addDay(), refresh: true);
```

If `cacheFor` is null, every call is live.

With `refresh: true`, `authenticatedUser()` clears the cached entry before it fetches the identity. If the fetch fails, the cache stays empty. The next call with `cacheFor` then fetches the identity from the upstream. It does not return the account you asked to replace.

On a cache hit, `authenticatedUser()` returns a plain `AuthenticatedUser` built from the cached fields. The identity is cached as JSON. A provider's `AuthenticatedUser` subclass is therefore returned as the base class, and an object in `raw` is returned as decoded JSON. On a cache miss, `authenticatedUser()` returns the provider's object unchanged.

### Credential changes

The OAuth callback and revoke routes call `forgetAuthenticatedUser()`. After a user reconnects as a different account or revokes the token, the next call does not return the old identity.

If you change an integration's credentials in a different way (for example, from an API key field in your own settings screen), call `forgetAuthenticatedUser()` after the change:

```php
$integration->update(['credentials' => $newCredentials]);
$integration->forgetAuthenticatedUser();
```

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

If you pass `cacheFor`, the identity must be JSON-encodable, and every top-level key of `raw` must be a string. Otherwise, `authenticatedUser()` throws `UncacheableAuthenticatedUser`. The exception's `user` property holds the identity that the provider returned. A call without `cacheFor` is not affected.

When PHP decodes JSON, it converts a numeric string key such as `"123"` to an integer. So if you decode `raw` from an upstream object that has a numeric top-level key, the identity can't be cached.

## In the CLI

`integrations:health` shows the resolved identity for providers that support it:

```text
=== GitHub (github) ===
  Health: healthy
  ...
  Authenticated as: octocat (id: 583231)
```

It caches the lookup briefly so a report over many integrations doesn't fan out a live call each, and a failure to resolve degrades to `Authenticated as: unknown (…)` rather than aborting the report.
