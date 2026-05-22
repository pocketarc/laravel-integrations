<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Event;
use Integrations\Events\SyncItemFailed;
use Integrations\Exceptions\RateLimitExceededException;
use Integrations\IntegrationManager;
use Integrations\Jobs\ProcessSyncItem;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationSyncItem;
use Integrations\Tests\Fixtures\QueuedSyncListener;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\Fixtures\TestSyncItemEvent;
use Integrations\Tests\TestCase;
use RuntimeException;

class ProcessSyncItemTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(IntegrationManager::class)->register('test', TestProvider::class);
    }

    public function test_runs_the_listener_and_marks_the_item_success(): void
    {
        $ran = false;
        Event::listen(TestSyncItemEvent::class, function () use (&$ran): void {
            $ran = true;
        });

        $integration = $this->makeIntegration();
        $item = $this->makeItem($integration, IntegrationSyncItem::STATUS_PENDING);

        (new ProcessSyncItem($item->id, new TestSyncItemEvent($integration, 'item-1'), $this->logId))->handle();

        $this->assertTrue($ran);
        $item->refresh();
        $this->assertSame(IntegrationSyncItem::STATUS_SUCCESS, $item->status);
        $this->assertNotNull($item->completed_at);
    }

    public function test_listener_exception_propagates_so_the_queue_can_retry(): void
    {
        Event::listen(TestSyncItemEvent::class, function (): void {
            throw new RuntimeException('listener boom');
        });

        $integration = $this->makeIntegration();
        $item = $this->makeItem($integration, IntegrationSyncItem::STATUS_PENDING);

        $job = new ProcessSyncItem($item->id, new TestSyncItemEvent($integration, 'item-1'), $this->logId);

        try {
            $job->handle();
            $this->fail('Expected the listener exception to propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('listener boom', $e->getMessage());
        }

        // Left "processing": the queue retries the whole job, and only an
        // exhausted job calls failed() to mark the row "failed".
        $this->assertSame(IntegrationSyncItem::STATUS_PROCESSING, $item->refresh()->status);
    }

    public function test_failed_marks_the_row_and_fires_sync_item_failed(): void
    {
        Event::fake([SyncItemFailed::class]);

        $integration = $this->makeIntegration();
        $item = $this->makeItem($integration, IntegrationSyncItem::STATUS_PROCESSING);

        $job = new ProcessSyncItem($item->id, new TestSyncItemEvent($integration, 'item-1'), $this->logId);
        $job->failed(new RuntimeException('retries exhausted'));

        $item->refresh();
        $this->assertSame(IntegrationSyncItem::STATUS_FAILED, $item->status);
        $this->assertNotNull($item->error);
        $this->assertStringContainsString('retries exhausted', $item->error);

        Event::assertDispatched(SyncItemFailed::class);
    }

    public function test_rejects_a_queued_listener_without_running_any_listener(): void
    {
        $closureRan = false;
        Event::listen(TestSyncItemEvent::class, QueuedSyncListener::class);
        Event::listen(TestSyncItemEvent::class, function () use (&$closureRan): void {
            $closureRan = true;
        });

        $integration = $this->makeIntegration();
        $item = $this->makeItem($integration, IntegrationSyncItem::STATUS_PENDING);

        // fail() is a no-op outside a real queue context, so handle() just
        // returns. But it returns before event(), proving the queued
        // listener was detected and the run was rejected rather than
        // reporting false success.
        (new ProcessSyncItem($item->id, new TestSyncItemEvent($integration, 'item-1'), $this->logId))->handle();

        $this->assertFalse($closureRan);
        $this->assertSame(IntegrationSyncItem::STATUS_PENDING, $item->refresh()->status);
    }

    public function test_rate_limit_from_a_listener_defers_the_item_instead_of_failing_it(): void
    {
        $integration = $this->makeIntegration();

        Event::listen(TestSyncItemEvent::class, function () use ($integration): never {
            throw new RateLimitExceededException($integration, 90);
        });

        $item = $this->makeItem($integration, IntegrationSyncItem::STATUS_PENDING);

        // The job releases itself back onto the queue with the exception's
        // retry-after delay (90s) instead of failing the item.
        $queueJob = $this->createMock(Job::class);
        $queueJob->method('attempts')->willReturn(1);
        $queueJob->expects($this->once())->method('release')->with(90);

        $job = new ProcessSyncItem($item->id, new TestSyncItemEvent($integration, 'item-1'), $this->logId);
        $job->setJob($queueJob);
        $job->handle();

        $item->refresh();
        $this->assertSame(IntegrationSyncItem::STATUS_PROCESSING, $item->status);
        $this->assertNull($item->completed_at);
        $this->assertNull($item->error);
    }

    public function test_max_exceptions_matches_the_configured_item_tries(): void
    {
        config(['integrations.sync.item_tries' => 7]);

        $integration = $this->makeIntegration();
        $job = new ProcessSyncItem(1, new TestSyncItemEvent($integration, 'item-1'), $this->logId);

        $this->assertSame(7, $job->maxExceptions);
    }

    public function test_retry_until_reflects_the_configured_retry_window(): void
    {
        config(['integrations.sync.item_retry_window' => 1800]);

        $integration = $this->makeIntegration();
        $before = now()->getTimestamp();
        $retryUntil = (new ProcessSyncItem(1, new TestSyncItemEvent($integration, 'item-1'), $this->logId))->retryUntil();
        $after = now()->getTimestamp();

        $this->assertGreaterThanOrEqual($before + 1800, $retryUntil->getTimestamp());
        $this->assertLessThanOrEqual($after + 1800, $retryUntil->getTimestamp());
    }

    private int $logId = 0;

    private function makeIntegration(): Integration
    {
        $integration = Integration::create([
            'provider' => 'test',
            'name' => 'Test',
            'sync_interval_minutes' => 15,
        ]);

        $log = $integration->logOperation(
            operation: 'sync',
            direction: 'inbound',
            status: 'processing',
        );
        $this->logId = $log->id;

        return $integration;
    }

    private function makeItem(Integration $integration, string $status): IntegrationSyncItem
    {
        return IntegrationSyncItem::create([
            'integration_id' => $integration->id,
            'sync_log_id' => $this->logId,
            'event_class' => TestSyncItemEvent::class,
            'checkpoint_value' => '2026-01-01T00:00:00+00:00',
            'status' => $status,
            'attempts' => 0,
        ]);
    }
}
