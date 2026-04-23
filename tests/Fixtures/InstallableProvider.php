<?php

declare(strict_types=1);

namespace Integrations\Tests\Fixtures;

use Integrations\Contracts\HasHealthCheck;
use Integrations\Contracts\IntegrationProvider;
use Integrations\Models\Integration;

class InstallableProvider implements HasHealthCheck, IntegrationProvider
{
    // Toggled by individual tests to drive health-check branches without
    // having to rebuild the container binding.
    public bool $healthCheckResult = true;

    public bool $healthCheckThrows = false;

    public function name(): string
    {
        return 'Installable';
    }

    public function credentialRules(): array
    {
        return [
            'api_key' => 'required|string',
            'api_secret' => 'required|string',
            'region' => 'nullable|string',
            'timeout' => 'nullable|integer',
            'sandbox' => 'nullable|boolean',
        ];
    }

    public function metadataRules(): array
    {
        return [];
    }

    public function credentialDataClass(): ?string
    {
        return InstallableCredentials::class;
    }

    public function metadataDataClass(): ?string
    {
        return null;
    }

    public function healthCheck(Integration $integration): bool
    {
        if ($this->healthCheckThrows) {
            throw new \RuntimeException('Health check exploded.');
        }

        return $this->healthCheckResult;
    }
}
