<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Integrations\IntegrationManager;
use Integrations\Tests\Fixtures\PlainProvider;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;
use InvalidArgumentException;

class IntegrationManagerTest extends TestCase
{
    public function test_register_and_resolve(): void
    {
        $manager = app(IntegrationManager::class);
        $manager->register('test', TestProvider::class);

        $provider = $manager->provider('test');

        $this->assertInstanceOf(TestProvider::class, $provider);
        $this->assertSame('Test Provider', $provider->name());
    }

    public function test_throws_on_unknown_provider(): void
    {
        $manager = app(IntegrationManager::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Integration provider 'unknown' is not registered.");

        $manager->provider('unknown');
    }

    public function test_has(): void
    {
        $manager = app(IntegrationManager::class);
        $manager->register('test', TestProvider::class);

        $this->assertTrue($manager->has('test'));
        $this->assertFalse($manager->has('nope'));
    }

    public function test_registered(): void
    {
        $manager = app(IntegrationManager::class);
        $manager->register('alpha', TestProvider::class);
        $manager->register('beta', TestProvider::class);

        $this->assertSame(['alpha', 'beta'], $manager->registered());
    }

    public function test_register_defaults_adds_providers_to_config(): void
    {
        IntegrationManager::registerDefaults([
            'test' => TestProvider::class,
        ]);

        $providers = config('integrations.providers');
        $this->assertIsArray($providers);
        $this->assertSame(TestProvider::class, $providers['test']);
    }

    public function test_register_defaults_does_not_override_existing_config(): void
    {
        config(['integrations.providers' => ['test' => PlainProvider::class]]);

        IntegrationManager::registerDefaults([
            'test' => TestProvider::class,
        ]);

        $providers = config('integrations.providers');
        $this->assertIsArray($providers);
        $this->assertSame(PlainProvider::class, $providers['test']);
    }

    public function test_register_defaults_merges_with_existing_config(): void
    {
        config(['integrations.providers' => ['existing' => PlainProvider::class]]);

        IntegrationManager::registerDefaults([
            'existing' => TestProvider::class,
            'new' => TestProvider::class,
        ]);

        $providers = config('integrations.providers');
        $this->assertIsArray($providers);
        $this->assertSame(PlainProvider::class, $providers['existing']);
        $this->assertSame(TestProvider::class, $providers['new']);
    }

    public function test_register_defaults_providers_are_resolved_by_manager(): void
    {
        IntegrationManager::registerDefaults([
            'test' => TestProvider::class,
        ]);

        // Flush the scoped binding so it re-reads config.
        $this->app->forgetInstance(IntegrationManager::class);

        $manager = app(IntegrationManager::class);

        $this->assertTrue($manager->has('test'));
        $this->assertInstanceOf(TestProvider::class, $manager->provider('test'));
    }
}
