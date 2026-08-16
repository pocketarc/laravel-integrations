<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Illuminate\Support\Facades\Event;
use Integrations\Events\SyncItemStuck;
use Integrations\IntegrationManager;
use Integrations\Jobs\FinaliseSyncRun;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationLog;
use Integrations\Models\IntegrationSyncItem;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\Fixtures\TestSyncItemEvent;
use Integrations\Tests\TestCase;

class StuckSyncItemTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(IntegrationManager::class)->register('test', TestProvider::class);
    }

    public function test_fires_once_the_same_item_has_failed_the_threshold_of_runs(): void
    {
        Event::fake([SyncItemStuck::class]);
        config(['integrations.sync.stuck_item_after_runs' => 3]);

        $integration = $this->makeIntegration();
        $this->failRuns($integration, 'ticket-10717', 3);

        Event::assertDispatched(
            SyncItemStuck::class,
            fn (SyncItemStuck $event): bool => $event->item->external_id === 'ticket-10717'
                && $event->consecutiveFailedRuns === 3,
        );
    }

    public function test_does_not_fire_below_the_threshold(): void
    {
        Event::fake([SyncItemStuck::class]);
        config(['integrations.sync.stuck_item_after_runs' => 3]);

        $integration = $this->makeIntegration();
        $this->failRuns($integration, 'ticket-10717', 2);

        Event::assertNotDispatched(SyncItemStuck::class);
    }

    public function test_a_later_success_resets_the_streak(): void
    {
        Event::fake([SyncItemStuck::class]);
        config(['integrations.sync.stuck_item_after_runs' => 3]);

        $integration = $this->makeIntegration();
        $this->failRuns($integration, 'ticket-10717', 2);

        $log = $this->openSyncLog($integration);
        $this->makeItem($integration, $log, IntegrationSyncItem::STATUS_SUCCESS, 'ticket-10717');
        (new FinaliseSyncRun($integration->id, $log->id))->handle();

        $this->failRuns($integration, 'ticket-10717', 2);

        Event::assertNotDispatched(SyncItemStuck::class);
    }

    public function test_a_later_skip_resets_the_streak(): void
    {
        // `integrations:skip-sync-item` is the documented way to clear an item
        // nobody can fix, so a skip has to end the streak like a success does.
        Event::fake([SyncItemStuck::class]);
        config(['integrations.sync.stuck_item_after_runs' => 3]);

        $integration = $this->makeIntegration();
        $this->failRuns($integration, 'ticket-10717', 2);

        $log = $this->openSyncLog($integration);
        $this->makeItem($integration, $log, IntegrationSyncItem::STATUS_SKIPPED, 'ticket-10717');
        (new FinaliseSyncRun($integration->id, $log->id))->handle();

        $this->failRuns($integration, 'ticket-10717', 2);

        Event::assertNotDispatched(SyncItemStuck::class);
    }

    public function test_items_without_an_external_id_are_skipped(): void
    {
        Event::fake([SyncItemStuck::class]);
        config(['integrations.sync.stuck_item_after_runs' => 2]);

        $integration = $this->makeIntegration();
        $this->failRuns($integration, null, 4);

        Event::assertNotDispatched(SyncItemStuck::class);
    }

    public function test_another_integrations_failures_do_not_count(): void
    {
        Event::fake([SyncItemStuck::class]);
        config(['integrations.sync.stuck_item_after_runs' => 3]);

        $other = $this->makeIntegration();
        $this->failRuns($other, 'ticket-10717', 2);

        $integration = $this->makeIntegration();
        $this->failRuns($integration, 'ticket-10717', 1);

        Event::assertNotDispatched(SyncItemStuck::class);
    }

    public function test_does_not_fire_again_when_the_run_is_finalised_twice(): void
    {
        Event::fake([SyncItemStuck::class]);
        config(['integrations.sync.stuck_item_after_runs' => 2]);

        $integration = $this->makeIntegration();
        $this->failRuns($integration, 'ticket-10717', 1);

        $log = $this->openSyncLog($integration);
        $this->makeItem($integration, $log, IntegrationSyncItem::STATUS_FAILED, 'ticket-10717');

        (new FinaliseSyncRun($integration->id, $log->id))->handle();
        (new FinaliseSyncRun($integration->id, $log->id))->handle();

        Event::assertDispatchedTimes(SyncItemStuck::class, 1);
    }

    public function test_reports_an_external_id_once_even_with_two_rows_in_one_run(): void
    {
        Event::fake([SyncItemStuck::class]);
        config(['integrations.sync.stuck_item_after_runs' => 2]);

        $integration = $this->makeIntegration();
        $this->failRuns($integration, 'ticket-10717', 1);

        $log = $this->openSyncLog($integration);
        $this->makeItem($integration, $log, IntegrationSyncItem::STATUS_FAILED, 'ticket-10717');
        $this->makeItem($integration, $log, IntegrationSyncItem::STATUS_FAILED, 'ticket-10717');

        (new FinaliseSyncRun($integration->id, $log->id))->handle();

        Event::assertDispatchedTimes(SyncItemStuck::class, 1);
    }

    private function failRuns(Integration $integration, ?string $externalId, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $log = $this->openSyncLog($integration);
            $this->makeItem($integration, $log, IntegrationSyncItem::STATUS_FAILED, $externalId);

            (new FinaliseSyncRun($integration->id, $log->id))->handle();
        }
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

    private function makeItem(
        Integration $integration,
        IntegrationLog $log,
        string $status,
        ?string $externalId,
    ): IntegrationSyncItem {
        return IntegrationSyncItem::create([
            'integration_id' => $integration->id,
            'sync_log_id' => $log->id,
            'event_class' => TestSyncItemEvent::class,
            'external_id' => $externalId,
            'checkpoint_value' => '2026-01-01T10:00:00+00:00',
            'status' => $status,
            'attempts' => 1,
        ]);
    }
}
