<?php

declare(strict_types=1);

namespace Integrations\Facades;

use Illuminate\Support\Facades\Facade;
use Integrations\IntegrationManager;

/**
 * @method static void register(string $key, class-string<\Integrations\Contracts\IntegrationProvider> $providerClass)
 * @method static \Integrations\Contracts\IntegrationProvider provider(string $key)
 * @method static bool has(string $key)
 * @method static list<string> registered()
 */
class Integrations extends Facade
{
    #[\Override]
    protected static function getFacadeAccessor(): string
    {
        return IntegrationManager::class;
    }
}
