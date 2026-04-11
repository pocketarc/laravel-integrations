<?php

declare(strict_types=1);

namespace Integrations;

use Illuminate\Contracts\Container\Container;
use Integrations\Contracts\IntegrationProvider;
use InvalidArgumentException;
use Spatie\LaravelData\Data;

class IntegrationManager
{
    /** @var array<string, class-string<IntegrationProvider>> */
    private array $providers = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * Register default providers that companion packages ship out of the box.
     *
     * Merges the given providers into config('integrations.providers') without
     * overriding entries the user has already defined. Call this from a companion
     * package's service provider register() method.
     *
     * @param  array<string, class-string<IntegrationProvider>>  $providers
     */
    public static function registerDefaults(array $providers): void
    {
        $existing = config('integrations.providers', []);

        if (! is_array($existing)) {
            $existing = [];
        }

        foreach ($providers as $key => $class) {
            $existing[$key] ??= $class;
        }

        config(['integrations.providers' => $existing]);
    }

    /**
     * Register a provider class for the given key.
     *
     * @param  class-string<IntegrationProvider>  $providerClass
     */
    public function register(string $key, string $providerClass): void
    {
        $this->providers[$key] = $providerClass;
    }

    /**
     * Resolve a provider instance by key.
     */
    public function provider(string $key): IntegrationProvider
    {
        if (! array_key_exists($key, $this->providers)) {
            throw new InvalidArgumentException("Integration provider '{$key}' is not registered.");
        }

        $provider = $this->container->make($this->providers[$key]);

        if (! $provider instanceof IntegrationProvider) {
            throw new InvalidArgumentException("Resolved class for provider '{$key}' does not implement ".IntegrationProvider::class.'.');
        }

        return $provider;
    }

    /**
     * Check if a provider is registered.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->providers);
    }

    /**
     * Resolve the credential Data class for a provider, or null if unregistered / none declared.
     *
     * @return class-string<Data>|null
     */
    public function resolveCredentialDataClass(string $provider): ?string
    {
        if (! $this->has($provider)) {
            return null;
        }

        return $this->provider($provider)->credentialDataClass();
    }

    /**
     * Resolve the metadata Data class for a provider, or null if unregistered / none declared.
     *
     * @return class-string<Data>|null
     */
    public function resolveMetadataDataClass(string $provider): ?string
    {
        if (! $this->has($provider)) {
            return null;
        }

        return $this->provider($provider)->metadataDataClass();
    }

    /**
     * Get all registered provider keys.
     *
     * @return list<string>
     */
    public function registered(): array
    {
        return array_keys($this->providers);
    }
}
