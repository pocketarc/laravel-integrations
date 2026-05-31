<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit\Commands;

use Integrations\Enums\HealthStatus;
use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\Tests\Fixtures\IdentifyingProvider;
use Integrations\Tests\TestCase;

class HealthCommandTest extends TestCase
{
    public function test_shows_health_report(): void
    {
        Integration::create([
            'provider' => 'test',
            'name' => 'Healthy One',
            'health_status' => HealthStatus::Healthy,
        ]);

        Integration::create([
            'provider' => 'test',
            'name' => 'Degraded One',
            'health_status' => HealthStatus::Degraded,
            'consecutive_failures' => 7,
        ]);

        $this->artisan('integrations:health')
            ->assertSuccessful()
            ->expectsOutputToContain('Healthy One')
            ->expectsOutputToContain('Degraded One')
            ->expectsOutputToContain('healthy')
            ->expectsOutputToContain('degraded');
    }

    public function test_shows_authenticated_identity_when_supported(): void
    {
        app(IntegrationManager::class)->register('identifying', IdentifyingProvider::class);

        Integration::create([
            'provider' => 'identifying',
            'name' => 'Identified One',
            'health_status' => HealthStatus::Healthy,
        ]);

        $this->artisan('integrations:health')
            ->assertSuccessful()
            ->expectsOutputToContain('Authenticated as: octocat (id: u-1)');
    }

    public function test_empty_state(): void
    {
        $this->artisan('integrations:health')
            ->assertSuccessful()
            ->expectsOutputToContain('No integrations registered');
    }
}
