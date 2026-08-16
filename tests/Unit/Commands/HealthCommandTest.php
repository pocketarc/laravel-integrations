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

    public function test_escapes_console_formatting_in_provider_supplied_output(): void
    {
        Integration::create([
            'provider' => 'test',
            'name' => 'Acme <fg=red>Corp</>',
            'health_status' => HealthStatus::Healthy,
        ]);

        // The markup must survive verbatim rather than being parsed as a style
        // tag and stripped (which would leave "Acme Corp").
        $this->artisan('integrations:health')
            ->assertSuccessful()
            ->expectsOutputToContain('Acme <fg=red>Corp</>');
    }

    public function test_flags_a_stale_integration_while_health_stays_green(): void
    {
        Integration::create([
            'provider' => 'test',
            'name' => 'Wedged',
            'health_status' => HealthStatus::Healthy,
            'sync_interval_minutes' => 15,
            'last_synced_at' => now()->subDays(12),
        ]);

        $this->artisan('integrations:health')
            ->assertSuccessful()
            ->expectsOutputToContain('STALE');
    }

    public function test_shows_a_scheduled_integration_as_on_schedule(): void
    {
        Integration::create([
            'provider' => 'test',
            'name' => 'Fine',
            'health_status' => HealthStatus::Healthy,
            'sync_interval_minutes' => 15,
            'last_synced_at' => now()->subMinutes(5),
        ]);

        $this->artisan('integrations:health')
            ->assertSuccessful()
            ->expectsOutputToContain('on schedule');
    }

    public function test_shows_an_unscheduled_integration_as_not_scheduled(): void
    {
        Integration::create([
            'provider' => 'test',
            'name' => 'Manual',
            'health_status' => HealthStatus::Healthy,
        ]);

        $this->artisan('integrations:health')
            ->assertSuccessful()
            ->expectsOutputToContain('not scheduled');
    }

    public function test_empty_state(): void
    {
        $this->artisan('integrations:health')
            ->assertSuccessful()
            ->expectsOutputToContain('No integrations registered');
    }
}
