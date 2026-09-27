<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit\Models;

use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationWebhook;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;

class IntegrationWebhookTest extends TestCase
{
    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        app(IntegrationManager::class)->register('test', TestProvider::class);
        $this->integration = Integration::create(['provider' => 'test', 'name' => 'Test']);
    }

    public function test_creates_webhook_record(): void
    {
        $webhook = IntegrationWebhook::create([
            'integration_id' => $this->integration->id,
            'delivery_id' => 'delivery-123',
            'event_type' => 'ticket.created',
            'payload' => '{"id": 1}',
            'headers' => ['content-type' => 'application/json'],
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('integration_webhooks', [
            'id' => $webhook->id,
            'delivery_id' => 'delivery-123',
            'event_type' => 'ticket.created',
            'status' => 'pending',
        ]);
    }

    public function test_mark_processing(): void
    {
        $webhook = IntegrationWebhook::create([
            'integration_id' => $this->integration->id,
            'delivery_id' => 'test-1',
            'payload' => '{}',
            'headers' => [],
            'status' => 'pending',
        ]);

        $this->assertTrue($webhook->markProcessing());
        $webhook->refresh();

        $this->assertSame('processing', $webhook->status);
    }

    public function test_mark_processing_only_from_pending(): void
    {
        foreach (['processing', 'processed', 'failed'] as $status) {
            $webhook = IntegrationWebhook::create([
                'integration_id' => $this->integration->id,
                'delivery_id' => "claim-{$status}",
                'payload' => '{}',
                'headers' => [],
                'status' => $status,
            ]);

            $this->assertFalse($webhook->markProcessing());
        }
    }

    public function test_mark_processed(): void
    {
        $webhook = IntegrationWebhook::create([
            'integration_id' => $this->integration->id,
            'delivery_id' => 'test-2',
            'payload' => '{}',
            'headers' => [],
            'status' => 'processing',
        ]);

        $webhook->markProcessed();
        $webhook->refresh();

        $this->assertSame('processed', $webhook->status);
        $this->assertNotNull($webhook->processed_at);
    }

    public function test_mark_failed(): void
    {
        $webhook = IntegrationWebhook::create([
            'integration_id' => $this->integration->id,
            'delivery_id' => 'test-3',
            'payload' => '{}',
            'headers' => [],
            'status' => 'processing',
        ]);

        $webhook->markFailed('Something went wrong');
        $webhook->refresh();

        $this->assertSame('failed', $webhook->status);
        $this->assertSame('Something went wrong', $webhook->error);
        $this->assertNotNull($webhook->processed_at);
    }

    public function test_pending_scope(): void
    {
        IntegrationWebhook::create([
            'integration_id' => $this->integration->id,
            'delivery_id' => 'pending-1',
            'payload' => '{}',
            'headers' => [],
            'status' => 'pending',
        ]);
        IntegrationWebhook::create([
            'integration_id' => $this->integration->id,
            'delivery_id' => 'processed-1',
            'payload' => '{}',
            'headers' => [],
            'status' => 'processed',
        ]);

        $this->assertSame(1, IntegrationWebhook::query()->pending()->count());
    }

    public function test_failed_scope(): void
    {
        IntegrationWebhook::create([
            'integration_id' => $this->integration->id,
            'delivery_id' => 'failed-1',
            'payload' => '{}',
            'headers' => [],
            'status' => 'failed',
            'error' => 'Error',
        ]);
        IntegrationWebhook::create([
            'integration_id' => $this->integration->id,
            'delivery_id' => 'pending-2',
            'payload' => '{}',
            'headers' => [],
            'status' => 'pending',
        ]);

        $this->assertSame(1, IntegrationWebhook::query()->failed()->count());
    }

    public function test_integration_relationship(): void
    {
        $webhook = IntegrationWebhook::create([
            'integration_id' => $this->integration->id,
            'delivery_id' => 'rel-1',
            'payload' => '{}',
            'headers' => [],
            'status' => 'pending',
        ]);

        $this->assertSame($this->integration->id, $webhook->integration->id);
    }

    public function test_integration_has_webhooks_relation(): void
    {
        IntegrationWebhook::create([
            'integration_id' => $this->integration->id,
            'delivery_id' => 'rel-2',
            'payload' => '{}',
            'headers' => [],
            'status' => 'pending',
        ]);

        $this->assertSame(1, $this->integration->webhooks()->count());
    }

    public function test_mark_processing_sets_updated_at(): void
    {
        $webhook = IntegrationWebhook::create([
            'integration_id' => $this->integration->id,
            'delivery_id' => 'ts-1',
            'payload' => '{}',
            'headers' => [],
            'status' => 'pending',
        ]);

        // Backdate updated_at.
        $webhook->newQuery()->where('id', $webhook->id)->update([
            'updated_at' => now()->subHour(),
        ]);
        $webhook->refresh();
        $before = $webhook->updated_at;

        $webhook->markProcessing();
        $webhook->refresh();

        $this->assertSame('processing', $webhook->status);
        $this->assertTrue($webhook->updated_at->greaterThan($before));
    }

    public function test_reset_to_pending(): void
    {
        $webhook = IntegrationWebhook::create([
            'integration_id' => $this->integration->id,
            'delivery_id' => 'reset-1',
            'payload' => '{}',
            'headers' => [],
            'status' => 'processing',
        ]);

        $result = $webhook->resetToPending();
        $webhook->refresh();

        $this->assertTrue($result);
        $this->assertSame('pending', $webhook->status);
        $this->assertNull($webhook->error);
        $this->assertNull($webhook->processed_at);
    }

    public function test_reset_to_pending_only_from_processing(): void
    {
        foreach (['pending', 'processed', 'failed'] as $status) {
            $webhook = IntegrationWebhook::create([
                'integration_id' => $this->integration->id,
                'delivery_id' => "reset-{$status}",
                'payload' => '{}',
                'headers' => [],
                'status' => $status,
            ]);

            $this->assertFalse($webhook->resetToPending());
        }
    }

    public function test_mark_processing_counts_the_attempt(): void
    {
        $webhook = $this->webhook('attempt-1', 'pending');

        $webhook->markProcessing();

        $this->assertSame(1, $webhook->attempts);
        $this->assertSame(1, $webhook->refresh()->attempts);
    }

    public function test_a_claim_that_loses_counts_no_attempt(): void
    {
        $webhook = $this->webhook('attempt-2', 'processed');

        $this->assertFalse($webhook->markProcessing());
        $this->assertSame(0, $webhook->refresh()->attempts);
    }

    public function test_mark_processing_refuses_a_copy_loaded_before_another_attempt(): void
    {
        $webhook = $this->webhook('claim-stale', 'pending');
        $stale = IntegrationWebhook::query()->findOrFail($webhook->id);
        $webhook->newQuery()->where('id', $webhook->id)->update(['attempts' => 1]);

        $this->assertFalse($stale->markProcessing());
        $this->assertSame(1, $stale->refresh()->attempts);
    }

    public function test_a_later_save_does_not_write_back_the_claimed_attempt_count(): void
    {
        $webhook = $this->webhook('claim-save', 'pending');
        $webhook->markProcessing();
        $webhook->newQuery()->where('id', $webhook->id)->update(['attempts' => 5]);

        $webhook->save();

        $this->assertSame(5, $webhook->refresh()->attempts);
    }

    public function test_a_job_that_lost_its_claim_leaves_the_newer_attempt_alone(): void
    {
        $first = $this->webhook('claim-lost', 'pending');
        $first->markProcessing();
        IntegrationWebhook::query()->findOrFail($first->id)->resetToPending();
        $this->assertTrue(IntegrationWebhook::query()->findOrFail($first->id)->markProcessing());

        $first->markFailed('Too slow.');
        $first->markProcessed();

        $first->refresh();
        $this->assertSame('processing', $first->status);
        $this->assertSame(2, $first->attempts);
        $this->assertNull($first->error);
    }

    public function test_mark_failed_still_updates_a_webhook_this_copy_did_not_claim(): void
    {
        $webhook = $this->webhook('unclaimed', 'pending');

        $webhook->markFailed('Rejected.');

        $this->assertSame('failed', $webhook->refresh()->status);
    }

    public function test_retry_moves_a_failed_webhook_back_to_pending(): void
    {
        $webhook = $this->webhook('retry-1', 'failed', attempts: 1);

        $this->assertTrue($webhook->retry());
        $webhook->refresh();

        $this->assertSame('pending', $webhook->status);
        $this->assertNull($webhook->error);
        $this->assertNull($webhook->processed_at);
        $this->assertSame(1, $webhook->attempts);
    }

    public function test_retry_only_from_failed(): void
    {
        foreach (['pending', 'processing', 'processed'] as $status) {
            $this->assertFalse($this->webhook("retry-{$status}", $status)->retry());
        }
    }

    public function test_retry_refuses_a_copy_read_before_another_attempt_failed(): void
    {
        $webhook = $this->webhook('retry-stale', 'failed', attempts: 1);
        $stale = IntegrationWebhook::query()->findOrFail($webhook->id);
        $webhook->newQuery()->where('id', $webhook->id)->update(['attempts' => 2]);

        $this->assertFalse($stale->retry());
        $this->assertSame('failed', $stale->refresh()->status);
    }

    public function test_reclaim_pending_touches_a_pending_webhook_older_than_the_cutoff(): void
    {
        $webhook = $this->webhook('reclaim-1', 'pending');
        $webhook->newQuery()->where('id', $webhook->id)->update(['updated_at' => now()->subHours(2)]);

        $this->assertTrue($webhook->reclaimPending(now()->subHour()));
        $this->assertTrue($webhook->refresh()->updated_at->greaterThan(now()->subMinute()));
    }

    public function test_reclaim_pending_claims_a_webhook_once(): void
    {
        $webhook = $this->webhook('reclaim-2', 'pending');
        $webhook->newQuery()->where('id', $webhook->id)->update(['updated_at' => now()->subHours(2)]);
        $sibling = IntegrationWebhook::query()->findOrFail($webhook->id);

        $this->assertTrue($webhook->reclaimPending(now()->subHour()));
        $this->assertFalse($sibling->reclaimPending(now()->subHour()));
    }

    public function test_reclaim_pending_leaves_other_webhooks_alone(): void
    {
        foreach (['processing', 'processed', 'failed'] as $status) {
            $webhook = $this->webhook("reclaim-{$status}", $status);
            $webhook->newQuery()->where('id', $webhook->id)->update(['updated_at' => now()->subHours(2)]);

            $this->assertFalse($webhook->reclaimPending(now()->subHour()));
        }
    }

    public function test_stale_pending_scope(): void
    {
        $stale = $this->webhook('stale-pending-1', 'pending');
        $stale->newQuery()->where('id', $stale->id)->update(['updated_at' => now()->subHours(2)]);
        $this->webhook('fresh-pending-1', 'pending');

        $results = IntegrationWebhook::query()->stalePending(3600)->get();

        $this->assertCount(1, $results);
        $this->assertSame($stale->id, $results->first()?->id);
    }

    public function test_retryable_scope_counts_only_claimed_attempts_below_the_limit(): void
    {
        $this->webhook('never-claimed', 'failed', attempts: 0);
        $retryable = $this->webhook('once', 'failed', attempts: 1);
        $this->webhook('exhausted', 'failed', attempts: 3);
        $this->webhook('pending', 'pending', attempts: 1);

        $results = IntegrationWebhook::query()->retryable(3)->get();

        $this->assertCount(1, $results);
        $this->assertSame($retryable->id, $results->first()?->id);
    }

    public function test_stale_processing_scope(): void
    {
        $stale = IntegrationWebhook::create([
            'integration_id' => $this->integration->id,
            'delivery_id' => 'stale-1',
            'payload' => '{}',
            'headers' => [],
            'status' => 'processing',
        ]);

        $stale->newQuery()->where('id', $stale->id)->update([
            'updated_at' => now()->subHours(2),
        ]);

        IntegrationWebhook::create([
            'integration_id' => $this->integration->id,
            'delivery_id' => 'fresh-1',
            'payload' => '{}',
            'headers' => [],
            'status' => 'processing',
        ]);

        $results = IntegrationWebhook::query()->staleProcessing(1800)->get();

        $this->assertCount(1, $results);
        $this->assertSame($stale->id, $results->first()->id);
    }

    private function webhook(string $deliveryId, string $status, int $attempts = 0): IntegrationWebhook
    {
        return IntegrationWebhook::create([
            'integration_id' => $this->integration->id,
            'delivery_id' => $deliveryId,
            'payload' => '{}',
            'headers' => [],
            'status' => $status,
            'attempts' => $attempts,
        ]);
    }
}
