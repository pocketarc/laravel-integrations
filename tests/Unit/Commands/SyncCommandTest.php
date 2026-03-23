<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit\Commands;

use Illuminate\Support\Facades\Queue;
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
}
