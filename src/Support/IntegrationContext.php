<?php

declare(strict_types=1);

namespace Integrations\Support;

use Illuminate\Support\Facades\Log;
use Integrations\Models\Integration;

class IntegrationContext
{
    public static function push(Integration $integration, ?string $operation = null): void
    {
        $context = [
            'integration_id' => $integration->id,
            'integration_provider' => $integration->provider,
            'integration_name' => $integration->name,
        ];

        if ($operation !== null) {
            $context['integration_operation'] = $operation;
        }

        Log::shareContext($context);
    }

    public static function clear(): void
    {
        Log::flushSharedContext();
    }
}
