<?php

declare(strict_types=1);

namespace Integrations\Testing;

use Integrations\Contracts\IntegrationProvider;
use Integrations\IntegrationManager;
use Integrations\Models\Integration;

trait CreatesIntegration
{
    /**
     * Register a provider and create an Integration model for testing.
     *
     * @param  class-string<IntegrationProvider>  $providerClass
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $attributes  Extra Integration attributes to set on the model.
     */
    protected function createIntegration(
        string $providerKey,
        string $providerClass,
        array $credentials = [],
        array $metadata = [],
        array $attributes = [],
    ): Integration {
        $manager = app(IntegrationManager::class);

        if (! $manager->has($providerKey)) {
            $manager->register($providerKey, $providerClass);
        }

        $integration = Integration::create([
            'provider' => $providerKey,
            'name' => "Test {$providerKey}",
            'credentials' => $credentials !== [] ? $credentials : null,
            'metadata' => $metadata !== [] ? $metadata : null,
            'is_active' => true,
            'health_status' => 'healthy',
            'consecutive_failures' => 0,
        ]);

        // forceFill keeps the helper's free-form $attributes off create()'s
        // typed attribute list; Integration is $guarded = [], so this sets
        // the same attributes create() would have.
        if ($attributes !== []) {
            $integration->forceFill($attributes)->save();
        }

        return $integration->refresh();
    }
}
