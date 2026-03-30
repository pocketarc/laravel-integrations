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
}
