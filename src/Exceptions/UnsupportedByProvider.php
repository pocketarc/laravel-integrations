<?php

declare(strict_types=1);

namespace Integrations\Exceptions;

use Integrations\Contracts\IdentifiesAuthenticatedUser;
use Integrations\Models\Integration;
use RuntimeException;

/**
 * Thrown when a caller asks an integration for a capability its provider
 * doesn't implement, such as the authenticated identity on a provider that
 * isn't an {@see IdentifiesAuthenticatedUser}.
 *
 * `$capability` is a human-readable label for the missing capability.
 * Callers that want to branch rather than catch can pre-check with the
 * matching `supports…()` accessor on {@see Integration} (for example,
 * {@see Integration::supportsAuthenticatedUser()}).
 */
class UnsupportedByProvider extends RuntimeException
{
    public function __construct(
        public readonly Integration $integration,
        public readonly string $capability,
    ) {
        parent::__construct(
            "Provider '{$integration->provider}' for integration '{$integration->name}' does not support {$capability}.",
        );
    }
}
