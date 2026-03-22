<?php

declare(strict_types=1);

namespace Integrations;

use Illuminate\Contracts\Container\Container;
use Integrations\Contracts\IntegrationProvider;
use InvalidArgumentException;

class IntegrationManager
{
    /** @var array<string, class-string<IntegrationProvider>> */
    private array $providers = [];

    public function __construct(
        private readonly Container $container,
    ) {}

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
        if (! isset($this->providers[$key])) {
            throw new InvalidArgumentException("Integration provider '{$key}' is not registered.");
        }

        /** @var IntegrationProvider */
        return $this->container->make($this->providers[$key]);
    }

    /**
     * Check if a provider is registered.
     */
    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
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
