<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit\Commands;

use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;

class TestCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $manager = app(IntegrationManager::class);
        $manager->register('test', TestProvider::class);
    }

    public function test_passes_healthy_integration(): void
    {
        Integration::create(['provider' => 'test', 'name' => 'Healthy']);

        $this->artisan('integrations:test')
            ->assertSuccessful()
            ->expectsOutputToContain('PASS');
    }

    public function test_fails_unhealthy_integration(): void
    {
        Integration::create(['provider' => 'test', 'name' => 'Unhealthy']);

        $provider = new TestProvider;
        $provider->healthCheckResult = false;
        $this->app->instance(TestProvider::class, $provider);

        $this->artisan('integrations:test')
            ->assertFailed()
            ->expectsOutputToContain('FAIL');
    }

    public function test_empty_state(): void
    {
        $this->artisan('integrations:test')
            ->assertSuccessful()
            ->expectsOutputToContain('No integrations with health check support found');
    }
}
