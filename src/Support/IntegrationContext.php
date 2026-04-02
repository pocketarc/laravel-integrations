<?php

declare(strict_types=1);

namespace Integrations\Support;

use Illuminate\Support\Facades\Log;
use Integrations\Models\Integration;

class IntegrationContext
{
    /** @var list<string> */
    private static array $addedKeys = [];

    public static function push(Integration $integration, ?string $operation = null): void
    {
        $context = [
            'integration_id' => $integration->id,
            'integration_provider' => $integration->provider,
            'integration_name' => $integration->name,
            'integration_operation' => $operation,
        ];

        self::$addedKeys = array_keys($context);

        Log::shareContext($context);
    }

    public static function clear(): void
    {
        $existing = Log::sharedContext();

        foreach (self::$addedKeys as $key) {
            unset($existing[$key]);
        }

        Log::flushSharedContext();

        if ($existing !== []) {
            Log::shareContext($existing);
        }

        self::$addedKeys = [];
    }
}
