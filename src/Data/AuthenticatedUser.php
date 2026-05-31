<?php

declare(strict_types=1);

namespace Integrations\Data;

use Integrations\Contracts\IdentifiesAuthenticatedUser;
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
 * cover.
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
