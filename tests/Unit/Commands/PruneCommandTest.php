<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit\Commands;

use Integrations\Models\Integration;
use Integrations\Models\IntegrationLog;
use Integrations\Models\IntegrationRequest;
use Integrations\Tests\TestCase;

class PruneCommandTest extends TestCase
{
    public function test_prunes_old_requests(): void
    {
        $integration = Integration::create(['provider' => 'test', 'name' => 'Test']);

        IntegrationRequest::create([
            'integration_id' => $integration->id,
            'endpoint' => '/old',
            'method' => 'GET',
            'created_at' => now()->subDays(100),
        ]);

        IntegrationRequest::create([
            'integration_id' => $integration->id,
            'endpoint' => '/recent',
            'method' => 'GET',
            'created_at' => now()->subDays(10),
        ]);

        $this->artisan('integrations:prune')
            ->assertSuccessful();

        $this->assertDatabaseCount('integration_requests', 1);
        $this->assertDatabaseHas('integration_requests', ['endpoint' => '/recent']);
    }

    public function test_prunes_old_logs(): void
    {
        $integration = Integration::create(['provider' => 'test', 'name' => 'Test']);

        IntegrationLog::create([
            'integration_id' => $integration->id,
            'operation' => 'sync',
            'direction' => 'inbound',
            'status' => 'success',
            'created_at' => now()->subDays(400),
        ]);

        $recentLog = IntegrationLog::create([
            'integration_id' => $integration->id,
            'operation' => 'sync',
            'direction' => 'inbound',
            'status' => 'success',
            'created_at' => now()->subDays(100),
        ]);

        $this->artisan('integrations:prune')
            ->assertSuccessful();

        $this->assertDatabaseCount('integration_logs', 1);
        $this->assertDatabaseHas('integration_logs', ['id' => $recentLog->id]);
    }
}
