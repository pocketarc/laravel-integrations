<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Illuminate\Support\Facades\Event;
use Integrations\Events\OperationCompleted;
use Integrations\Events\OperationFailed;
use Integrations\Events\OperationStarted;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationLog;
use Integrations\Sync\SyncAttemptContext;
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

    protected function tearDown(): void
    {
        Integration::setCurrentSyncAttempt(null);
        parent::tearDown();
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

    public function test_log_operation_with_result_data(): void
    {
        $log = $this->integration->logOperation(
            operation: 'issue.create',
            direction: 'outbound',
            status: 'success',
            summary: 'Created issue in GitHub',
            metadata: ['repo' => 'acme/api'],
            resultData: ['issue_number' => 42, 'url' => 'https://github.com/acme/api/issues/42'],
            durationMs: 1250,
        );

        $this->assertSame(['issue_number' => 42, 'url' => 'https://github.com/acme/api/issues/42'], $log->result_data);
        $this->assertSame(['repo' => 'acme/api'], $log->metadata);

        $fresh = $log->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(42, $fresh->result_data['issue_number']);
    }

    public function test_result_data_defaults_to_null(): void
    {
        $log = $this->integration->logOperation(
            operation: 'sync',
            direction: 'inbound',
            status: 'success',
        );

        $this->assertNull($log->result_data);
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

        Event::assertDispatched(OperationCompleted::class, function (OperationCompleted $event): bool {
            return $event->integration->is($this->integration)
                && $event->log->operation === 'sync'
                && $event->log->direction === 'inbound'
                && $event->log->status === 'success';
        });
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

    public function test_dispatches_operation_started_event(): void
    {
        Event::fake();

        $this->integration->logOperation(operation: 'issue.create', direction: 'outbound', status: 'processing');

        Event::assertDispatched(OperationStarted::class, function (OperationStarted $event): bool {
            return $event->integration->is($this->integration)
                && $event->log->operation === 'issue.create'
                && $event->log->direction === 'outbound'
                && $event->log->status === 'processing';
        });
        Event::assertNotDispatched(OperationCompleted::class);
        Event::assertNotDispatched(OperationFailed::class);
    }

    public function test_no_event_for_pending_status(): void
    {
        Event::fake();

        $this->integration->logOperation(operation: 'webhook', direction: 'inbound', status: 'pending');

        Event::assertNotDispatched(OperationCompleted::class);
        Event::assertNotDispatched(OperationFailed::class);
        Event::assertNotDispatched(OperationStarted::class);
    }

    public function test_operation_failed_carries_null_attempt_outside_a_sync(): void
    {
        Event::fake();

        $log = $this->integration->logOperation(operation: 'sync', direction: 'inbound', status: 'failed', error: 'boom');

        $this->assertNull($log->attempt);
        $this->assertNull($log->max_attempts);

        Event::assertDispatched(OperationFailed::class, function (OperationFailed $event): bool {
            return $event->attempt === null;
        });
    }

    public function test_operation_failed_carries_attempt_context_inside_a_sync(): void
    {
        Event::fake();

        $context = new SyncAttemptContext(
            attempt: 2,
            maxAttempts: 5,
            syncItemId: 10,
            integrationId: $this->integration->id,
            syncLogId: 99,
            externalId: 'EXT-7',
        );
        Integration::setCurrentSyncAttempt($context);

        $log = $this->integration->logOperation(operation: 'import', direction: 'inbound', status: 'failed', error: 'boom');

        $this->assertSame(2, $log->attempt);
        $this->assertSame(5, $log->max_attempts);

        $fresh = $log->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(2, $fresh->attempt);
        $this->assertSame(5, $fresh->max_attempts);

        Event::assertDispatched(OperationFailed::class, function (OperationFailed $event) use ($context): bool {
            return $event->attempt === $context;
        });
    }

    public function test_attempt_columns_ignored_for_non_failed_status(): void
    {
        Integration::setCurrentSyncAttempt(new SyncAttemptContext(2, 5, 10, $this->integration->id, 99, 'EXT-7'));

        $success = $this->integration->logOperation(operation: 'import', direction: 'inbound', status: 'success');
        $processing = $this->integration->logOperation(operation: 'import', direction: 'inbound', status: 'processing');

        $this->assertNull($success->attempt);
        $this->assertNull($success->max_attempts);
        $this->assertNull($processing->attempt);
        $this->assertNull($processing->max_attempts);
    }
}
