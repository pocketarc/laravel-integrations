<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit\Commands;

use Illuminate\Support\Facades\DB;
use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationWebhook;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;

class ReplayWebhookCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $manager = app(IntegrationManager::class);
        $manager->register('test', TestProvider::class);
    }

    public function test_replays_webhook(): void
    {
        $integration = Integration::create(['provider' => 'test', 'name' => 'Test']);

        $webhook = IntegrationWebhook::create([
            'integration_id' => $integration->id,
            'delivery_id' => 'test-delivery-1',
            'payload' => json_encode(['event' => 'created']),
            'headers' => ['content-type' => 'application/json'],
            'status' => 'processed',
        ]);

        $this->artisan("integrations:replay-webhook {$webhook->id}")
            ->assertSuccessful()
            ->expectsOutputToContain('replayed successfully');
    }

    public function test_fails_on_missing_webhook(): void
    {
        $missingId = (int) DB::table('integration_webhooks')->max('id') + 1;

        $this->artisan("integrations:replay-webhook {$missingId}")
            ->assertFailed()
            ->expectsOutputToContain('not found');
    }
}
