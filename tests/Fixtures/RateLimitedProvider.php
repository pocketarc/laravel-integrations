<?php

declare(strict_types=1);

namespace Integrations\Tests\Fixtures;

use Integrations\Contracts\DeclaresRateLimit;
use Integrations\Contracts\IntegrationProvider;
use Integrations\RateLimit;

/**
 * A request-only provider: it declares a rate budget via DeclaresRateLimit
 * without implementing HasScheduledSync, mirroring an LLM-gateway style
 * upstream that has nothing to sync but a real per-minute budget.
 */
class RateLimitedProvider implements DeclaresRateLimit, IntegrationProvider
{
    public function name(): string
    {
        return 'Rate Limited Provider';
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

    public function defaultRateLimit(): ?RateLimit
    {
        return RateLimit::perMinute(50)->sliding();
    }
}
