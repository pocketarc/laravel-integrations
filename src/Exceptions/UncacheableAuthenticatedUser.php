<?php

declare(strict_types=1);

namespace Integrations\Exceptions;

use Integrations\Data\AuthenticatedUser;
use Integrations\Models\Integration;
use RuntimeException;
use Throwable;

/**
 * Thrown by {@see Integration::authenticatedUser()}. The fault is in the
 * provider's mapping, not the cache store. `$user` is the identity the
 * provider returned.
 */
class UncacheableAuthenticatedUser extends RuntimeException
{
    public function __construct(
        public readonly Integration $integration,
        public readonly AuthenticatedUser $user,
        public readonly string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            "Provider '{$integration->provider}' for integration '{$integration->name}' returned an authenticated user that can't be cached: {$reason}.",
            previous: $previous,
        );
    }
}
