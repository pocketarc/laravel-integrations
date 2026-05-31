<?php

declare(strict_types=1);

namespace Integrations\Tests\Fixtures;

use Integrations\Contracts\IdentifiesAuthenticatedUser;
use Integrations\Contracts\IntegrationProvider;
use Integrations\Data\AuthenticatedUser;
use Integrations\Models\Integration;

class IdentifyingProvider implements IdentifiesAuthenticatedUser, IntegrationProvider
{
    /**
     * How many times authenticatedUser() has run, so caching tests can assert
     * the provider is hit once (cached) or every call (uncached). Bind a
     * shared instance via $this->app->instance() to read it across calls.
     */
    public int $calls = 0;

    public function authenticatedUser(Integration $integration): AuthenticatedUser
    {
        $this->calls++;

        return new AuthenticatedUser(
            id: 'u-1',
            username: 'octocat',
            name: 'The Octocat',
            email: 'octo@example.com',
            raw: ['login' => 'octocat', 'id' => 1],
        );
    }

    public function name(): string
    {
        return 'Identifying Provider';
    }

    public function credentialRules(): array
    {
        return [];
    }

    public function metadataRules(): array
    {
        return [];
    }

    public function credentialDataClass(): ?string
    {
        return null;
    }

    public function metadataDataClass(): ?string
    {
        return null;
    }
}
