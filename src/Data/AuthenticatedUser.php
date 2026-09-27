<?php

declare(strict_types=1);

namespace Integrations\Data;

use Integrations\Contracts\IdentifiesAuthenticatedUser;
use Integrations\Exceptions\UncacheableAuthenticatedUser;
use Spatie\LaravelData\Data;

/**
 * The account an integration's credentials authenticate as: the principal
 * behind the token, resolved from the upstream's "who am I" endpoint and
 * mapped to a provider-agnostic shape. Returned by
 * {@see IdentifiesAuthenticatedUser::authenticatedUser()}.
 *
 * `id` is the stable provider user id; `username` is whatever the provider
 * uses as a human handle (GitHub login, Zendesk email, …). `raw` keeps the
 * full upstream payload for provider-specific needs the mapped fields don't
 * cover. Every field must be JSON-encodable and every top-level key of `raw`
 * must be a string, or Integration::authenticatedUser() throws
 * {@see UncacheableAuthenticatedUser} when called with `$cacheFor`. A cache
 * hit returns a base AuthenticatedUser even when the provider returns a
 * subclass.
 */
class AuthenticatedUser extends Data
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $username = null,
        public readonly ?string $name = null,
        public readonly ?string $email = null,
        public readonly array $raw = [],
    ) {}
}
