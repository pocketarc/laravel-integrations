<?php

declare(strict_types=1);

namespace Integrations\Concerns;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Integrations\Contracts\IdentifiesAuthenticatedUser;
use Integrations\Data\AuthenticatedUser;
use Integrations\Exceptions\UncacheableAuthenticatedUser;
use Integrations\Exceptions\UnsupportedByProvider;
use Integrations\Models\Integration;
use Integrations\Support\Config;
use JsonException;

use function Safe\json_decode;
use function Safe\json_encode;

/**
 * @phpstan-require-extends Integration
 */
trait ResolvesAuthenticatedUser
{
    /**
     * Whether this integration's provider implements
     * {@see IdentifiesAuthenticatedUser}, which {@see self::authenticatedUser()}
     * requires.
     */
    public function supportsAuthenticatedUser(): bool
    {
        return $this->provider() instanceof IdentifiesAuthenticatedUser;
    }

    /**
     * The account behind this integration's credentials, fetched through the
     * provider's {@see IdentifiesAuthenticatedUser::authenticatedUser()}.
     *
     * With $cacheFor, the identity is cached per integration until that time,
     * and a hit returns a base AuthenticatedUser rebuilt from JSON. $refresh
     * clears the cached identity before fetching, so a failed fetch leaves
     * none cached. Without $cacheFor, every call is live and $refresh does
     * nothing.
     *
     * @throws UnsupportedByProvider
     * @throws UncacheableAuthenticatedUser when the fetched identity isn't JSON-encodable or its `raw` has an integer top-level key
     */
    public function authenticatedUser(?CarbonInterface $cacheFor = null, bool $refresh = false): AuthenticatedUser
    {
        $provider = $this->provider();

        if (! $provider instanceof IdentifiesAuthenticatedUser) {
            throw new UnsupportedByProvider($this, 'authenticated-user identification');
        }

        if ($cacheFor === null) {
            return $provider->authenticatedUser($this);
        }

        if ($refresh) {
            $this->forgetAuthenticatedUser();
        }

        $cached = $refresh ? null : $this->cachedAuthenticatedUser();

        if ($cached !== null) {
            return $cached;
        }

        $user = $provider->authenticatedUser($this);

        Cache::put($this->authenticatedUserCacheKey(), $this->encodeAuthenticatedUser($user), $cacheFor);

        return $user;
    }

    /**
     * Clear the identity cached by {@see self::authenticatedUser()}. The OAuth
     * callback and revoke routes call this; call it yourself after changing
     * credentials any other way.
     */
    public function forgetAuthenticatedUser(): void
    {
        Cache::forget($this->authenticatedUserCacheKey());
    }

    private function authenticatedUserCacheKey(): string
    {
        // Must not be auth-user:{id}: older releases read that key and throw a
        // TypeError on any entry that isn't an AuthenticatedUser object.
        return Config::cachePrefix().":auth-user:v2:{$this->id}";
    }

    /**
     * JSON rather than an array: raw may hold objects, and
     * cache.serializable_classes can stop the store unserializing them.
     *
     * @throws UncacheableAuthenticatedUser
     */
    private function encodeAuthenticatedUser(AuthenticatedUser $user): string
    {
        if (self::stringKeyed($user->raw) === null) {
            throw new UncacheableAuthenticatedUser(
                $this,
                $user,
                'its raw array has an integer top-level key (PHP converts a numeric string key such as "123" to an integer)',
            );
        }

        try {
            return json_encode([
                'id' => $user->id,
                'username' => $user->username,
                'name' => $user->name,
                'email' => $user->email,
                'raw' => $user->raw,
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new UncacheableAuthenticatedUser($this, $user, "it isn't JSON-encodable ({$e->getMessage()})", $e);
        }
    }

    /**
     * The cached identity, or null when the entry is missing or malformed.
     * Doesn't use AuthenticatedUser::from(), which applies the app's global
     * laravel-data validation and name mapping.
     */
    private function cachedAuthenticatedUser(): ?AuthenticatedUser
    {
        $cached = Cache::get($this->authenticatedUserCacheKey());

        try {
            $fields = is_string($cached) ? json_decode($cached, true, 512, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            return null;
        }

        if (! is_array($fields)) {
            return null;
        }

        $id = $fields['id'] ?? null;
        $username = $fields['username'] ?? null;
        $name = $fields['name'] ?? null;
        $email = $fields['email'] ?? null;
        $raw = self::stringKeyed($fields['raw'] ?? null);

        if (! is_string($id)
            || ($username !== null && ! is_string($username))
            || ($name !== null && ! is_string($name))
            || ($email !== null && ! is_string($email))
            || $raw === null
        ) {
            return null;
        }

        return new AuthenticatedUser(id: $id, username: $username, name: $name, email: $email, raw: $raw);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function stringKeyed(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $keyed = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                return null;
            }

            $keyed[$key] = $item;
        }

        return $keyed;
    }
}
