<?php

declare(strict_types=1);

namespace Integrations\Tests\Fixtures;

use Integrations\Contracts\IntegrationProvider;

class TestDataProvider implements IntegrationProvider
{
    public function name(): string
    {
        return 'Test Data Provider';
    }

    public function credentialRules(): array
    {
        return ['api_key' => 'required|string'];
    }

    public function metadataRules(): array
    {
        return ['region' => 'required|string'];
    }

    public function credentialDataClass(): ?string
    {
        return TestCredentials::class;
    }

    public function metadataDataClass(): ?string
    {
        return TestMetadata::class;
    }
}
