<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit\Commands;

use Integrations\Enums\HealthStatus;
use Integrations\Models\Integration;
use Integrations\Tests\TestCase;

class ListCommandTest extends TestCase
{
    public function test_lists_integrations(): void
    {
        Integration::create(['provider' => 'zendesk', 'name' => 'Prod ZD']);
        Integration::create(['provider' => 'github', 'name' => 'GitHub']);

        $this->artisan('integrations:list')
            ->assertSuccessful()
            ->expectsOutputToContain('Prod ZD')
            ->expectsOutputToContain('GitHub');
    }

    public function test_marks_a_stale_integration_in_the_sync_column(): void
    {
        Integration::create([
            'provider' => 'test',
            'name' => 'Wedged',
            'health_status' => HealthStatus::Healthy,
            'sync_interval_minutes' => 15,
            'last_synced_at' => now()->subDays(12),
        ]);

        $this->artisan('integrations:list')
            ->assertSuccessful()
            ->expectsOutputToContain('stale');
    }

    public function test_marks_a_healthy_sync_as_ok(): void
    {
        Integration::create([
            'provider' => 'test',
            'name' => 'Fine',
            'health_status' => HealthStatus::Healthy,
            'sync_interval_minutes' => 15,
            'last_synced_at' => now()->subMinutes(5),
        ]);

        $this->artisan('integrations:list')
            ->assertSuccessful()
            ->expectsOutputToContain('ok');
    }

    public function test_empty_state(): void
    {
        $this->artisan('integrations:list')
            ->assertSuccessful()
            ->expectsOutputToContain('No integrations registered');
    }
}
