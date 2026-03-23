<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Illuminate\Support\Facades\Event;
use Integrations\Events\OperationCompleted;
use Integrations\Events\OperationFailed;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationLog;
use Integrations\Tests\TestCase;

class IntegrationLogTest extends TestCase
{
    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->integration = Integration::create([
            'provider' => 'test',
            'name' => 'Test',
        ]);
    }

    public function test_log_operation(): void
    {
        $log = $this->integration->logOperation(
            operation: 'sync',
            direction: 'inbound',
            status: 'success',
            summary: 'Synced 42 tickets',
            metadata: ['count' => 42],
            durationMs: 1500,
        );

        $this->assertSame('sync', $log->operation);
        $this->assertSame('inbound', $log->direction);
        $this->assertSame('success', $log->status);
        $this->assertSame('Synced 42 tickets', $log->summary);
        $this->assertSame(42, $log->metadata['count']);
        $this->assertSame(1500, $log->duration_ms);
    }

    public function test_parent_child_hierarchy(): void
    {
        $parent = $this->integration->logOperation(
            operation: 'sync',
            direction: 'inbound',
            status: 'success',
        );

        $child1 = $this->integration->logOperation(
            operation: 'import',
            direction: 'inbound',
            status: 'success',
            externalId: 'EXT-001',
            parentId: $parent->id,
        );

        $child2 = $this->integration->logOperation(
            operation: 'import',
            direction: 'inbound',
            status: 'skipped',
            externalId: 'EXT-002',
            parentId: $parent->id,
        );

        $this->assertCount(2, $parent->children);
        $this->assertSame($parent->id, $child1->parent?->id);
    }

    public function test_query_builders(): void
    {
        $this->integration->logOperation(operation: 'sync', direction: 'inbound', status: 'success');
        $this->integration->logOperation(operation: 'sync', direction: 'inbound', status: 'failed');
        $this->integration->logOperation(operation: 'push', direction: 'outbound', status: 'success');

        $this->assertCount(2, IntegrationLog::successful()->get());
        $this->assertCount(1, IntegrationLog::failed()->get());
        $this->assertCount(2, IntegrationLog::forOperation('sync')->get());
        $this->assertCount(3, IntegrationLog::topLevel()->get());
    }

    public function test_dispatches_operation_completed_event(): void
    {
        Event::fake();

        $this->integration->logOperation(operation: 'sync', direction: 'inbound', status: 'success');

        Event::assertDispatched(OperationCompleted::class);
        Event::assertNotDispatched(OperationFailed::class);
    }

    public function test_dispatches_operation_failed_event(): void
    {
        Event::fake();

        $this->integration->logOperation(operation: 'sync', direction: 'inbound', status: 'failed', error: 'Connection timeout');

        Event::assertDispatched(OperationFailed::class, function (OperationFailed $event): bool {
            return $event->integration->is($this->integration)
                && $event->log->operation === 'sync'
                && $event->log->direction === 'inbound'
                && $event->log->status === 'failed'
                && $event->log->error === 'Connection timeout';
        });
        Event::assertNotDispatched(OperationCompleted::class);
    }

    public function test_no_event_for_pending_status(): void
    {
        Event::fake();

        $this->integration->logOperation(operation: 'webhook', direction: 'inbound', status: 'pending');

        Event::assertNotDispatched(OperationCompleted::class);
        Event::assertNotDispatched(OperationFailed::class);
    }
}
