<?php

declare(strict_types=1);

namespace Integrations\Tests\Fixtures;

use Integrations\Contracts\IntegrationProvider;
use Integrations\Contracts\LimitsRequestLogging;

class QuietLoggingTestProvider implements IntegrationProvider, LimitsRequestLogging
{
    public function name(): string
    {
        return 'Quiet Logging Provider';
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

    public function unloggedResponseEndpoints(): array
    {
        return ['chat/*', 'POST:embeddings'];
    }
}
