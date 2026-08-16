<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit\Commands;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Integrations\Events\SyncBecameStale;
use Integrations\Events\SyncStalenessRecovered;
use Integrations\IntegrationManager;
use Integrations\Jobs\SyncIntegration;
use Integrations\Models\Integration;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;

class SyncCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $manager = app(IntegrationManager::class);
        $manager->register('test', TestProvider::class);
    }

    public function test_dispatches_sync_for_due_integrations(): void
    {
        Queue::fake();

        Integration::create([
            'provider' => 'test',
            'name' => 'Due',
            'sync_interval_minutes' => 15,
            'next_sync_at' => now()->subMinute(),
        ]);

        $this->artisan('integrations:sync')->assertSuccessful();

        Queue::assertPushed(SyncIntegration::class);
    }

    public function test_skips_integrations_not_due(): void
    {
        Queue::fake();

        Integration::create([
            'provider' => 'test',
            'name' => 'Not Due',
            'sync_interval_minutes' => 15,
            'next_sync_at' => now()->addHour(),
        ]);

        $this->artisan('integrations:sync')->assertSuccessful();

        Queue::assertNotPushed(SyncIntegration::class);
    }

    public function test_applies_health_backoff_for_failing_integrations(): void
    {
        Queue::fake();

        Integration::create([
            'provider' => 'test',
            'name' => 'Failing',
            'sync_interval_minutes' => 15,
            'next_sync_at' => now()->subMinute(),
            'health_status' => 'failing',
            'consecutive_failures' => 25,
            'last_synced_at' => now()->subMinutes(20),
        ]);

        $this->artisan('integrations:sync')->assertSuccessful();

        // 15 min interval * 10x backoff = 150 min. Last synced 20 min ago. Should skip.
        Queue::assertNotPushed(SyncIntegration::class);
    }

    public function test_dispatches_with_configured_job_timeout(): void
    {
        Queue::fake();
        config()->set('integrations.sync.job_timeout', 1234);

        Integration::create([
            'provider' => 'test',
            'name' => 'Due',
            'sync_interval_minutes' => 15,
            'next_sync_at' => now()->subMinute(),
        ]);

        $this->artisan('integrations:sync')->assertSuccessful();

        Queue::assertPushed(SyncIntegration::class, fn (SyncIntegration $job) => $job->timeout === 1234);
    }

    public function test_announces_a_stale_integration_exactly_once(): void
    {
        Queue::fake();
        Event::fake([SyncBecameStale::class]);

        $stale = $this->staleIntegration();

        $this->artisan('integrations:sync')->assertSuccessful();
        $this->artisan('integrations:sync')->assertSuccessful();

        Event::assertDispatchedTimes(SyncBecameStale::class, 1);
        $this->assertNotNull($stale->refresh()->sync_stale_alerted_at);
    }

    public function test_announces_recovery_once_and_re_arms(): void
    {
        Queue::fake();
        Event::fake([SyncBecameStale::class, SyncStalenessRecovered::class]);

        $stale = $this->staleIntegration();
        $this->artisan('integrations:sync')->assertSuccessful();

        $stale->markSynced(now());
        $this->artisan('integrations:sync')->assertSuccessful();
        $this->artisan('integrations:sync')->assertSuccessful();

        Event::assertDispatchedTimes(SyncStalenessRecovered::class, 1);
        $this->assertNull($stale->refresh()->sync_stale_alerted_at);
    }

    public function test_evaluates_staleness_for_an_integration_that_is_not_due(): void
    {
        Queue::fake();
        Event::fake([SyncBecameStale::class]);

        $stale = $this->staleIntegration();
        $stale->update(['next_sync_at' => now()->addDay()]);

        $this->artisan('integrations:sync')->assertSuccessful();

        Event::assertDispatched(SyncBecameStale::class);
    }

    private function staleIntegration(): Integration
    {
        return Integration::create([
            'provider' => 'test',
            'name' => 'Stale',
            'sync_interval_minutes' => 15,
            'last_synced_at' => now()->subDays(12),
            'next_sync_at' => now()->subMinute(),
        ]);
    }
}
