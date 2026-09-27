<?php

declare(strict_types=1);

namespace Integrations\Tests\Fixtures;

use Integrations\Contracts\IdentifiesAuthenticatedUser;
use Integrations\Contracts\IntegrationProvider;
use Integrations\Data\AuthenticatedUser;
use Integrations\Models\Integration;
use RuntimeException;

class IdentifyingProvider implements IdentifiesAuthenticatedUser, IntegrationProvider
{
    public int $calls = 0;

    public ?AuthenticatedUser $lastReturned = null;

    public bool $fails = false;

    /** @var array<array-key, mixed> */
    public array $raw = ['login' => 'octocat', 'id' => 1];

    public function authenticatedUser(Integration $integration): AuthenticatedUser
    {
        $this->calls++;

        if ($this->fails) {
            throw new RuntimeException('Upstream unavailable.');
        }

        return $this->lastReturned = new AuthenticatedUser(
            id: 'u-1',
            username: 'octocat',
            name: 'The Octocat',
            email: 'octo@example.com',
            raw: $this->raw,
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
