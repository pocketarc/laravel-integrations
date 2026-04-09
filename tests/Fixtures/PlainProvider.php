<?php

declare(strict_types=1);

namespace Integrations\Tests\Fixtures;

use Integrations\Contracts\IntegrationProvider;

class PlainProvider implements IntegrationProvider
{
    public function name(): string
    {
        return 'Plain Provider';
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
