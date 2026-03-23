<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Integrations\IntegrationManager;
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
}
