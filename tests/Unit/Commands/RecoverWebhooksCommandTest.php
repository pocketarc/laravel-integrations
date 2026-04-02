<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit\Commands;

use Illuminate\Support\Facades\Queue;
use Integrations\IntegrationManager;
use Integrations\Jobs\ProcessWebhook;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationWebhook;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;

class RecoverWebhooksCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(IntegrationManager::class)->register('test', TestProvider::class);
    }

    public function test_recovers_stale_processing_webhooks(): void
    {
        Queue::fake();

        $integration = Integration::create(['provider' => 'test', 'name' => 'Test']);

        $webhook = IntegrationWebhook::create([
            'integration_id' => $integration->id,
            'delivery_id' => 'stale-1',
            'payload' => '{}',
            'headers' => [],
            'status' => 'processing',
        ]);

        // Backdate updated_at to exceed the timeout
        $webhook->newQuery()->where('id', $webhook->id)->update([
            'updated_at' => now()->subHours(2),
        ]);

        $this->artisan('integrations:recover-webhooks')
            ->assertSuccessful()
            ->expectsOutputToContain('Recovered 1 stale webhook(s)');

        $webhook->refresh();
        $this->assertSame('pending', $webhook->status);

        Queue::assertPushed(ProcessWebhook::class, function (ProcessWebhook $job) use ($webhook): bool {
            return $job->webhookId === $webhook->id;
        });
    }

    public function test_does_not_recover_recent_processing_webhooks(): void
    {
        Queue::fake();

        $integration = Integration::create(['provider' => 'test', 'name' => 'Test']);

        IntegrationWebhook::create([
            'integration_id' => $integration->id,
            'delivery_id' => 'fresh-1',
            'payload' => '{}',
            'headers' => [],
            'status' => 'processing',
        ]);

        $this->artisan('integrations:recover-webhooks')
            ->assertSuccessful()
            ->expectsOutputToContain('No stale webhooks found');

        Queue::assertNothingPushed();
    }

    public function test_does_not_recover_non_processing_webhooks(): void
    {
        Queue::fake();

        $integration = Integration::create(['provider' => 'test', 'name' => 'Test']);

        foreach (['pending', 'processed', 'failed'] as $status) {
            $webhook = IntegrationWebhook::create([
                'integration_id' => $integration->id,
                'delivery_id' => "old-{$status}",
                'payload' => '{}',
                'headers' => [],
                'status' => $status,
            ]);

            $webhook->newQuery()->where('id', $webhook->id)->update([
                'updated_at' => now()->subHours(2),
            ]);
        }

        $this->artisan('integrations:recover-webhooks')
            ->assertSuccessful()
            ->expectsOutputToContain('No stale webhooks found');

        Queue::assertNothingPushed();
    }
}
