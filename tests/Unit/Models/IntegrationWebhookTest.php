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

        $webhook->markProcessing();
        $webhook->refresh();

        $this->assertSame('processing', $webhook->status);
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
}
