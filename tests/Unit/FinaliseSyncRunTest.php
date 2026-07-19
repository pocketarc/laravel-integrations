<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Integrations\Events\SyncCompleted;
use Integrations\IntegrationManager;
use Integrations\Jobs\FinaliseSyncRun;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationLog;
use Integrations\Models\IntegrationSyncItem;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\Fixtures\TestSyncItemEvent;
use Integrations\Tests\TestCase;

class FinaliseSyncRunTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(IntegrationManager::class)->register('test', TestProvider::class);
    }

    public function test_advances_cursor_and_finalises_log_when_all_items_succeed(): void
    {
        $integration = $this->makeIntegration();
        $log = $this->openSyncLog($integration);

        $this->makeItem($integration, $log, IntegrationSyncItem::STATUS_SUCCESS, '2026-01-01T10:00:00+00:00');
        $this->makeItem($integration, $log, IntegrationSyncItem::STATUS_SUCCESS, '2026-01-01T12:00:00+00:00');

        (new FinaliseSyncRun($integration->id, $log->id))->handle();

        $integration->refresh();
        $log->refresh();

        $this->assertSame('2026-01-01T12:00:00+00:00', $integration->sync_cursor);
        $this->assertNotNull($integration->last_synced_at);
        $this->assertSame('success', $log->status);
        $this->assertSame(2, $log->metadata['success_count'] ?? null);
        $this->assertSame(0, $log->metadata['failure_count'] ?? null);
    }

    public function test_does_not_advance_cursor_when_an_item_failed(): void
    {
        $integration = $this->makeIntegration(cursor: '2026-01-01T00:00:00+00:00');
        $log = $this->openSyncLog($integration);

        $this->makeItem($integration, $log, IntegrationSyncItem::STATUS_SUCCESS, '2026-01-01T10:00:00+00:00');
        $this->makeItem($integration, $log, IntegrationSyncItem::STATUS_FAILED, '2026-01-01T12:00:00+00:00');

        (new FinaliseSyncRun($integration->id, $log->id))->handle();

        $integration->refresh();
        $log->refresh();

        $this->assertSame('2026-01-01T00:00:00+00:00', $integration->sync_cursor);
        $this->assertSame('partial', $log->status);
    }

    public function test_marks_log_failed_when_every_item_failed(): void
    {
        $integration = $this->makeIntegration();
        $log = $this->openSyncLog($integration);

        $this->makeItem($integration, $log, IntegrationSyncItem::STATUS_FAILED, '2026-01-01T10:00:00+00:00');

        (new FinaliseSyncRun($integration->id, $log->id))->handle();

        $log->refresh();
        $this->assertSame('failed', $log->status);
    }

    public function test_skipped_items_count_as_completed(): void
    {
        $integration = $this->makeIntegration();
        $log = $this->openSyncLog($integration);

        $this->makeItem($integration, $log, IntegrationSyncItem::STATUS_SUCCESS, '2026-01-01T10:00:00+00:00');
        $this->makeItem($integration, $log, IntegrationSyncItem::STATUS_SKIPPED, '2026-01-01T12:00:00+00:00');

        (new FinaliseSyncRun($integration->id, $log->id))->handle();

        $integration->refresh();
        $log->refresh();

        // A skipped item still counts toward "completed", so the cursor moves
        // past it and the run is a clean success.
        $this->assertSame('2026-01-01T12:00:00+00:00', $integration->sync_cursor);
        $this->assertSame('success', $log->status);
    }

    public function test_cursor_advance_is_monotonic(): void
    {
        $integration = $this->makeIntegration(cursor: '2026-06-01T00:00:00+00:00');
        $log = $this->openSyncLog($integration);

        // An item whose checkpoint is behind the current cursor (e.g. a later
        // batch already advanced past it). The cursor must not move backward.
        $this->makeItem($integration, $log, IntegrationSyncItem::STATUS_SUCCESS, '2026-01-01T10:00:00+00:00');

        (new FinaliseSyncRun($integration->id, $log->id))->handle();

        $integration->refresh();
        $this->assertSame('2026-06-01T00:00:00+00:00', $integration->sync_cursor);
    }

    public function test_bails_while_items_are_still_in_flight(): void
    {
        $integration = $this->makeIntegration();
        $log = $this->openSyncLog($integration);

        $this->makeItem($integration, $log, IntegrationSyncItem::STATUS_SUCCESS, '2026-01-01T10:00:00+00:00');
        $this->makeItem($integration, $log, IntegrationSyncItem::STATUS_PENDING, '2026-01-01T12:00:00+00:00');

        (new FinaliseSyncRun($integration->id, $log->id))->handle();

        $integration->refresh();
        $log->refresh();

        $this->assertNull($integration->sync_cursor);
        $this->assertSame('processing', $log->status);
    }

    public function test_fires_sync_completed_exactly_once_across_repeated_runs(): void
    {
        Event::fake([SyncCompleted::class]);

        $integration = $this->makeIntegration();
        $log = $this->openSyncLog($integration);
        $this->makeItem($integration, $log, IntegrationSyncItem::STATUS_SUCCESS, '2026-01-01T10:00:00+00:00');

        (new FinaliseSyncRun($integration->id, $log->id))->handle();
        (new FinaliseSyncRun($integration->id, $log->id))->handle();

        Event::assertDispatchedTimes(SyncCompleted::class, 1);
    }

    public function test_retry_catchup_advances_cursor_once_the_failed_item_resolves(): void
    {
        $integration = $this->makeIntegration(cursor: '2026-01-01T00:00:00+00:00');
        $log = $this->openSyncLog($integration);

        $success = $this->makeItem($integration, $log, IntegrationSyncItem::STATUS_SUCCESS, '2026-01-01T10:00:00+00:00');
        $failed = $this->makeItem($integration, $log, IntegrationSyncItem::STATUS_FAILED, '2026-01-01T12:00:00+00:00');

        // First reconcile: a failure is present, so the cursor stays put.
        (new FinaliseSyncRun($integration->id, $log->id))->handle();
        $integration->refresh();
        $this->assertSame('2026-01-01T00:00:00+00:00', $integration->sync_cursor);

        // Operator retries the failed item; it now succeeds.
        $failed->update(['status' => IntegrationSyncItem::STATUS_SUCCESS]);

        // Second reconcile: every item is now terminal-success, cursor catches up.
        (new FinaliseSyncRun($integration->id, $log->id))->handle();
        $integration->refresh();
        $this->assertSame('2026-01-01T12:00:00+00:00', $integration->sync_cursor);

        $this->assertSame(IntegrationSyncItem::STATUS_SUCCESS, $success->refresh()->status);
    }

    public function test_dispatch_for_rides_the_item_queue(): void
    {
        // Reconciliation must share the item jobs' queue: on the default queue
        // it once sat behind an unrelated backlog for days, parking cursors.
        config(['integrations.sync.queue' => 'integrations-sync']);
        Queue::fake();

        FinaliseSyncRun::dispatchFor(1, 2);

        Queue::assertPushedOn('integrations-sync', FinaliseSyncRun::class);
    }

    public function test_dispatch_for_honours_a_per_provider_queue(): void
    {
        config(['integrations.sync.queues' => ['test' => 'test-sync']]);
        Queue::fake();

        FinaliseSyncRun::dispatchFor(1, 2, 'test');

        Queue::assertPushedOn('test-sync', FinaliseSyncRun::class);
    }

    private function makeIntegration(mixed $cursor = null): Integration
    {
        return Integration::create([
            'provider' => 'test',
            'name' => 'Test',
            'sync_interval_minutes' => 15,
            'sync_cursor' => $cursor,
        ]);
    }

    private function openSyncLog(Integration $integration): IntegrationLog
    {
        return $integration->logOperation(
            operation: 'sync',
            direction: 'inbound',
            status: 'processing',
        );
    }

    private function makeItem(Integration $integration, IntegrationLog $log, string $status, mixed $checkpoint): IntegrationSyncItem
    {
        return IntegrationSyncItem::create([
            'integration_id' => $integration->id,
            'sync_log_id' => $log->id,
            'event_class' => TestSyncItemEvent::class,
            'checkpoint_value' => $checkpoint,
            'status' => $status,
            'attempts' => 1,
        ]);
    }
}
