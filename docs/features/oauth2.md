# OAuth2

OAuth2 authorization with automatic token refresh is built in. Providers implement the `HasOAuth2` interface.

## The HasOAuth2 interface

```php
use Integrations\Contracts\HasOAuth2;

interface HasOAuth2
{
    public function authorizationUrl(Integration $integration, string $redirectUri, string $state): string;
    public function exchangeCode(Integration $integration, string $code, string $redirectUri): array;
    public function refreshToken(Integration $integration): array;
    public function revokeToken(Integration $integration): void;
    public function refreshThreshold(): int; // seconds before expiry to trigger refresh
}
```

The `exchangeCode()` and `refreshToken()` methods return arrays that get merged into the integration's encrypted credentials:

```php
[
    'access_token' => '...',
    'refresh_token' => '...',
    'token_expires_at' => '2026-03-24T12:00:00Z',
]
```

## Routes

The package registers these routes automatically:

| Route                                    | Name                           | Purpose                  |
|------------------------------------------|--------------------------------|--------------------------|
| `GET /integrations/{id}/oauth/authorize` | `integrations.oauth.authorize` | Start the OAuth flow     |
| `GET /integrations/oauth/callback`       | `integrations.oauth.callback`  | Handle provider callback |
| `POST /integrations/{id}/oauth/revoke`   | `integrations.oauth.revoke`    | Revoke authorization     |

## Starting the flow

Link to the authorize route from your UI:

```html
<a href="{{ route('integrations.oauth.authorize', $integration) }}">Connect to Zendesk</a>
```

The package generates a state token, caches it, and redirects the user to the provider's consent page via your `authorizationUrl()` implementation. After the user authorizes, the provider redirects back to the callback route, which exchanges the code for tokens and stores them in the encrypted credentials column.

## Automatic token refresh

```php
$token = $integration->getAccessToken();
```

This checks `token_expires_at` against the provider's `refreshThreshold()`. If the token is about to expire, it calls `refreshToken()` first, updates credentials, and returns the fresh token.

You can also check and refresh explicitly:

```php
if ($integration->tokenExpiresSoon()) {
    $integration->refreshTokenIfNeeded();
}
```

### Concurrent refresh protection

Token refresh uses a cache lock to prevent multiple queue workers from refreshing simultaneously. Configurable via:

```php
// config/integrations.php
'oauth' => [
    'refresh_lock_ttl' => 30,  // lock TTL in seconds
    'refresh_lock_wait' => 15, // max wait for lock
],
```

## Configuration

```php
// config/integrations.php
'oauth' => [
    'route_prefix' => 'integrations',
    'middleware' => ['web'],              // authorize + revoke routes
    'callback_middleware' => ['web'],     // callback route
    'success_redirect' => '/integrations', // redirect after OAuth completes
    'state_ttl' => 600,                   // state token validity (10 min)
    'refresh_lock_ttl' => 30,
    'refresh_lock_wait' => 15,
],
```
