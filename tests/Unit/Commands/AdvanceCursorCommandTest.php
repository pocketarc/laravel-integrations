<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit\Commands;

use Illuminate\Support\Facades\Queue;
use Integrations\IntegrationManager;
use Integrations\Jobs\FinaliseSyncRun;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationLog;
use Integrations\Models\IntegrationSyncItem;
use Integrations\Support\Config;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\Fixtures\TestSyncItemEvent;
use Integrations\Tests\TestCase;

class AdvanceCursorCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(IntegrationManager::class)->register('test', TestProvider::class);
    }

    public function test_rejects_a_non_numeric_integration_argument(): void
    {
        $this->artisan('integrations:advance-cursor', ['integration' => 'abc'])
            ->assertFailed()
            ->expectsOutputToContain('must be a positive integer id');
    }

    public function test_reports_when_there_is_nothing_to_reconcile(): void
    {
        $integration = $this->makeIntegration();

        $this->artisan('integrations:advance-cursor', ['integration' => (string) $integration->id])
            ->assertSuccessful()
            ->expectsOutputToContain('No unreconciled sync runs');
    }

    public function test_reclaim_stale_marks_abandoned_items_failed(): void
    {
        Queue::fake();

        $integration = $this->makeIntegration();
        $log = $this->openSyncLog($integration);
        $item = $this->abandonedItem($integration, $log);

        $this->artisan('integrations:advance-cursor', [
            'integration' => (string) $integration->id,
            '--reclaim-stale' => true,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('Reclaimed abandoned items across 1 sync run(s).');

        $this->assertSame(IntegrationSyncItem::STATUS_FAILED, $item->refresh()->status);
        Queue::assertPushed(FinaliseSyncRun::class);
    }

    public function test_leaves_in_flight_items_alone_without_the_flag(): void
    {
        Queue::fake();

        $integration = $this->makeIntegration();
        $log = $this->openSyncLog($integration);
        $item = $this->abandonedItem($integration, $log);

        $this->artisan('integrations:advance-cursor', ['integration' => (string) $integration->id])
            ->assertSuccessful();

        $this->assertSame(IntegrationSyncItem::STATUS_PENDING, $item->refresh()->status);
    }

    public function test_reports_when_there_was_nothing_to_reclaim(): void
    {
        $integration = $this->makeIntegration();

        $this->artisan('integrations:advance-cursor', [
            'integration' => (string) $integration->id,
            '--reclaim-stale' => true,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('No abandoned sync items to reclaim.');
    }

    private function makeIntegration(): Integration
    {
        return Integration::create([
            'provider' => 'test',
            'name' => 'Test',
            'sync_interval_minutes' => 15,
        ]);
    }

    private function openSyncLog(Integration $integration): IntegrationLog
    {
        return $integration->logOperation(operation: 'sync', direction: 'inbound', status: 'processing');
    }

    private function abandonedItem(Integration $integration, IntegrationLog $log): IntegrationSyncItem
    {
        $item = IntegrationSyncItem::create([
            'integration_id' => $integration->id,
            'sync_log_id' => $log->id,
            'batch_id' => 'batch-gone',
            'event_class' => TestSyncItemEvent::class,
            'checkpoint_value' => '2026-01-01T10:00:00+00:00',
            'status' => IntegrationSyncItem::STATUS_PENDING,
            'attempts' => 0,
        ]);

        IntegrationSyncItem::query()
            ->whereKey($item->getKey())
            ->update(['created_at' => now()->subSeconds(Config::syncItemReclaimAfter() + 60)]);

        return $item;
    }
}
