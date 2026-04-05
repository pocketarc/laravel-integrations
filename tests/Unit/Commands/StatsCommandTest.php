<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit\Commands;

use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\Tests\Fixtures\TestOkResponse;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;

class StatsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(IntegrationManager::class)->register('test', TestProvider::class);
    }

    public function test_stats_command_shows_metrics(): void
    {
        $integration = Integration::create(['provider' => 'test', 'name' => 'Stats Test']);
        $integration->refresh();

        $integration->requestAs(
            endpoint: '/api/success',
            method: 'GET',
            responseClass: TestOkResponse::class,
            callback: fn () => ['ok' => true],
        );

        $this->artisan('integrations:stats')
            ->assertSuccessful()
            ->expectsOutputToContain('Stats Test');
    }

    public function test_stats_command_with_no_integrations(): void
    {
        $this->artisan('integrations:stats')
            ->assertSuccessful()
            ->expectsOutputToContain('No integrations');
    }
}
