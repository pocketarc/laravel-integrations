<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Illuminate\Support\Carbon;
use Integrations\Enums\FailureClass;
use Integrations\Enums\HealthStatus;
use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;

class SyncStalenessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(IntegrationManager::class)->register('test', TestProvider::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_an_integration_past_the_threshold_is_stale(): void
    {
        // Default threshold is ten intervals: fifteen minutes * 10 = 2.5 hours.
        $integration = $this->makeIntegration(lastSyncedAt: now()->subHours(3));

        $this->assertTrue($integration->isSyncStale());
    }

    public function test_an_integration_inside_the_threshold_is_not_stale(): void
    {
        $integration = $this->makeIntegration(lastSyncedAt: now()->subHours(2));

        $this->assertFalse($integration->isSyncStale());
    }

    public function test_a_never_synced_integration_is_measured_from_creation(): void
    {
        Carbon::setTestNow('2026-01-01 12:00:00');
        $fresh = $this->makeIntegration();

        $this->assertFalse($fresh->isSyncStale());

        Carbon::setTestNow('2026-01-01 18:00:00');

        $this->assertTrue($fresh->refresh()->isSyncStale());
    }

    public function test_an_unscheduled_integration_is_never_stale(): void
    {
        $integration = $this->makeIntegration(lastSyncedAt: now()->subYear());
        $integration->update(['sync_interval_minutes' => null]);

        $this->assertFalse($integration->isSyncStale());
        $this->assertNull($integration->syncStaleness());
    }

    public function test_an_inactive_integration_is_never_stale(): void
    {
        $integration = $this->makeIntegration(lastSyncedAt: now()->subYear());
        $integration->update(['is_active' => false]);

        $this->assertFalse($integration->isSyncStale());
    }

    public function test_the_threshold_is_configurable(): void
    {
        config(['integrations.sync.stale_after_intervals' => 2]);

        $integration = $this->makeIntegration(lastSyncedAt: now()->subMinutes(45));

        $this->assertTrue($integration->isSyncStale());
    }

    public function test_sync_staleness_reports_seconds_since_the_last_clean_sync(): void
    {
        // Frozen: the fixture and syncStaleness() each call now(), so a second
        // boundary between them would make this 7201.
        Carbon::setTestNow('2026-01-01 12:00:00');

        $integration = $this->makeIntegration(lastSyncedAt: now()->subHours(2));

        $this->assertSame(7200, $integration->syncStaleness());
    }

    public function test_a_wedged_integration_goes_stale_while_health_stays_healthy(): void
    {
        $integration = $this->makeIntegration(lastSyncedAt: now()->subDays(12));

        $integration->recordFailure(FailureClass::Upstream);
        $integration->recordSuccess();

        $this->assertSame(HealthStatus::Healthy, $integration->health_status);
        $this->assertSame(0, $integration->consecutive_failures);
        $this->assertTrue($integration->isSyncStale());
    }

    private function makeIntegration(?Carbon $lastSyncedAt = null): Integration
    {
        return Integration::create([
            'provider' => 'test',
            'name' => 'Test',
            'sync_interval_minutes' => 15,
            'last_synced_at' => $lastSyncedAt,
        ]);
    }
}
