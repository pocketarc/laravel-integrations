<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Illuminate\Support\Facades\Event;
use Integrations\Events\SyncCompleted;
use Integrations\IntegrationManager;
use Integrations\Jobs\SyncIntegration;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationSyncItem;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\Fixtures\TestSyncItemEvent;
use Integrations\Tests\TestCase;
use RuntimeException;

class SyncIntegrationTest extends TestCase
{
    private TestProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        // Bind a shared instance so the test can shape syncItems and read
        // syncCalled; IntegrationManager resolves providers via the container.
        $this->provider = new TestProvider;
        $this->app->instance(TestProvider::class, $this->provider);
        app(IntegrationManager::class)->register('test', TestProvider::class);
    }

    public function test_runs_a_sync_end_to_end_and_advances_the_cursor(): void
    {
        Event::fake([SyncCompleted::class]);

        $ran = 0;
        Event::listen(TestSyncItemEvent::class, function () use (&$ran): void {
            $ran++;
        });

        $this->provider->syncItems = [
            ['id' => 'a', 'checkpoint' => '2026-01-01T10:00:00+00:00'],
            ['id' => 'b', 'checkpoint' => '2026-01-01T12:00:00+00:00'],
        ];

        $integration = $this->makeIntegration();

        (new SyncIntegration($integration->id))->handle();

        $this->assertSame(2, $ran);

        $items = IntegrationSyncItem::query()->forIntegration($integration->id)->get();
        $this->assertCount(2, $items);
        $this->assertTrue($items->every(fn (IntegrationSyncItem $i): bool => $i->status === IntegrationSyncItem::STATUS_SUCCESS));
        $this->assertNotNull($items->first()?->batch_id);

        $integration->refresh();
        $this->assertSame('2026-01-01T12:00:00+00:00', $integration->sync_cursor);
        $this->assertNotNull($integration->last_synced_at);

        $log = $integration->logs()->forOperation('sync')->first();
        $this->assertNotNull($log);
        $this->assertSame('success', $log->status);

        Event::assertDispatched(SyncCompleted::class);
    }

    public function test_an_empty_run_finalises_without_a_batch(): void
    {
        Event::fake([SyncCompleted::class]);

        $this->provider->syncItems = [];

        $integration = $this->makeIntegration();

        (new SyncIntegration($integration->id))->handle();

        $this->assertSame(0, IntegrationSyncItem::query()->forIntegration($integration->id)->count());

        $integration->refresh();
        $this->assertNotNull($integration->last_synced_at);

        $log = $integration->logs()->forOperation('sync')->first();
        $this->assertNotNull($log);
        $this->assertSame('success', $log->status);
        $this->assertSame(0, $log->metadata['success_count'] ?? null);

        Event::assertDispatched(SyncCompleted::class);
    }

    public function test_skips_the_run_when_a_previous_batch_is_still_in_flight(): void
    {
        $integration = $this->makeIntegration();

        // A leftover in-flight item from a previous run's batch.
        $priorLog = $integration->logOperation(operation: 'sync', direction: 'inbound', status: 'processing');
        IntegrationSyncItem::create([
            'integration_id' => $integration->id,
            'batch_id' => 'prior-batch',
            'sync_log_id' => $priorLog->id,
            'event_class' => TestSyncItemEvent::class,
            'checkpoint_value' => '2026-01-01T00:00:00+00:00',
            'status' => IntegrationSyncItem::STATUS_PROCESSING,
            'attempts' => 1,
        ]);

        (new SyncIntegration($integration->id))->handle();

        // The provider was never asked to enumerate, and no new run was opened.
        $this->assertFalse($this->provider->syncCalled);
        $this->assertSame(1, $integration->logs()->forOperation('sync')->count());
        $this->assertSame(1, IntegrationSyncItem::query()->forIntegration($integration->id)->count());
    }

    public function test_inactive_integration_is_skipped(): void
    {
        $integration = $this->makeIntegration();
        $integration->update(['is_active' => false]);

        (new SyncIntegration($integration->id))->handle();

        $this->assertFalse($this->provider->syncCalled);
        $this->assertSame(0, $integration->logs()->count());
    }

    public function test_a_failing_item_leaves_the_cursor_untouched(): void
    {
        Event::listen(TestSyncItemEvent::class, function (): void {
            throw new RuntimeException('listener boom');
        });

        $this->provider->syncItems = [
            ['id' => 'a', 'checkpoint' => '2026-01-01T10:00:00+00:00'],
        ];

        $integration = $this->makeIntegration();

        // A failing item must never advance the cursor. (The per-item failure
        // bookkeeping, marking the row "failed" and firing SyncItemFailed, is
        // covered by ProcessSyncItemTest; here we only assert the run did not
        // report success or move the cursor.)
        try {
            (new SyncIntegration($integration->id))->handle();
        } catch (RuntimeException $e) {
            $this->assertSame('listener boom', $e->getMessage());
        }

        $integration->refresh();
        $this->assertNull($integration->sync_cursor);

        $log = $integration->logs()->forOperation('sync')->first();
        $this->assertNotNull($log);
        $this->assertNotSame('success', $log->status);
    }

    private function makeIntegration(): Integration
    {
        return Integration::create([
            'provider' => 'test',
            'name' => 'Test',
            'sync_interval_minutes' => 15,
        ]);
    }
}
