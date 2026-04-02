<?php

declare(strict_types=1);

namespace Integrations\Tests\Fixtures;

use Integrations\Contracts\IntegrationProvider;
use Integrations\Contracts\RedactsRequestData;

class RedactingProvider implements IntegrationProvider, RedactsRequestData
{
    public function name(): string
    {
        return 'Redacting Provider';
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

    public function sensitiveRequestFields(): array
    {
        return ['password'];
    }

    public function sensitiveResponseFields(): array
    {
        return ['token'];
    }
}
