<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit\Jobs;

use Integrations\IntegrationManager;
use Integrations\Jobs\ProcessWebhook;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationWebhook;
use Integrations\Tests\Fixtures\PlainProvider;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;

class ProcessWebhookTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(IntegrationManager::class)->register('test', TestProvider::class);
        app(IntegrationManager::class)->register('plain', PlainProvider::class);
    }

    public function test_processes_a_pending_webhook_and_counts_the_attempt(): void
    {
        $webhook = $this->webhook('test', 'pending');

        (new ProcessWebhook($webhook->id))->handle();

        $webhook->refresh();
        $this->assertSame('processed', $webhook->status);
        $this->assertSame(1, $webhook->attempts);
    }

    public function test_counts_an_attempt_when_the_provider_does_not_handle_webhooks(): void
    {
        $webhook = $this->webhook('plain', 'pending');

        (new ProcessWebhook($webhook->id))->handle();

        $webhook->refresh();
        $this->assertSame('failed', $webhook->status);
        $this->assertSame(1, $webhook->attempts);
    }

    public function test_a_late_duplicate_job_leaves_a_processed_webhook_alone(): void
    {
        $webhook = $this->webhook('plain', 'processed');

        (new ProcessWebhook($webhook->id))->handle();

        $webhook->refresh();
        $this->assertSame('processed', $webhook->status);
        $this->assertSame(0, $webhook->attempts);
    }

    private function webhook(string $provider, string $status): IntegrationWebhook
    {
        $integration = Integration::create(['provider' => $provider, 'name' => ucfirst($provider)]);

        return IntegrationWebhook::create([
            'integration_id' => $integration->id,
            'delivery_id' => "{$provider}-{$status}",
            'payload' => '{}',
            'headers' => [],
            'status' => $status,
        ]);
    }
}
