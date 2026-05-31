<?php

declare(strict_types=1);

namespace Integrations\Contracts;

use Integrations\Data\AuthenticatedUser;
use Integrations\Exceptions\UnsupportedByProvider;
use Integrations\Models\Integration;

/**
 * Lets a provider answer "which account is this integration authenticated
 * as?" — the principal behind the credentials. Without this, the resolved
 * identity is known only to the upstream API and never surfaced to the app.
 *
 * Read it through `Integration::authenticatedUser()`, which adds caching and
 * throws {@see UnsupportedByProvider} when the
 * provider doesn't implement this contract.
 */
interface IdentifiesAuthenticatedUser
{
    /**
     * The account the integration's credentials authenticate as.
     *
     * Implementations make the upstream "who am I" call (GitHub `GET /user`,
     * Zendesk `GET /users/me.json`, …) through the integration's request
     * builder, so the circuit breaker, rate limiter, and request logging all
     * apply, and map the payload to a provider-agnostic {@see AuthenticatedUser}.
     */
    public function authenticatedUser(Integration $integration): AuthenticatedUser;
}
