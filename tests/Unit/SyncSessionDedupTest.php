<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\Sync\SyncSession;
use Integrations\Tests\Fixtures\OtherTestSyncItemEvent;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\Fixtures\TestSyncItemEvent;
use Integrations\Tests\TestCase;

class SyncSessionDedupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(IntegrationManager::class)->register('test', TestProvider::class);
    }

    public function test_the_same_external_id_is_only_enumerated_once(): void
    {
        $integration = $this->makeIntegration();
        $session = new SyncSession($integration);

        $session->dispatch(new TestSyncItemEvent($integration, 'a'), 'cp-1', 'ticket-10717');
        $session->dispatch(new TestSyncItemEvent($integration, 'a'), 'cp-2', 'ticket-10717');

        $this->assertSame(1, $session->count());
        $this->assertSame(1, $session->duplicatesDropped());
    }

    public function test_the_first_copy_wins(): void
    {
        $integration = $this->makeIntegration();
        $session = new SyncSession($integration);

        $session->dispatch(new TestSyncItemEvent($integration, 'a'), 'cp-1', 'ticket-1');
        $session->dispatch(new TestSyncItemEvent($integration, 'a'), 'cp-2', 'ticket-1');

        $this->assertSame('cp-1', $session->pendingItems()[0]->checkpointValue);
    }

    public function test_distinct_external_ids_are_all_kept(): void
    {
        $integration = $this->makeIntegration();
        $session = new SyncSession($integration);

        $session->dispatch(new TestSyncItemEvent($integration, 'a'), 'cp-1', 'ticket-1');
        $session->dispatch(new TestSyncItemEvent($integration, 'b'), 'cp-2', 'ticket-2');

        $this->assertSame(2, $session->count());
        $this->assertSame(0, $session->duplicatesDropped());
    }

    public function test_a_second_event_class_for_one_record_is_kept(): void
    {
        // The event class is half the dedup key, so a provider emitting two
        // kinds of work for one record keeps both.
        $integration = $this->makeIntegration();
        $session = new SyncSession($integration);

        $session->dispatch(new TestSyncItemEvent($integration, 'a'), 'cp-1', 'ticket-1');
        $session->dispatch(new OtherTestSyncItemEvent($integration, 'a'), 'cp-1', 'ticket-1');

        $this->assertSame(2, $session->count());
        $this->assertSame(0, $session->duplicatesDropped());
    }

    public function test_items_without_an_external_id_are_never_deduplicated(): void
    {
        $integration = $this->makeIntegration();
        $session = new SyncSession($integration);

        $session->dispatch(new TestSyncItemEvent($integration, 'a'), 'cp-1');
        $session->dispatch(new TestSyncItemEvent($integration, 'b'), 'cp-2');

        $this->assertSame(2, $session->count());
        $this->assertSame(0, $session->duplicatesDropped());
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
