<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit\Commands;

use Carbon\CarbonInterface;
use Integrations\Enums\HealthStatus;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationIncident;
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

    public function test_prunes_old_closed_incidents_but_keeps_open_ones(): void
    {
        $integration = Integration::create(['provider' => 'test', 'name' => 'Test']);

        $oldClosed = $this->makeIncident($integration, IntegrationIncident::STATUS_CLOSED, closedAt: now()->subDays(400));
        $recentClosed = $this->makeIncident($integration, IntegrationIncident::STATUS_CLOSED, closedAt: now()->subDays(10));
        $open = $this->makeIncident($integration, IntegrationIncident::STATUS_OPEN, openedAt: now()->subDays(400));

        $this->artisan('integrations:prune')->assertSuccessful();

        $this->assertDatabaseMissing('integration_incidents', ['id' => $oldClosed->id]);
        $this->assertDatabaseHas('integration_incidents', ['id' => $recentClosed->id]);
        // The open one is never deleted by retention; but see the stale auto-close below.
        $this->assertDatabaseHas('integration_incidents', ['id' => $open->id]);
    }

    public function test_auto_closes_stale_open_incidents_for_healthy_integrations(): void
    {
        $healthy = Integration::create(['provider' => 'test', 'name' => 'Healthy', 'health_status' => HealthStatus::Healthy]);
        $degraded = Integration::create(['provider' => 'test', 'name' => 'Degraded', 'health_status' => HealthStatus::Degraded]);

        $staleHealthy = $this->makeIncident($healthy, IntegrationIncident::STATUS_OPEN, openedAt: now()->subDays(30));
        $staleDegraded = $this->makeIncident($degraded, IntegrationIncident::STATUS_OPEN, openedAt: now()->subDays(30));
        $freshHealthy = $this->makeIncident($healthy, IntegrationIncident::STATUS_OPEN, openedAt: now()->subDay());

        $this->artisan('integrations:prune')->assertSuccessful();

        // Stale + healthy → auto-closed.
        $this->assertSame(IntegrationIncident::STATUS_CLOSED, $staleHealthy->refresh()->status);
        // Stale but still unhealthy → left open.
        $this->assertSame(IntegrationIncident::STATUS_OPEN, $staleDegraded->refresh()->status);
        // Recent → left open even though healthy.
        $this->assertSame(IntegrationIncident::STATUS_OPEN, $freshHealthy->refresh()->status);
    }

    private function makeIncident(
        Integration $integration,
        string $status,
        ?CarbonInterface $openedAt = null,
        ?CarbonInterface $closedAt = null,
    ): IntegrationIncident {
        return IntegrationIncident::create([
            'integration_id' => $integration->id,
            'status' => $status,
            'source' => IntegrationIncident::SOURCE_HEALTH,
            'reason' => 'health_degraded',
            'peak_severity' => HealthStatus::Degraded,
            'opened_at' => $openedAt ?? now(),
            'closed_at' => $closedAt,
        ]);
    }
}
